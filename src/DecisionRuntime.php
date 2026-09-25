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
        private readonly AppendOnlyAuditLog $audit,
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
            $audit,
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
        if ($method === 'GET' && $path === '/decisions/history') {
            $this->sessions->githubToken($session);
            $repository = self::optionalFilter($request, 'repository');
            $category = self::optionalFilter($request, 'category');
            $history = (new DecisionHistory($this->audit, $this->repositories))->load($repository, $category, $now);
            return self::jsonResponse(200, ['history' => $history]);
        }
        if ($method === 'POST' && $path === '/approvals/execute') {
            return self::jsonResponse(200, $this->approve($session, $request, $now));
        }
        if ($method === 'POST' && $path === '/approvals/batch') {
            return self::jsonResponse(200, $this->approveBatch($session, $request, $now));
        }
        if ($method === 'POST' && $path === '/decisions/snooze') {
            return self::jsonResponse(200, $this->snooze($session, $request, $now));
        }
        if ($method === 'GET' && $path === '/release/status') {
            return self::jsonResponse(200, $this->safeReleaseStatus($session));
        }
        return self::jsonResponse(404, ['error' => 'not-found']);
    }

    private function render(array $session, int $now): string
    {
        $decisions = (new DecisionSnooze($this->audit))->visible($this->loadDecisions($session), $now);
        $csrf = $this->sessions->csrfToken($session);
        $reauthenticated = false;
        try {
            $this->sessions->contextFromRequest($session, ['_csrf' => $csrf], $now);
            $reauthenticated = true;
        } catch (RuntimeException) {
            $reauthenticated = false;
        }
        return DecisionUi::render(
            $decisions,
            $reauthenticated,
            $csrf,
            DecisionBatch::eligible($decisions),
        );
    }

    private function loadDecisions(array $session): array
    {
        $components = $this->components($this->sessions->githubToken($session));
        return (new GateInbox($components['api'], $components['gateway']))->load($this->repositories);
    }

    private function snooze(array &$session, array $request, int $now): array
    {
        if (
            array_diff(array_keys($request), ['_csrf', 'repository', 'issue', 'duration']) !== []
            || !is_string($request['_csrf'] ?? null)
            || !is_string($request['repository'] ?? null)
            || (!is_int($request['issue'] ?? null)
                && !(is_string($request['issue'] ?? null) && ctype_digit($request['issue'])))
            || !is_string($request['duration'] ?? null)
        ) {
            throw new InvalidArgumentException('Solicitud de recordatorio inválida.');
        }

        $repository = $request['repository'];
        $issue = (int) $request['issue'];
        if (!in_array($repository, $this->repositories, true) || $issue < 1) {
            throw new InvalidArgumentException('Decisión fuera de allowlist runtime.');
        }

        $owner = $this->sessions->contextFromRequest($session, ['_csrf' => $request['_csrf']], $now);
        $decisions = (new DecisionSnooze($this->audit))->visible($this->loadDecisions($session), $now);

        return (new DecisionSnooze($this->audit))->snooze(
            $decisions,
            $repository,
            $issue,
            $request['duration'],
            $owner,
            $now,
        );
    }

    private function approveBatch(array &$session, array $request, int $now): array
    {
        if (
            array_diff(array_keys($request), ['_csrf']) !== []
            || !is_string($request['_csrf'] ?? null)
        ) {
            throw new InvalidArgumentException('Solicitud de lote inválida.');
        }

        $this->sessions->contextFromRequest($session, ['_csrf' => $request['_csrf']], $now);
        $decisions = (new DecisionSnooze($this->audit))->visible($this->loadDecisions($session), $now);

        return DecisionBatch::execute(
            $decisions,
            function (array $entry) use (&$session, $request, $now): array {
                return $this->approve($session, [
                    '_csrf' => $request['_csrf'],
                    'repository' => $entry['repository'],
                    'issue' => (string) $entry['issue'],
                    'option' => $entry['option'],
                    'displayed_sha' => $entry['displayed_sha'],
                ], $now);
            },
        );
    }

    private function approve(array &$session, array $request, int $now): array
    {
        $repository = $request['repository'] ?? null;
        $issue = $request['issue'] ?? null;
        if (!is_string($repository) || !in_array($repository, $this->repositories, true)) {
            throw new InvalidArgumentException('Repositorio fuera de la allowlist runtime.');
        }
        if (
            (!is_int($issue) && !(is_string($issue) && ctype_digit($issue)))
            || (int) $issue < 1
        ) {
            throw new InvalidArgumentException('Solicitud de aprobación inválida.');
        }

        $issueNumber = (int) $issue;
        $visible = (new DecisionSnooze($this->audit))->visible($this->loadDecisions($session), $now);
        $matches = array_filter(
            $visible,
            static fn (mixed $decision): bool => is_array($decision)
                && ($decision['repository'] ?? null) === $repository
                && ($decision['issue'] ?? null) === $issueNumber,
        );
        if (count($matches) !== 1) {
            throw new RuntimeException('La decisión no está disponible para aprobar.');
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

    private static function optionalFilter(array $request, string $key): ?string
    {
        if (!array_key_exists($key, $request) || $request[$key] === '') {
            return null;
        }
        if (!is_string($request[$key]) || strlen($request[$key]) > 120 || preg_match('/[\r\n]/', $request[$key]) === 1) {
            throw new InvalidArgumentException('Filtro de historial inválido.');
        }
        return $request[$key];
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
