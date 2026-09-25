<?php
declare(strict_types=1);

namespace ControlBot\Decisions;

use ControlBot\Approvals\AppendOnlyAuditLog;
use ControlBot\Approvals\ApprovalEndpoint;
use ControlBot\GitHub\ApiClient;
use ControlBot\GitHub\ApiTransport;
use ControlBot\GitHub\Gateway;
use ControlBot\GitHub\GateSource;
use ControlBot\GitHub\HumanGateSource;
use ControlBot\Security\OwnerSessionService;
use InvalidArgumentException;
use RuntimeException;

final class DecisionRuntime
{
    private const TRACKING_KEY = '_controlbot_release_tracking';
    private array $repositories;

    public function __construct(
        private readonly OwnerSessionService $sessions,
        private readonly ApprovalEndpoint $approvals,
        private readonly \Closure $githubFactory,
        array $repositories,
    ) {
        if ($repositories === [] || count($repositories) > 20) {
            throw new InvalidArgumentException('Allowlist de repositorios inválida.');
        }
        $normalized = [];
        foreach ($repositories as $repository) {
            if (!is_string($repository)) {
                throw new InvalidArgumentException('Allowlist de repositorios inválida.');
            }
            Gateway::repoPath($repository);
            $normalized[$repository] = true;
        }
        $this->repositories = array_keys($normalized);
    }

    public static function fromServer(
        OwnerSessionService $sessions,
        AppendOnlyAuditLog $audit,
        array $repositories,
    ): self {
        $factory = static function (string $token): array {
            $api = new ApiClient($token, new ApiTransport());
            return [
                'api' => $api,
                'gateway' => new Gateway($api),
                'source' => new GateSource($api),
            ];
        };

        return new self(
            $sessions,
            new ApprovalEndpoint($sessions, $audit, $factory),
            $factory,
            $repositories,
        );
    }

    public function handle(
        string $method,
        string $path,
        array &$session,
        array $request,
        int $now,
    ): array {
        $method = strtoupper($method);
        if ($method === 'GET' && $path === '/decisions') {
            return self::response(200, 'text/html; charset=utf-8', $this->render($session, $now));
        }
        if ($method === 'POST' && $path === '/approvals/execute') {
            return self::jsonResponse(200, $this->approve($session, $request, $now));
        }
        if ($method === 'GET' && $path === '/release/status') {
            return self::jsonResponse(200, $this->releaseStatus($session));
        }
        return self::jsonResponse(404, ['error' => 'not-found']);
    }

    private function render(array $session, int $now): string
    {
        $components = $this->components($this->sessions->githubToken($session));
        $inbox = new GateInbox($components['api'], $components['gateway']);
        $csrf = $this->sessions->csrfToken($session);
        $reauthenticated = false;
        try {
            $this->sessions->contextFromRequest($session, ['_csrf' => $csrf], $now);
            $reauthenticated = true;
        } catch (RuntimeException) {
            $reauthenticated = false;
        }
        return DecisionUi::render($inbox->load($this->repositories), $reauthenticated, $csrf);
    }

    private function approve(array &$session, array $request, int $now): array
    {
        $repository = $request['repository'] ?? null;
        if (!is_string($repository) || !in_array($repository, $this->repositories, true)) {
            throw new InvalidArgumentException('Repositorio fuera de la allowlist runtime.');
        }

        $result = $this->approvals->execute($session, $request, $now);
        if (
            ($result['category'] ?? null) === 'factory-release'
            && ($result['option'] ?? null) === 'A'
            && is_string($result['sha'] ?? null)
        ) {
            $session[self::TRACKING_KEY] = [
                'repository' => $repository,
                'workflow' => 'release-bootstrap.yml',
                'sha' => $result['sha'],
                'dispatched_at' => $now,
            ];
            $result['release'] = $this->safeReleaseStatus($session);
        }
        return $result;
    }

    private function releaseStatus(array &$session): array
    {
        $tracking = $session[self::TRACKING_KEY] ?? null;
        if ($tracking === null) {
            return ['state' => 'idle', 'terminal' => true, 'run_url' => null];
        }
        if (
            !is_array($tracking)
            || !is_string($tracking['repository'] ?? null)
            || !in_array($tracking['repository'], $this->repositories, true)
            || !is_string($tracking['workflow'] ?? null)
            || !is_string($tracking['sha'] ?? null)
            || !is_int($tracking['dispatched_at'] ?? null)
        ) {
            throw new RuntimeException('Correlación server-side inválida.');
        }

        $components = $this->components($this->sessions->githubToken($session));
        $status = (new ReleaseRunTracker($components['api']))->status(
            $tracking['repository'],
            $tracking['workflow'],
            $tracking['sha'],
            $tracking['dispatched_at'],
        );
        if (($status['terminal'] ?? false) === true) {
            unset($session[self::TRACKING_KEY]);
        }
        return $status;
    }

    private function safeReleaseStatus(array &$session): array
    {
        try {
            return $this->releaseStatus($session);
        } catch (RuntimeException) {
            return ['state' => 'blocked', 'terminal' => false, 'run_url' => null];
        }
    }

    private function components(string $token): array
    {
        $components = ($this->githubFactory)($token);
        if (
            !is_array($components)
            || !($components['api'] ?? null) instanceof ApiClient
            || !($components['gateway'] ?? null) instanceof Gateway
            || !($components['source'] ?? null) instanceof HumanGateSource
        ) {
            throw new RuntimeException('Integración GitHub runtime inválida.');
        }
        return $components;
    }

    private static function response(int $status, string $contentType, string $body): array
    {
        return ['status' => $status, 'content_type' => $contentType, 'body' => $body];
    }

    private static function jsonResponse(int $status, array $payload): array
    {
        return self::response(
            $status,
            'application/json; charset=utf-8',
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }
}
