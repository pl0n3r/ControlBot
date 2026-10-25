<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/GitHubPullRequestIntentExecutor.php';
try {
    if ($argc !== 2) throw new InvalidArgumentException('fixture missing');
    $encoded = base64_decode($argv[1], true);
    if ($encoded === false || strlen($encoded) > 32768)
        throw new InvalidArgumentException('fixture invalid');
    $payload = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || !isset($payload['intent'], $payload['fixture'], $payload['state']))
        throw new InvalidArgumentException('fixture missing fields');
    if (!is_array($payload['fixture']) || !is_string($payload['fixture']['key'] ?? null))
        throw new InvalidArgumentException('invalid test key');
    $payload['fixture']['key'] = hex2bin($payload['fixture']['key']);
    if ($payload['fixture']['key'] === false) throw new InvalidArgumentException('invalid test key');
    $result = \ControlBot\GitHub\GitHubPullRequestIntentExecutor::simulate(
        $payload['intent'], $payload['fixture'], $payload['state'],
        $payload['audit'] ?? [], $payload['ledger'] ?? []
    );
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (\Throwable) {
    echo json_encode([
        'decision' => 'deny', 'execution' => false, 'runtime_authorized' => false,
        'fake_effect' => ['status' => 'denied', 'reason' => 'invalid_fixture'],
        'fake_state' => [], 'audit' => [], 'ledger' => [], 'replayed' => false
    ], JSON_THROW_ON_ERROR);
}
