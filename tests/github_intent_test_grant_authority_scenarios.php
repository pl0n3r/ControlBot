<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/GitHubIntentTestGrantAuthority.php';

try {
    if ($argc !== 2) {
        throw new InvalidArgumentException('fixture required');
    }
    $raw = base64_decode($argv[1], true);
    if ($raw === false || strlen($raw) > 16384) {
        throw new InvalidArgumentException('invalid fixture');
    }
    $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($input)) {
        throw new InvalidArgumentException('invalid fixture');
    }
    $key = hex2bin($input['key'] ?? '');
    if ($key === false) {
        throw new InvalidArgumentException('invalid key');
    }
    $result = \ControlBot\GitHub\GitHubIntentTestGrantAuthority::evaluate(
        $input['requirements'], $input['approval'], $input['trusted'],
        $input['seen'], $input['revoked'], $input['now'], $key
    );
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (Throwable) {
    // The fixture CLI never emits payloads, token material or stack traces.
    echo json_encode(['decision' => 'deny', 'reason' => 'invalid_fixture',
        'test_grant_valid' => false, 'execution' => false], JSON_THROW_ON_ERROR);
}
