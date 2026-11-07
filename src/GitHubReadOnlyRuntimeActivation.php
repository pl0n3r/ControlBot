<?php

declare(strict_types=1);

namespace ControlBot\GitHub;

use Closure;
use Throwable;

require_once __DIR__ . '/GitHubReadOnlyHttpsTransport.php';

/**
 * Build-ahead only: the two injected callbacks are test doubles, never a real
 * GitHub installation or network authority. There is no application wiring.
 */
final class GitHubReadOnlyRuntimeActivation
{
    private array $events = [];

    public function __construct(
        private readonly Closure $trustedSource,
        private readonly Closure $fakeSender,
        private readonly bool $enabled = false,
    ) {
    }

    /** @return array<string, mixed> */
    public function inspect(array $request, int $now): array
    {
        if (!$this->enabled) {
            return $this->deny('disabled');
        }
        if (!$this->validRequest($request, $now)) {
            return $this->deny('invalid_request');
        }

        try {
            // Called only by trusted server code. HTTP clients cannot provide
            // this witness, an installation ID, or a permission grant.
            $witness = ($this->trustedSource)($request['repository'], $request['resource'], $now);
        } catch (Throwable) {
            return $this->deny('source_unavailable');
        }
        if (!$this->validWitness($witness, $request, $now)) {
            return $this->deny('authority_unverified');
        }

        $transport = new GitHubReadOnlyHttpsTransport($this->fakeSender, true);
        $out = $transport->read([
            'method' => 'GET',
            'host' => 'api.github.com',
            'repository' => $request['repository'],
            'resource' => $request['resource'],
            'page_size' => $request['page_size'],
            'observed_at' => $witness['observed_at'],
            'expires_at' => $witness['expires_at'],
            'now' => $now,
        ]);
        if (($out['status'] ?? null) !== 'CURRENT'
            || ($out['runtime_authorized'] ?? null) !== false
            || ($out['production_verified'] ?? null) !== false
            || ($out['execute_actions'] ?? null) !== false) {
            return $this->deny('read_unverified');
        }
        $this->record('offline_validated');
        return [
            'status' => 'OFFLINE_VALIDATED',
            'reason' => 'fake_transport_only',
            'projection' => $out['projection'],
            'runtime_authorized' => false,
            'production_verified' => false,
            'execute_actions' => false,
            'authority' => 'unchanged',
        ];
    }

    private function validRequest(array $r, int $now): bool
    {
        if ($now < 1 || count($r) !== 3
            || !isset($r['repository'], $r['resource'], $r['page_size'])
            || !is_string($r['repository'])
            || preg_match('~^[A-Za-z0-9][A-Za-z0-9._-]{0,38}/[A-Za-z0-9][A-Za-z0-9._-]{0,99}$~D', $r['repository']) !== 1
            || str_contains($r['repository'], '..')
            || !in_array($r['resource'], ['issues', 'pulls'], true)
            || !is_int($r['page_size']) || $r['page_size'] < 1 || $r['page_size'] > 20) {
            return false;
        }
        return true;
    }

    private function validWitness(mixed $w, array $r, int $now): bool
    {
        if (!is_array($w) || count($w) !== 9
            || !isset($w['installation_id'], $w['owner'], $w['repository'],
                $w['permissions'], $w['grant'], $w['observed_at'], $w['expires_at'], $w['verified'])
            || !array_key_exists('revoked', $w)
            || !is_int($w['installation_id']) || $w['installation_id'] < 1
            || $w['verified'] !== true || $w['revoked'] !== false
            || !is_string($w['owner']) || $w['owner'] !== explode('/', $r['repository'], 2)[0]
            || $w['repository'] !== $r['repository']
            || !is_array($w['permissions']) || count($w['permissions']) !== 1
            || ($w['permissions'][$r['resource']] ?? null) !== 'read'
            || !is_int($w['observed_at']) || !is_int($w['expires_at'])
            || $w['observed_at'] > $now || $now >= $w['expires_at']
            || $now - $w['observed_at'] > 300
            || $w['expires_at'] - $w['observed_at'] > 300) {
            return false;
        }
        $g = $w['grant'];
        if (!is_array($g) || count($g) !== 7
            || !isset($g['installation_id'], $g['owner'], $g['repository'],
                $g['resource'], $g['approved'], $g['expires_at'])
            || !array_key_exists('revoked', $g)
            || $g['installation_id'] !== $w['installation_id']
            || $g['owner'] !== $w['owner']
            || $g['repository'] !== $w['repository']
            || $g['resource'] !== $r['resource']
            || $g['approved'] !== true || $g['revoked'] !== false
            || !is_int($g['expires_at']) || $g['expires_at'] <= $now
            || $g['expires_at'] > $w['expires_at']) {
            return false;
        }
        return true;
    }

    /** @return array<string, mixed> */
    private function deny(string $reason): array
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
            $this->events[] = ['reason' => $reason];
        }
    }

    public function audit(): array
    {
        return $this->events;
    }
}
