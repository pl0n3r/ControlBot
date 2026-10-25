<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/GitHubWorkflowIntentExecutor.php';
try {
    if ($argc !== 2) throw new InvalidArgumentException('fixture missing');
    $raw = base64_decode($argv[1], true);
    if ($raw === false || strlen($raw) > 32768) throw new InvalidArgumentException('fixture invalid');
    $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($input) || !isset($input['intent'], $input['fixture'], $input['state']))
        throw new InvalidArgumentException('fixture missing fields');
    $fixture = $input['fixture'];
    if (!is_array($fixture) || !is_string($fixture['key'] ?? null))
        throw new InvalidArgumentException('invalid test key');
    $fixture['key'] = hex2bin($fixture['key']);
    if ($fixture['key'] === false) throw new InvalidArgumentException('invalid test key');
    $result = \ControlBot\GitHub\GitHubWorkflowIntentExecutor::simulate(
        $input['intent'], $fixture, $input['state'],
        $input['audit'] ?? [], $input['ledger'] ?? []
    );
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (\Throwable) {
    echo json_encode([
        'decision' => 'deny', 'execution' => false, 'runtime_authorized' => false,
        'fake_effect' => ['status' => 'denied', 'reason' => 'invalid_fixture'],
        'fake_state' => [], 'audit' => [], 'ledger' => [], 'replayed' => false
    ], JSON_THROW_ON_ERROR);
}
