<?php

declare(strict_types=1);

namespace ControlBot\GitHub;

/**
 * Pure, synthetic installation admission preflight. Never grants execution,
 * acquires credentials, or calls a GitHub transport.
 */
final class GitHubReadOnlyInstallationAdmission
{
    private const REPOSITORY = 'pl0n3r/ControlBot';
    private const OWNER = 'pl0n3r';
    private array $events = [];

    /** @return array<string,mixed> */
    public function inspect(array $fixture, ?array $grant, array $request, int $now): array
    {
        if (!$this->validFixture($fixture, $now)) {
            return $this->result(false, 'installation_invalid');
        }
        if (!$this->validGrant($grant, $fixture, $now)) {
            return $this->result(false, 'owner_grant_invalid');
        }
        if (!$this->validRequest($request, $fixture)) {
            return $this->result(false, 'request_invalid');
        }
        return $this->result(true, 'offline_fixture_only');
    }

    /** @return array<int,array<string,string>> */
    public function audit(): array
    {
        return $this->events;
    }

    private function validFixture(array $f, int $now): bool
    {
        if (!$this->exactKeys($f, ['origin', 'test_only', 'installation_id', 'owner',
            'repository', 'permissions', 'status', 'revision', 'issued_at', 'expires_at', 'revoked_at'])) {
            return false;
        }
        if ($f['origin'] !== 'synthetic_installation_fixture'
            || $f['test_only'] !== true
            || !is_int($f['installation_id']) || $f['installation_id'] <= 0
            || $f['owner'] !== self::OWNER || $f['repository'] !== self::REPOSITORY
            || $f['status'] !== 'active' || $f['revoked_at'] !== null
            || !is_int($f['revision']) || $f['revision'] <= 0
            || !is_int($f['issued_at']) || !is_int($f['expires_at'])
            || $f['issued_at'] > $now || $f['expires_at'] <= $now
            || $f['expires_at'] <= $f['issued_at']
            || $f['expires_at'] - $f['issued_at'] > 300
            || !is_array($f['permissions']) || array_is_list($f['permissions'])) {
            return false;
        }
        foreach ($f['permissions'] as $scope => $level) {
            if (!is_string($scope) || !in_array($scope, ['metadata', 'issues', 'pull_requests'], true)
                || $level !== 'read') {
                return false;
            }
        }
        return ($f['permissions']['metadata'] ?? null) === 'read';
    }

    private function validGrant(?array $g, array $f, int $now): bool
    {
        if ($g === null || !$this->exactKeys($g, ['origin', 'test_only', 'owner_verified',
            'installation_id', 'owner', 'repository', 'revision', 'issued_at', 'expires_at'])) {
            return false;
        }
        return $g['origin'] === 'synthetic_owner_approval'
            && $g['test_only'] === true && $g['owner_verified'] === true
            && $g['installation_id'] === $f['installation_id']
            && $g['owner'] === self::OWNER && $g['repository'] === self::REPOSITORY
            && $g['revision'] === $f['revision']
            && is_int($g['issued_at']) && is_int($g['expires_at'])
            && $g['issued_at'] <= $now && $g['expires_at'] > $now
            && $g['issued_at'] >= $f['issued_at']
            && $g['expires_at'] <= $f['expires_at']
            && $g['expires_at'] > $g['issued_at']
            && $g['expires_at'] - $g['issued_at'] <= 300;
    }

    private function validRequest(array $r, array $f): bool
    {
        if (!$this->exactKeys($r, ['method', 'repository', 'resource', 'installation_id', 'revision'])
            || $r['method'] !== 'GET' || $r['repository'] !== self::REPOSITORY
            || !in_array($r['resource'], ['issues', 'pulls'], true)
            || $r['installation_id'] !== $f['installation_id']
            || $r['revision'] !== $f['revision']) {
            return false;
        }
        $resourceScope = $r['resource'] === 'issues' ? 'issues' : 'pull_requests';
        // Exact least-privilege scopes for this one requested projection.
        return $this->exactKeys($f['permissions'], ['metadata', $resourceScope]);
    }

    /** @param list<string> $keys */
    private function exactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);
        return $actual === $keys;
    }

    /** @return array<string,mixed> */
    private function result(bool $valid, string $reason): array
    {
        $status = $valid ? 'OFFLINE_VALIDATED' : 'UNKNOWN';
        if (count($this->events) < 16) {
            $this->events[] = ['status' => $status, 'reason' => $reason];
        }
        return [
            'version' => 1,
            'status' => $status,
            'reason' => $reason,
            'fixture_validated' => $valid,
            'runtime_authorized' => false,
            'production_verified' => false,
            'execute_actions' => false,
            'authority' => 'unchanged',
        ];
    }
}
