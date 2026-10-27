<?php
declare(strict_types=1);

namespace ControlBot\GitHub;

use Closure;
use Throwable;

require_once __DIR__ . '/GitHubReadOnlyInstallationSource.php';

/**
 * Offline-only boundary. Neither this class nor its dependency creates a
 * GitHub transport; the caller must inject a fake test Closure.
 */
final class GitHubReadOnlyRuntimeBoundary
{
    private GitHubReadOnlyInstallationSource $source;
    private array $events = [];

    public function __construct(Closure $fakeGet, string $testFixtureKey)
    {
        $this->source = new GitHubReadOnlyInstallationSource($fakeGet, $testFixtureKey);
    }

    /**
     * An owner grant is exclusively a server-side TEST fixture. It does not
     * represent or mint a production credential, scope, token or authority.
     */
    public function inspect(array $serverFixture, ?array $ownerGrant, array $request, int $now): array
    {
        try {
            if (!$this->validTestGrant($ownerGrant, $serverFixture, $now)) {
                return $this->result('UNKNOWN', 'owner_grant_required', null);
            }

            // Source authenticates the server fixture with a synthetic HMAC,
            // enforces exact repository/SHA and GET-only bounded pagination.
            $projection = $this->source->project($serverFixture, $request, $now);
            if (($projection['status'] ?? null) !== 'CURRENT'
                || ($projection['reason'] ?? null) !== 'fixture_only'
                || !is_array($projection['fixture_projection'] ?? null)
                || ($projection['runtime_authorized'] ?? null) !== false
                || ($projection['production_verified'] ?? null) !== false
                || ($projection['execute_actions'] ?? null) !== false) {
                return $this->result('UNKNOWN', 'source_unknown', null);
            }
            return $this->result('CURRENT', 'fixture_only', $projection['fixture_projection']);
        } catch (Throwable) {
            // Never leak transport exception contents, fixtures or identifiers.
            return $this->result('UNKNOWN', 'fixture_error', null);
        }
    }

    /** Bounded, sanitized in-memory audit. Does not expose source payloads. */
    public function audit(): array
    {
        return $this->events;
    }

    private function validTestGrant(?array $g, array $f, int $now): bool
    {
        if ($g === null) {
            return false;
        }
        $fields = ['origin', 'test_only', 'owner_verified', 'installation_id',
            'owner', 'repository', 'sha', 'issued_at', 'expires_at'];
        if (count($g) !== count($fields)
            || count(array_intersect($fields, array_keys($g))) !== count($fields)
            || ($g['origin'] ?? null) !== 'server_test_grant'
            || ($g['test_only'] ?? null) !== true
            || ($g['owner_verified'] ?? null) !== true
            || !is_int($g['installation_id']) || $g['installation_id'] <= 0
            || !is_string($g['owner']) || !is_string($g['repository']) || !is_string($g['sha'])
            || !is_int($g['issued_at']) || !is_int($g['expires_at'])
            || $g['issued_at'] > $now || $g['expires_at'] <= $now
            || $g['expires_at'] <= $g['issued_at']
            || $g['expires_at'] - $g['issued_at'] > 300) {
            return false;
        }
        foreach (['installation_id', 'owner', 'repository', 'sha'] as $key) {
            if (!array_key_exists($key, $f) || $g[$key] !== $f[$key]) {
                return false;
            }
        }
        return true;
    }

    private function result(string $status, string $reason, ?array $fixtureProjection): array
    {
        if (count($this->events) < 32) {
            $this->events[] = ['status' => $status, 'reason' => $reason];
        }
        return [
            'version' => 1,
            'status' => $status,
            'reason' => $reason,
            'fixture_projection' => $fixtureProjection,
            'runtime_authorized' => false,
            'production_verified' => false,
            'execute_actions' => false,
            'authority' => 'unchanged',
        ];
    }
}
