<?php
declare(strict_types=1);
namespace ControlBot\GitHub;
use Closure;
use Throwable;
/** Dormant GET-only owner pilot; never activate or wire without a separate OWNER grant. */
final class GitHubOwnerScopedGetPilot
{
    private const HOST = 'api.github.com';
    private const MAX_PAGES = 3;
    private const MAX_PAGE_BYTES = 32768;
    private const MAX_TOTAL_BYTES = 65536;
    private array $events = [];
    public function __construct(
        private readonly Closure $trustedSource,
        private readonly Closure $tokenProvider,
        private readonly ?Closure $sender = null,
        private readonly bool $enabled = false,
    ) {
    }
    public function read(array $request, int $now): array
    {
        if (!$this->enabled) {
            return $this->result('disabled');
        }
        if (!$this->validRequest($request, $now)) {
            return $this->result('invalid_request');
        }
        try {
            $witness = ($this->trustedSource)(
                $request['repository'], $request['resource'], $now
            );
        } catch (Throwable) {
            return $this->result('source_unavailable');
        }
        if (!$this->validWitness($witness, $request, $now)) {
            return $this->result('authority_unverified');
        }
        try {
            $token = ($this->tokenProvider)($witness['installation_id']);
            if (!is_string($token) || strlen($token) < 12 || strlen($token) > 4096
                || preg_match('/[\x00-\x20\x7f]/', $token) === 1) {
                return $this->result('token_unavailable');
            }
        } catch (Throwable) {
            return $this->result('token_unavailable');
        }
        $sender = $this->sender ?? Closure::fromCallable([self::class, 'nativeGet']);
        $items = [];
        $seen = [];
        $totalBytes = 0;
        $completed = false;
        try {
            for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
                $url = 'https://' . self::HOST . '/repos/'
                    . $request['repository'] . '/' . $request['resource']
                    . '?per_page=' . $request['page_size'] . '&page=' . $page;
                $guards = [
                    'method' => 'GET',
                    'tls_verify_peer' => true,
                    'tls_verify_host' => 2,
                    'follow_redirects' => false,
                    'connect_timeout_seconds' => 2,
                    'timeout_seconds' => 5,
                    'max_response_bytes' => self::MAX_PAGE_BYTES,
                ];
                $reply = $sender($url, $token, $guards);
                if (!is_array($reply) || !$this->exactKeys($reply,
                    ['status', 'content_type', 'redirected', 'tls_verified', 'body']) || ($reply['status'] ?? null) !== 200
                    || ($reply['tls_verified'] ?? null) !== true || ($reply['redirected'] ?? null) !== false
                    || !is_string($reply['content_type'])
                    || !in_array(strtolower(trim(explode(';', $reply['content_type'])[0])),
                        ['application/json', 'application/vnd.github+json'], true) || !is_string($reply['body'])) {
                    return $this->result('response_invalid');
                }
                $bytes = strlen($reply['body']);
                $totalBytes += $bytes;
                if ($bytes < 2 || $bytes > self::MAX_PAGE_BYTES || $totalBytes > self::MAX_TOTAL_BYTES) {
                    return $this->result('size_limit');
                }
                $decoded = json_decode($reply['body'], true, 16, JSON_THROW_ON_ERROR);
                if (!is_array($decoded) || !array_is_list($decoded) || count($decoded) > $request['page_size']) {
                    return $this->result('response_invalid');
                }
                foreach ($decoded as $entry) {
                    if (!is_array($entry) || !is_int($entry['number'] ?? null) || $entry['number'] < 1
                        || !in_array($entry['state'] ?? null, ['open', 'closed'], true) || isset($seen[$entry['number']])) {
                        return $this->result('response_invalid');
                    }
                    $seen[$entry['number']] = true;
                    $items[] = ['number' => $entry['number'], 'state' => $entry['state']];
                }
                if (count($decoded) < $request['page_size']) {
                    $completed = true;
                    break;
                }
            }
        } catch (Throwable) {
            return $this->result('transport_unknown');
        } finally {
            unset($token);
        }
        if (!$completed) {
            return $this->result('page_limit');
        }
        $this->record('read_completed');
        return [
            'status' => $this->sender === null ? 'SOURCE_READ' : 'OFFLINE_VALIDATED',
            'reason' => $this->sender === null ? 'unwired_server_read' : 'fake_sender_only',
            'projection' => [
                'repository' => $request['repository'],
                'resource' => $request['resource'],
                'items' => $items,
                'observed_at' => $now,
                'expires_at' => min($witness['expires_at'], $witness['grant']['expires_at']),
            ],
            'runtime_authorized' => false,
            'production_verified' => false,
            'execute_actions' => false,
            'authority' => 'unchanged',
        ];
    }
    private function validRequest(array $r, int $now): bool
    {
        return $now > 0 && $this->exactKeys($r, ['repository', 'resource', 'page_size']) && is_string($r['repository'])
            && preg_match('~^[A-Za-z0-9][A-Za-z0-9._-]{0,38}/[A-Za-z0-9][A-Za-z0-9._-]{0,99}$~D',
                $r['repository']) === 1 && !str_contains($r['repository'], '..')
            && in_array($r['resource'], ['issues', 'pulls'], true)
            && is_int($r['page_size']) && $r['page_size'] >= 1 && $r['page_size'] <= 20;
    }
    private function validWitness(mixed $w, array $r, int $now): bool
    {
        if (!is_array($w) || !$this->exactKeys($w, [
                'origin', 'installation_id', 'owner', 'repository', 'permissions',
                'grant', 'observed_at', 'expires_at', 'verified', 'revoked',
            ]) || $w['origin'] !== 'trusted_installation_source'
            || !is_int($w['installation_id']) || $w['installation_id'] < 1
            || $w['verified'] !== true || $w['revoked'] !== false || $w['owner'] !== explode('/', $r['repository'], 2)[0]
            || $w['repository'] !== $r['repository'] || !is_array($w['permissions'])
            || !$this->exactKeys($w['permissions'], [
                'metadata', $r['resource'] === 'issues' ? 'issues' : 'pull_requests',
            ]) || ($w['permissions']['metadata'] ?? null) !== 'read'
            || ($w['permissions'][$r['resource'] === 'issues' ? 'issues' : 'pull_requests'] ?? null) !== 'read'
            || !is_int($w['observed_at']) || !is_int($w['expires_at'])
            || $w['observed_at'] > $now || $now >= $w['expires_at'] || $now - $w['observed_at'] > 300
            || $w['expires_at'] <= $w['observed_at'] || $w['expires_at'] - $w['observed_at'] > 300) {
            return false;
        }
        $g = $w['grant'];
        return is_array($g) && $this->exactKeys($g, [
                'origin', 'installation_id', 'owner', 'repository', 'resource',
                'approved', 'revoked', 'issued_at', 'expires_at',
            ]) && $g['origin'] === 'owner_approved_grant' && $g['installation_id'] === $w['installation_id']
            && $g['owner'] === $w['owner'] && $g['repository'] === $w['repository'] && $g['resource'] === $r['resource']
            && $g['approved'] === true && $g['revoked'] === false && is_int($g['issued_at']) && is_int($g['expires_at'])
            && $g['issued_at'] <= $now && $now < $g['expires_at'] && $g['issued_at'] >= $w['observed_at']
            && $g['expires_at'] <= $w['expires_at'] && $g['expires_at'] - $g['issued_at'] <= 300;
    }
    private function exactKeys(array $a, array $keys): bool
    {
        $actual = array_keys($a);
        sort($actual);
        sort($keys);
        return $actual === $keys;
    }
    private function result(string $reason): array
    {
        $this->record($reason);
        return [
            'status' => 'UNKNOWN',
            'reason' => $reason,
            'projection' => null,
            'runtime_authorized' => false,
            'production_verified' => false,
            'execute_actions' => false,
            'authority' => 'unchanged',
        ];
    }
    private function record(string $reason): void
    {
        if (count($this->events) < 16) {
            $this->events[] = ['status' => 'UNKNOWN', 'reason' => $reason];
        }
    }
    public function audit(): array
    {
        return $this->events;
    }
    private static function nativeGet(string $url, string $token, array $guards): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('curl_unavailable');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('curl_unavailable');
        }
        $content = '';
        try {
            $protocols = defined('CURLOPT_PROTOCOLS_STR')
                ? [CURLOPT_PROTOCOLS_STR => 'https'] : [CURLOPT_PROTOCOLS => CURLPROTO_HTTPS];
            curl_setopt_array($handle, $protocols + [
                CURLOPT_HTTPGET => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_USERAGENT => 'ControlBotOwnerGetPilot/1.0',
                CURLOPT_HTTPHEADER => [
                    'Accept: application/vnd.github+json',
                    'Authorization: Bearer ' . $token,
                    'X-GitHub-Api-Version: 2022-11-28',
                ],
                CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$content): int {
                    if (strlen($content) + strlen($chunk) > self::MAX_PAGE_BYTES) {
                        return 0;
                    }
                    $content .= $chunk;
                    return strlen($chunk);
                },
            ]);
            if (curl_exec($handle) === false) {
                throw new \RuntimeException('transport_unknown');
            }
            return [
                'status' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
                'content_type' => (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE),
                'redirected' => (int) curl_getinfo($handle, CURLINFO_REDIRECT_COUNT) !== 0,
                'tls_verified' => (int) curl_getinfo($handle, CURLINFO_SSL_VERIFYRESULT) === 0,
                'body' => $content,
            ];
        } finally {
            curl_close($handle);
        }
    }
}
