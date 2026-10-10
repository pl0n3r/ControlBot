<?php
declare(strict_types=1);
namespace ControlBot\GitHub;

use Closure;
use InvalidArgumentException;

/** Offline-only GitHub HTTPS transport boundary. The injected sender is a fake. */
final class GitHubHttpTransport
{
    private const ROUTES = [
        'issue.create'      => ['POST', '/issues'],
        'issue.update'      => ['PATCH', '/issues/[1-9][0-9]*'],
        'issue.close'       => ['PATCH', '/issues/[1-9][0-9]*'],
        'issue.reserve'     => ['POST', '/issues/[1-9][0-9]*/comments'],
        'issue.release'     => ['POST', '/issues/[1-9][0-9]*/comments'],
        'pr.review'         => ['POST', '/pulls/[1-9][0-9]*/reviews'],
        'pr.merge'          => ['PUT', '/pulls/[1-9][0-9]*/merge'],
        'workflow.dispatch'=> ['POST', '/actions/workflows/[A-Za-z0-9_.-]+/dispatches'],
    ];
    private array $previous = [];

    /**
     * Sender signature: fn(string $method, string $url, ?string $json,
     *                       string $token, array $guards): array.
     * The sender MUST be a local fake/stub. This class has no network adapter.
     */
    public function __construct(
        private readonly Closure $sender,
        private readonly Closure $secretProvider,
        private readonly int $maxResponseBytes = 65536,
    ) {
        if ($maxResponseBytes < 1 || $maxResponseBytes > 1048576) {
            throw new InvalidArgumentException('invalid_response_limit');
        }
    }

    /** @return array<string, mixed> safe execution receipt (never response body/token) */
    public function dispatch(array $request): array
    {
        $started = gmdate('Y-m-d\TH:i:s\Z');
        $safe = self::metadata($request);
        $digest = null;
        $reason = 'invalid_request';
        try {
            $json = $this->preflight($request);
            $digest = hash('sha256', $request['method'] . "\n" . $request['url'] . "\n" . $json);
            $key = $request['idempotency_key'];
            if (isset($this->previous[$key])) {
                $cached = $this->previous[$key];
                if ($cached['request_digest'] === $digest) {
                    return $cached;
                }
                return self::receipt($safe, 'rejected', $digest, 'idempotency_conflict', $started);
            }

            try {
                $token = ($this->secretProvider)();
                if (!is_string($token) || $token === '' || strlen($token) > 4096) {
                    throw new InvalidArgumentException('invalid_token');
                }
            } catch (\Throwable) {
                return $this->remember($key, self::receipt($safe, 'failed', $digest, 'secret_provider_failed', $started));
            }

            $guards = [
                'verify_tls_peer' => true,
                'verify_tls_host' => true,
                'follow_redirects' => false,
                'connect_timeout_seconds' => 2,
                'timeout_seconds' => 5,
                'max_response_bytes' => $this->maxResponseBytes,
            ];
            try {
                $response = ($this->sender)($request['method'], $request['url'], $json, $token, $guards);
            } catch (\Throwable) {
                return $this->remember($key, self::receipt($safe, 'ambiguous', $digest, 'transport_uncertain', $started));
            } finally {
                unset($token);
            }
            if (!is_array($response) || !array_key_exists('tls_verified', $response)
                || $response['tls_verified'] !== true) {
                return $this->remember($key, self::receipt($safe, 'failed', $digest, 'tls_not_verified', $started));
            }
            if (!array_key_exists('redirect_count', $response)
                || !is_int($response['redirect_count']) || $response['redirect_count'] !== 0) {
                return $this->remember($key, self::receipt($safe, 'rejected', $digest, 'redirect_blocked', $started));
            }
            if (!isset($response['status_code'], $response['body'])
                || !is_int($response['status_code']) || !is_string($response['body'])) {
                return $this->remember($key, self::receipt($safe, 'ambiguous', $digest, 'invalid_transport_response', $started));
            }
            if (strlen($response['body']) > $this->maxResponseBytes) {
                return $this->remember($key, self::receipt($safe, 'failed', $digest, 'response_limit_exceeded', $started));
            }
            $status = $response['status_code'];
            if ($status >= 300 && $status <= 399) {
                return $this->remember($key, self::receipt($safe, 'rejected', $digest, 'redirect_blocked', $started));
            }
            if ($status >= 200 && $status <= 299) {
                $evidence = 'stub:sha256:' . hash('sha256', $response['body']);
                return $this->remember($key, self::receipt($safe, 'executed', $digest, null, $started, $evidence));
            }
            $code = ($status >= 400 && $status < 500) ? 'http_rejected' : 'http_uncertain';
            $state = ($status >= 400 && $status < 500) ? 'failed' : 'ambiguous';
            return $this->remember($key, self::receipt($safe, $state, $digest, $code, $started));
        } catch (\Throwable) {
            return self::receipt($safe, 'rejected', $digest, $reason, $started);
        }
    }

