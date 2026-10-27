<?php
declare(strict_types=1);

namespace ControlBot\GitHub;

use Closure;
use Throwable;

require_once __DIR__ . '/GitHubReadOnlyInstallationSource.php';
require_once __DIR__ . '/GitHubReadOnlyHttpsTransport.php';
require_once __DIR__ . '/GitHubReadOnlyProjectionWriter.php';

/** Disconnected fixture-only composition. Never registered as a runtime service. */
final class GitHubReadOnlyProjectionPipeline
{
    public function __construct(
        private readonly GitHubReadOnlyInstallationSource $source,
        private readonly GitHubReadOnlyHttpsTransport $transport,
        private readonly GitHubReadOnlyProjectionWriter $writer,
        private readonly bool $enabled = false,
    ) {}

    /** GET adapters corroborate metadata; they never supply snapshot content. */
    public function project(
        array $fixture, array $request, array $httpsRequest,
        array $snapshots, array $expected, string $fixtureDirectory,
        int $now, ?Closure $witness = null,
    ): array {
        if (!$this->enabled) {
            return self::deny('disabled');
        }
        if ($witness === null) {
            return self::deny('witness_missing');
        }
        try {
            if ($now < 1 || !array_is_list($snapshots) || count($snapshots) !== 1
                || !array_is_list($expected) || count($expected) !== 1
                || !is_array($snapshots[0]) || !is_array($snapshots[0]['repositories'] ?? null)
                || !array_is_list($snapshots[0]['repositories'])
                || count($snapshots[0]['repositories']) !== 1) {
                return self::deny('snapshot_invalid');
            }
            $snapshot = $snapshots[0];
            $repo = $snapshot['repositories'][0];
            if (!is_array($repo) || !is_string($fixture['repository'] ?? null)
                || !is_string($fixture['sha'] ?? null)
                || !is_int($fixture['observed_at'] ?? null)
                || ($fixture['test_only'] ?? null) !== true
                || ($fixture['origin'] ?? null) !== 'server_fixture'
                || ($request['resource'] ?? null) !== 'issues'
                || ($httpsRequest['resource'] ?? null) !== 'issues'
                || ($httpsRequest['repository'] ?? null) !== $fixture['repository']
                || ($httpsRequest['now'] ?? null) !== $now
                || ($httpsRequest['observed_at'] ?? null) !== $fixture['observed_at']
                || ($httpsRequest['expires_at'] ?? null) !== ($fixture['expires_at'] ?? null)
                || ($httpsRequest['page_size'] ?? null) !== ($request['page_size'] ?? null)
                || ($repo['repository'] ?? null) !== $fixture['repository']
                || ($repo['main_sha'] ?? null) !== $fixture['sha']
                || ($repo['observed_at'] ?? null) !== $fixture['observed_at']
                || ($snapshot['observed_at'] ?? null) !== $fixture['observed_at']) {
                return self::deny('identity_mismatch');
            }
            // No free-text issues/PRs or arbitrary check names in this first
            // isolated fixture; counters never authorize raw GitHub content.
            foreach (['issues', 'pull_requests'] as $collection) {
                if (($repo[$collection]['items'] ?? null) !== []
                    || ($repo[$collection]['truncated'] ?? null) !== false) {
                    return self::deny('payload_not_minimized');
                }
            }
            if (($repo['latest_release'] ?? null) !== null
                || ($repo['latest_workflow'] ?? null) !== null
                || ($repo['checks']['truncated'] ?? null) !== false
                || !is_array($repo['checks']['items'] ?? null)
                || !array_is_list($repo['checks']['items'])) {
                return self::deny('payload_not_minimized');
            }
            foreach ($repo['checks']['items'] as $check) {
                if (!is_array($check)
                    || !in_array($check['name'] ?? null, ['CI', 'Validar', 'CodeQL', 'SonarCloud Code Analysis'], true)) {
                    return self::deny('payload_not_minimized');
                }
            }
            $view = GitHubProjectView::project($snapshot, $now, 300);
            if (($view['state'] ?? null) !== 'current' || ($view['freshness'] ?? null) !== 'current'
                || count($view['repositories'] ?? []) !== 1
                || ($view['repositories'][0]['state'] ?? null) !== 'current') {
                return self::deny('snapshot_incomplete');
            }
            $manifest = [[
                'project_id' => $snapshot['project_id'],
                'observed_at' => $snapshot['observed_at'],
                'repositories' => [[
                    'repository' => $repo['repository'], 'main_sha' => $repo['main_sha'],
                    'observed_at' => $repo['observed_at'],
                ]],
            ]];
            if ($manifest !== $expected) {
                return self::deny('manifest_mismatch');
            }
            $source = $this->source->project($fixture, $request, $now);
            if (($source['status'] ?? null) !== 'CURRENT'
                || ($source['fixture_projection']['kind'] ?? null) !== 'fixture_projection'
                || ($source['fixture_projection']['repository'] ?? null) !== $repo['repository']
                || ($source['fixture_projection']['sha'] ?? null) !== $repo['main_sha']
                || ($source['fixture_projection']['resource'] ?? null) !== 'issues'
                || ($source['fixture_projection']['items_count'] ?? null) !== 0) {
                return self::deny('source_unknown');
            }
            $transport = $this->transport->read($httpsRequest);
            if (($transport['status'] ?? null) !== 'CURRENT'
                || ($transport['projection']['kind'] ?? null) !== 'offline_metadata_only'
                || ($transport['projection']['items_count'] ?? null) !== 0) {
                return self::deny('transport_unknown');
            }
            $written = $this->writer->write($snapshots, $expected, $fixtureDirectory,
                $now, 300, $witness);
            if (($written['status'] ?? null) !== 'CURRENT') {
                return self::deny('projection_unknown');
            }
            return [
                'status' => 'CURRENT', 'reason' => 'synthetic_fixture_only',
                'project_count' => 1, 'repository_count' => 1,
                'runtime_authorized' => false, 'production_verified' => false,
                'execute_actions' => false, 'authority' => 'unchanged',
            ];
        } catch (Throwable) {
            return self::deny('invalid');
        }
    }

    private static function deny(string $reason): array
    {
        return [
            'status' => 'UNKNOWN', 'reason' => $reason,
            'project_count' => 0, 'repository_count' => 0,
            'runtime_authorized' => false, 'production_verified' => false,
            'execute_actions' => false, 'authority' => 'unchanged',
        ];
    }
}
