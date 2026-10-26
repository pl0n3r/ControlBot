<?php
declare(strict_types=1);

namespace ControlBot\GitHub;

use Closure;
use Throwable;

/**
 * Offline-only source for GET-shaped GitHub installation fixtures.
 *
 * No HTTP client, token, SDK, GitHub permission or runtime wiring exists here.
 * A server-side TEST harness injects both an attested fixture and a fake reader.
 */
final class GitHubReadOnlyInstallationSource
{
    private const MAX_PAGES = 3;
    private const MAX_ITEMS_PER_PAGE = 25;
    private array $events = [];

    public function __construct(
        private readonly Closure $fakeGet,
        private readonly string $testFixtureKey,
    ) {}

    /**
     * The fixture must originate from the test harness, never a browser payload.
     * Its synthetic HMAC only binds fixture fields; it is NOT a GitHub credential.
     */
    public function project(array $serverFixture, array $request, int $now): array
    {
        try {
            if (!$this->validFixture($serverFixture, $now)) {
                return $this->result('UNKNOWN', 'untrusted_or_stale_fixture', null, 0);
            }
            if (!$this->validRequest($request, $serverFixture)) {
                return $this->result('UNKNOWN', 'identity_or_scope_mismatch', null, 0);
            }

            $count = 0;
            $pages = 0;
            $resource = $request['resource'];
            $repo = $serverFixture['repository'];
            $sha = $serverFixture['sha'];
            $size = $request['page_size'];

            for ($page = 1; $page <= self::MAX_PAGES; $page++) {
                // Fixed relative route, never a user-controlled URL.
                $query = [
                    'method' => 'GET',
                    'route' => '/repos/' . $repo . '/' . $resource,
                    'page' => $page,
                    'per_page' => $size,
                    'mode' => 'offline_test_only',
                ];
                $response = ($this->fakeGet)($query);
                if (!$this->validPage($response, $repo, $sha, $page, $size)) {
                    return $this->result('UNKNOWN', 'incomplete_or_untrusted_page', null, $pages);
                }
                $pages++;
                $count += count($response['items']);
                if ($response['next_page'] === null) {
                    return $this->result('CURRENT', 'fixture_only', [
                        'repository' => $repo,
                        'sha' => $sha,
                        'resource' => $resource,
                        'items_count' => $count,
                        'pages' => $pages,
                        'kind' => 'fixture_projection',
                    ], $pages);
                }
            }
            return $this->result('UNKNOWN', 'page_limit', null, $pages);
        } catch (Throwable) {
            // Never expose fake transport exceptions or their possibly sensitive text.
            return $this->result('UNKNOWN', 'fixture_transport_error', null, 0);
        }
    }

    /** Sanitized and bounded in-memory audit; no fixture payload or owner fields. */
    public function audit(): array
    {
        return $this->events;
    }

    private function validFixture(array $f, int $now): bool
    {
        $fields = [
            'origin', 'test_only', 'owner_verified', 'installation_id',
            'owner', 'repository', 'sha', 'scopes', 'observed_at',
            'expires_at', 'proof',
        ];
        if (count($f) !== count($fields)
            || count(array_intersect($fields, array_keys($f))) !== count($fields)
            || ($f['origin'] ?? null) !== 'server_fixture'
            || ($f['test_only'] ?? null) !== true
            || ($f['owner_verified'] ?? null) !== true
            || !is_int($f['installation_id']) || $f['installation_id'] <= 0
            || !is_string($f['owner'])
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,38}$/D', $f['owner']) !== 1
            || !is_string($f['repository'])
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,38}\/[A-Za-z0-9_.-]{1,100}$/D', $f['repository']) !== 1
            || explode('/', $f['repository'], 2)[0] !== $f['owner']
            || !is_string($f['sha'])
            || preg_match('/^[a-f0-9]{40}$/D', $f['sha']) !== 1
            || !is_array($f['scopes'])
            || $f['scopes'] !== ['contents:read', 'metadata:read']
            || !is_int($f['observed_at'])
            || !is_int($f['expires_at'])
            || $now < $f['observed_at'] || $now >= $f['expires_at']
            || $f['expires_at'] <= $f['observed_at']
            || $f['expires_at'] - $f['observed_at'] > 300
            || strlen($this->testFixtureKey) < 32
            || !is_string($f['proof'])
            || preg_match('/^[a-f0-9]{64}$/D', $f['proof']) !== 1) {
            return false;
        }

        $attested = [];
        foreach ($fields as $name) {
            if ($name !== 'proof') {
                $attested[$name] = $f[$name];
            }
        }
        $encoded = json_encode($attested, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return hash_equals(hash_hmac('sha256', $encoded, $this->testFixtureKey), $f['proof']);
    }

    private function validRequest(array $r, array $f): bool
    {
        return count($r) === 4
            && isset($r['repository'], $r['sha'], $r['resource'], $r['page_size'])
            && $r['repository'] === $f['repository']
            && $r['sha'] === $f['sha']
            && in_array($r['resource'], ['issues', 'pulls'], true)
            && is_int($r['page_size'])
            && $r['page_size'] >= 1
            && $r['page_size'] <= self::MAX_ITEMS_PER_PAGE;
    }

    private function validPage(mixed $r, string $repo, string $sha, int $page, int $size): bool
    {
        if (!is_array($r)
            || count($r) !== 8
            || !isset($r['status'], $r['repository'], $r['sha'], $r['page'], $r['items'], $r['complete'], $r['truncated'])
            || !array_key_exists('next_page', $r)
            || $r['status'] !== 200
            || $r['repository'] !== $repo
            || $r['sha'] !== $sha
            || $r['page'] !== $page
            || $r['truncated'] !== false
            || !is_bool($r['complete'])
            || !is_array($r['items'])
            || !array_is_list($r['items'])
            || count($r['items']) > $size
            || ($r['complete'] && $r['next_page'] !== null)
            || (!$r['complete'] && $r['next_page'] !== $page + 1)) {
            return false;
        }
        foreach ($r['items'] as $item) {
            if (!is_array($item) || count($item) !== 2
                || !isset($item['id'], $item['state'])
                || !is_int($item['id']) || $item['id'] <= 0
                || !in_array($item['state'], ['open', 'closed'], true)) {
                return false;
            }
        }
        return true;
    }

    private function result(string $status, string $reason, ?array $projection, int $pages): array
    {
        $event = ['status' => $status, 'reason' => $reason, 'pages' => $pages];
        if (count($this->events) < 32) {
            $this->events[] = $event;
        }
        return [
            'version' => 1,
            'status' => $status,
            'reason' => $reason,
            'fixture_projection' => $projection,
            'runtime_authorized' => false,
            'production_verified' => false,
            'execute_actions' => false,
            'authority' => 'unchanged',
        ];
    }
}
