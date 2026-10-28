<?php
declare(strict_types=1);
namespace ControlBot\GitHub;
use Closure;
use Throwable;
require_once __DIR__ . '/GitHubReadOnlyInstallationAdmission.php';
require_once __DIR__ . '/GitHubReadOnlyProjectionPipeline.php';
/**
 * Test-only composition of the existing synthetic admission and projection
 * pipeline. Not registered in any web route, worker, cron or service container.
 */
final class GitHubReadOnlyAdmissionProjectionGate
{
    public function __construct(
        private readonly GitHubReadOnlyInstallationAdmission $admission,
        private readonly GitHubReadOnlyProjectionPipeline $pipeline,
        private readonly bool $enabled = false,
    ) {}
    /**
     * All readers/senders inside the injected pipeline must be synthetic.
     * The binding witness represents independently observed test fixture state.
     * No witness can guarantee an external revocation after the last check.
     */
    public function project(
        array $installation,
        ?array $grant,
        array $admissionRequest,
        array $sourceFixture,
        array $sourceRequest,
        array $httpsRequest,
        array $snapshots,
        array $expected,
        string $fixtureDirectory,
        int $now,
        ?Closure $bindingWitness = null,
        ?Closure $manifestWitness = null,
    ): array {
        if (!$this->enabled || $bindingWitness === null || $manifestWitness === null) {
            return self::deny('disabled_or_witness_missing');
        }
        try {
            if (!$this->validJoin(
                $installation, $grant, $admissionRequest, $sourceFixture,
                $sourceRequest, $httpsRequest, $snapshots, $expected, $now
            )) {
                return self::deny('binding_invalid');
            }
            // Real, existing admission is mandatory; no callable substitution.
            $admitted = $this->admission->inspect(
                $installation, $grant, $admissionRequest, $now
            );
            if (($admitted['status'] ?? null) !== 'OFFLINE_VALIDATED'
                || ($admitted['fixture_validated'] ?? null) !== true || ($admitted['runtime_authorized'] ?? null) !== false || ($admitted['execute_actions'] ?? null) !== false
                || ($admitted['production_verified'] ?? null) !== false) {
                return self::deny('installation_unknown');
            }
            $binding = [
                'installation_id' => $installation['installation_id'],
                'revision' => $installation['revision'],
                'owner' => $installation['owner'],
                'repository' => $installation['repository'],
                'resource' => 'issues',
                'main_sha' => $sourceFixture['sha'],
                'observed_at' => $sourceFixture['observed_at'],
                'expires_at' => $sourceFixture['expires_at'],
            ];
            // The witness must be stable before any source/transport callback.
            if ($bindingWitness() !== $binding || $manifestWitness() !== $expected) {
                return self::deny('witness_unknown');
            }
            // Pipeline/Writer call this before staging and before their rename.
            // Verify the admission binding on both sides of manifest sampling.
            $atomicWitness = static function () use (
                $bindingWitness, $manifestWitness, $binding, $expected
            ): array {
                try {
                    if ($bindingWitness() !== $binding
                        || $manifestWitness() !== $expected || $bindingWitness() !== $binding) {
                        return [];
                    }
                    return $expected;
                } catch (Throwable) {
                    return [];
                }
            };
            $projected = $this->pipeline->project(
                $sourceFixture, $sourceRequest, $httpsRequest, $snapshots,
                $expected, $fixtureDirectory, $now, $atomicWitness
            );
            if (($projected['status'] ?? null) !== 'CURRENT'
                || ($projected['runtime_authorized'] ?? null) !== false || ($projected['execute_actions'] ?? null) !== false || ($projected['production_verified'] ?? null) !== false) {
                return self::deny('projection_unknown');
            }
            // "CURRENT" is only the state of a synthetic fixture.
            return [
                'status' => 'OFFLINE_VALIDATED',
                'reason' => 'synthetic_join_only',
                'fixture_validated' => true,
                'projection_status' => 'CURRENT',
                'runtime_authorized' => false,
                'execute_actions' => false,
                'production_verified' => false,
                'authority' => 'unchanged',
            ];
        } catch (Throwable) {
            return self::deny('invalid');
        }
    }
    private function validJoin(
        array $i, ?array $g, array $r, array $f, array $sr,
        array $hr, array $snapshots, array $expected, int $now,
    ): bool {
        if ($now < 1 || $g === null
            || ($i['origin'] ?? null) !== 'synthetic_installation_fixture' || ($i['test_only'] ?? null) !== true || ($i['owner'] ?? null) !== 'pl0n3r' || ($i['repository'] ?? null) !== 'pl0n3r/ControlBot'
            || ($i['status'] ?? null) !== 'active' || !array_key_exists('revoked_at', $i) || $i['revoked_at'] !== null || !is_int($i['installation_id'] ?? null) || $i['installation_id'] <= 0
            || !is_int($i['revision'] ?? null) || $i['revision'] <= 0 || !is_int($i['issued_at'] ?? null) || !is_int($i['expires_at'] ?? null) || $i['issued_at'] > $now || $i['expires_at'] <= $now
            || $i['expires_at'] <= $i['issued_at'] || $i['expires_at'] - $i['issued_at'] > 300 || ($i['permissions'] ?? null) !== ['metadata' => 'read', 'issues' => 'read']
            || ($g['origin'] ?? null) !== 'synthetic_owner_approval' || ($g['test_only'] ?? null) !== true || ($g['owner_verified'] ?? null) !== true || ($g['installation_id'] ?? null) !== $i['installation_id']
            || ($g['revision'] ?? null) !== $i['revision'] || ($g['owner'] ?? null) !== $i['owner'] || ($g['repository'] ?? null) !== $i['repository'] || !is_int($g['issued_at'] ?? null) || !is_int($g['expires_at'] ?? null)
            || $g['issued_at'] > $now || $g['expires_at'] <= $now || $g['issued_at'] < $i['issued_at'] || $g['expires_at'] > $i['expires_at'] || $g['expires_at'] <= $g['issued_at'] || $g['expires_at'] - $g['issued_at'] > 300
            || ($r['method'] ?? null) !== 'GET' || ($r['resource'] ?? null) !== 'issues' || ($r['repository'] ?? null) !== $i['repository'] || ($r['installation_id'] ?? null) !== $i['installation_id']
            || ($r['revision'] ?? null) !== $i['revision']) {
            return false;
        }
        if (($f['origin'] ?? null) !== 'server_fixture'
            || ($f['test_only'] ?? null) !== true || ($f['owner_verified'] ?? null) !== true || ($f['owner'] ?? null) !== $i['owner'] || ($f['repository'] ?? null) !== $i['repository']
            || ($f['installation_id'] ?? null) !== $i['installation_id'] || !is_string($f['sha'] ?? null) || preg_match('/^[a-f0-9]{40}$/D', $f['sha']) !== 1 || !is_int($f['observed_at'] ?? null)
            || !is_int($f['expires_at'] ?? null) || $f['observed_at'] < $i['issued_at'] || $f['observed_at'] > $now || $f['expires_at'] <= $now || $f['expires_at'] > $i['expires_at'] || ($sr['resource'] ?? null) !== 'issues'
            || ($sr['repository'] ?? null) !== $i['repository'] || ($sr['sha'] ?? null) !== $f['sha'] || ($hr['method'] ?? null) !== 'GET' || ($hr['resource'] ?? null) !== 'issues'
            || ($hr['repository'] ?? null) !== $i['repository'] || ($hr['now'] ?? null) !== $now || ($hr['observed_at'] ?? null) !== $f['observed_at'] || ($hr['expires_at'] ?? null) !== $f['expires_at']
            || !array_is_list($snapshots) || count($snapshots) !== 1 || !array_is_list($expected) || count($expected) !== 1 || !is_array($snapshots[0]['repositories'] ?? null) || !array_is_list($snapshots[0]['repositories'])
            || count($snapshots[0]['repositories']) !== 1) {
            return false;
        }
        $s = $snapshots[0];
        $repo = $s['repositories'][0];
        if (!is_array($repo)
            || ($s['observed_at'] ?? null) !== $f['observed_at'] || ($repo['observed_at'] ?? null) !== $f['observed_at'] || ($repo['repository'] ?? null) !== $i['repository'] || ($repo['main_sha'] ?? null) !== $f['sha']
            || ($expected[0]['project_id'] ?? null) !== ($s['project_id'] ?? null) || ($expected[0]['observed_at'] ?? null) !== $f['observed_at'] || ($expected[0]['repositories'][0]['repository'] ?? null) !== $i['repository']
            || ($expected[0]['repositories'][0]['main_sha'] ?? null) !== $f['sha'] || ($expected[0]['repositories'][0]['observed_at'] ?? null) !== $f['observed_at']) {
            return false;
        }
        return true;
    }
    private static function deny(string $reason): array
    {
        return [
            'status' => 'UNKNOWN',
            'reason' => $reason,
            'fixture_validated' => false,
            'projection_status' => 'UNKNOWN',
            'runtime_authorized' => false,
            'execute_actions' => false,
            'production_verified' => false,
            'authority' => 'unchanged',
        ];
    }
}
