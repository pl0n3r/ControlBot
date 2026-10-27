<?php
declare(strict_types=1);

/**
 * #981 / #55 AC-06. Deterministic PHP fixture of the existing GitHub project
 * read-only UI. Both viewport values consume the SAME actual HTML/CSS;
 * responsive layout is supplied solely by the real CSS media queries.
 * No HTTP client, user session, private data, credentials or live services.
 */
require_once dirname(__DIR__) . '/src/GitHubProjectUi.php';

use ControlBot\GitHub\GitHubProjectUi;

$viewport = $argv[1] ?? '';
$scenario = $argv[2] ?? '';
if (!in_array($viewport, ['375', '390', '1280', '1440'], true)
    || !in_array($scenario, [
        'full', 'empty', 'loading', 'error', 'permission_denied',
        'stale', 'truncated', 'ambiguous', 'malformed', 'hostile'
    ], true)) {
    fwrite(STDERR, "Invalid synthetic viewport or scenario\n");
    exit(2);
}

$repo = [
    'repository_id' => 'synthetic-repo',
    'repository' => 'example/Fixture',
    'source_ref' => 'https://github.com/example/Fixture',
    'observed_at' => 1000,
    'freshness' => 'current',
    'age_seconds' => 0,
    'state' => 'current',
    'main_sha' => str_repeat('a', 40),
    'checks' => [
        'items' => [['name' => 'CI synthetic', 'status' => 'completed', 'conclusion' => 'success']],
        'truncated' => false,
    ],
    'pull_requests' => [
        'items' => [[
            'number' => 21, 'title' => 'Synthetic pull',
            'draft' => false, 'head_sha' => str_repeat('b', 40),
            'base_ref' => 'main', 'review_state' => 'approved',
            'mergeability' => 'mergeable',
        ]],
        'truncated' => false,
    ],
    'issues' => [
        'items' => [['number' => 34, 'title' => 'Synthetic issue', 'labels' => ['estado: disponible']]],
        'truncated' => false,
    ],
    'latest_release' => ['tag_name' => 'v0.0.0-fixture', 'draft' => false, 'prerelease' => false],
    'latest_workflow' => [
        'name' => 'Validate fixture', 'status' => 'completed',
        'conclusion' => 'success', 'head_sha' => str_repeat('a', 40),
        'run_number' => 1,
    ],
];
$view = [
    'version' => 1,
    'project_id' => 'synthetic-project',
    'state' => 'current',
    'freshness' => 'current',
    'observed_at' => 1000,
    'age_seconds' => 0,
    'repositories' => [$repo],
];
$surface = 'ready';

switch ($scenario) {
    case 'empty':
        $view['repositories'] = [];
        break;
    case 'loading':
    case 'error':
    case 'permission_denied':
        $surface = $scenario;
        break;
    case 'stale':
        $view['state'] = 'unknown';
        $view['freshness'] = 'stale';
        $view['repositories'][0]['state'] = 'unknown';
        $view['repositories'][0]['freshness'] = 'stale';
        break;
    case 'truncated':
        $view['state'] = 'unknown';
        $view['repositories'][0]['checks']['truncated'] = true;
        break;
    case 'ambiguous':
        $view['state'] = 'unknown';
        $view['repositories'][0]['state'] = 'unknown';
        $view['repositories'][0]['pull_requests']['items'][0]['review_state'] = 'unknown';
        $view['repositories'][0]['pull_requests']['items'][0]['mergeability'] = 'unknown';
        break;
    case 'malformed':
        unset($view['repositories'][0]['checks']['items']);
        break;
    case 'hostile':
        $view['repositories'][0]['repository'] = '<svg onload=alert(1)>';
        $view['repositories'][0]['pull_requests']['items'][0]['title'] =
            '<img src=x onerror=alert(1)>';
        break;
}

echo GitHubProjectUi::render($view, $surface);
