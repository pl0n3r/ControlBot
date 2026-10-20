<?php
declare(strict_types=1);

require __DIR__ . '/../src/GitHubIntentTrustedEvidence.php';

use ControlBot\GitHub\GitHubIntentTrustedEvidence;

const NOW = 1791157800;
const REPOSITORY = 'pl0n3r/ControlBot';

function canonicalSnapshot(int $observedAt = NOW - 120): array
{
    return [
        'version' => 1,
        'project_id' => 'controlbot',
        'observed_at' => $observedAt,
        'repositories' => [[
            'repository_id' => 'controlbot-repo',
            'repository' => REPOSITORY,
            'source_ref' => 'https://github.com/' . REPOSITORY,
            'observed_at' => $observedAt,
            'main_sha' => str_repeat('a', 40),
            'checks' => ['items' => [], 'truncated' => false],
            'pull_requests' => ['items' => [], 'truncated' => false],
            'issues' => ['items' => [], 'truncated' => false],
            'latest_release' => null,
            'latest_workflow' => null,
        ]],
    ];
}

function projection(array $snapshot, string $repository = REPOSITORY, int $now = NOW): array
{
    return GitHubIntentTrustedEvidence::derive($snapshot, $repository, $now);
}

$scenario = $argv[1] ?? '';
if ($scenario === 'canonical') {
    echo json_encode(projection(canonicalSnapshot()), JSON_THROW_ON_ERROR);
} elseif ($scenario === 'closed') {
    $callerTimestamp = canonicalSnapshot();
    $callerTimestamp['evidence_observed_at'] = NOW;
    $callerTtl = canonicalSnapshot();
    $callerTtl['evidence_max_age_seconds'] = 9000;
    $stale = canonicalSnapshot(NOW - 301);
    $future = canonicalSnapshot(NOW + 1);
    $duplicate = canonicalSnapshot();
    $duplicate['repositories'][] = $duplicate['repositories'][0];
    $ambiguous = canonicalSnapshot();
    $ambiguous['repositories'][0]['checks']['truncated'] = true;
    $mismatchedSource = canonicalSnapshot();
    $mismatchedSource['repositories'][0]['source_ref'] = 'https://github.com/other/repo';
    $extraField = canonicalSnapshot();
    $extraField['repositories'][0]['caller_attested'] = true;
    echo json_encode([
        'caller_timestamp' => projection($callerTimestamp),
        'caller_ttl' => projection($callerTtl),
        'scope_mismatch' => projection(canonicalSnapshot(), 'other/repo'),
        'scope_case_mismatch' => projection(canonicalSnapshot(), 'pl0n3r/controlbot'),
        'stale' => projection($stale),
        'future' => projection($future),
        'duplicated_repository' => projection($duplicate),
        'partial_or_ambiguous' => projection($ambiguous),
        'mismatched_source' => projection($mismatchedSource),
        'unexpected_row_field' => projection($extraField),
        'untrusted_clock' => projection(canonicalSnapshot(), REPOSITORY, 0),
        'empty_repositories' => projection([
            'version' => 1, 'project_id' => 'controlbot',
            'observed_at' => NOW - 120, 'repositories' => [],
        ]),
    ], JSON_THROW_ON_ERROR);
} else {
    fwrite(STDERR, "Unknown scenario\n");
    exit(2);
}
