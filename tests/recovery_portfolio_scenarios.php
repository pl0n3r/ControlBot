<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/InfrastructureProvider.php';
require_once dirname(__DIR__) . '/src/RecoveryProfile.php';
require_once dirname(__DIR__) . '/src/RecoveryPortfolio.php';

use ControlBot\Security\RecoveryPortfolio;

$root = dirname(__DIR__) . '/config/recovery';
$profiles = [];
foreach (['autofactory', 'brvtal', 'condor', 'controlbot', 'factory', 'grindflow'] as $key) {
    $profiles[$key] = json_decode(file_get_contents($root . '/' . $key . '.json'), true, 32, JSON_THROW_ON_ERROR);
}
$scenario = $argv[1] ?? '';
if ($scenario === 'baseline') {
    $out = RecoveryPortfolio::project($profiles);
} elseif ($scenario === 'stale') {
    $profiles['controlbot']['freshness'] = 'stale';
    $out = RecoveryPortfolio::project($profiles);
} elseif (in_array($scenario, ['cross-project', 'sensitive', 'extra-project', 'bad-shape', 'unexpected-runner'], true)) {
    if ($scenario === 'cross-project') $profiles['controlbot']['project_ref'] = 'controlbot:project/project-brvtal';
    if ($scenario === 'sensitive') $profiles['controlbot']['api_key'] = 'do-not-echo';
    if ($scenario === 'extra-project') $profiles['unknown-project'] = null;
    if ($scenario === 'bad-shape') $profiles['controlbot'] = 'unexpected';
    if ($scenario === 'unexpected-runner') {
        $profiles['factoryrunner'] = $profiles['factory'];
        $profiles['factoryrunner']['project_ref'] = 'controlbot:project/project-factoryrunner';
    }
    try {
        RecoveryPortfolio::project($profiles);
        $out = ['rejected' => false];
    } catch (\Throwable) {
        $out = ['rejected' => true];
    }
} else {
    fwrite(STDERR, "Unknown scenario\n");
    exit(2);
}
echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