    private function preflight(array $r): string
    {
        $required = ['intent_id', 'project_id', 'repository_id', 'type', 'idempotency_key', 'method', 'url', 'body'];
        $keys = array_keys($r); sort($keys); sort($required);
        if ($keys !== $required) throw new InvalidArgumentException('fields');
        foreach (['intent_id', 'project_id', 'repository_id', 'type', 'idempotency_key', 'method', 'url'] as $field) {
            if (!is_string($r[$field]) || $r[$field] === '' || strlen($r[$field]) > 512) {
                throw new InvalidArgumentException('fields');
            }
        }
        if (!preg_match('/^[A-Za-z0-9:._-]{8,120}$/D', $r['idempotency_key'])
            || preg_match('/(?:token|secret|bearer|github_pat|ghp_)/i', $r['idempotency_key'])) {
            throw new InvalidArgumentException('idempotency');
        }
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $r['intent_id'])
            || !preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $r['project_id'])) {
            throw new InvalidArgumentException('identity');
        }
        if (!preg_match('~^([A-Za-z0-9-]+)/([A-Za-z0-9_.-]+)$~D', $r['repository_id'], $repo)) {
            throw new InvalidArgumentException('repository');
        }
        if (!isset(self::ROUTES[$r['type']]) || $r['method'] !== self::ROUTES[$r['type']][0]) {
            throw new InvalidArgumentException('method');
        }
        // No userinfo, ports, queries, fragments, IP literals, encoded slashes or alternate hosts.
        $prefix = 'https://api.github.com/repos/' . $repo[1] . '/' . $repo[2];
        $pattern = '~^' . preg_quote($prefix, '~') . self::ROUTES[$r['type']][1] . '$~D';
        if (preg_match($pattern, $r['url']) !== 1) {
            throw new InvalidArgumentException('url');
        }
        if ($r['body'] !== null && !is_array($r['body'])) {
            throw new InvalidArgumentException('body');
        }
        $json = json_encode($r['body'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > 8192) throw new InvalidArgumentException('body_limit');
        return $json;
    }

    private function remember(string $key, array $receipt): array
    {
        $this->previous[$key] = $receipt;
        return $receipt;
    }

    private static function metadata(array $request): array
    {
        $out = [];
        foreach (['intent_id', 'project_id', 'repository_id', 'type', 'idempotency_key'] as $field) {
            $value = $request[$field] ?? null;
            $out[$field] = is_string($value)
                && strlen($value) <= 128
                && preg_match('/^[A-Za-z0-9_.:\/-]{1,128}$/D', $value)
                && !preg_match('/(?:token|secret|bearer|github_pat|ghp_)/i', $value)
                ? $value : null;
        }
        return $out;
    }

    private static function receipt(
        array $safe, string $state, ?string $digest, ?string $code, string $started, ?string $evidence = null,
    ): array {
        $result = $safe + [
            'status' => $state,
            'request_digest' => $digest,
            'evidence_ref' => $evidence,
            'started_at' => $started,
            'finished_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        if ($code !== null) $result['error_code'] = $code;
        return $result;
    }
}
