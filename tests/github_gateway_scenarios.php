<?php
declare(strict_types=1);

require __DIR__ . '/../src/Approvals.php';
require __DIR__ . '/../src/GitHub.php';

use ControlBot\GitHub\ApiClient;
use ControlBot\GitHub\ApiTransport;
use ControlBot\GitHub\Gateway;

$scenario = $argv[1] ?? '';
$calls = [];
$sender = static function (string $method, string $url, array $headers, ?string $body) use (&$calls): array {
    $calls[] = [$method, $url, $headers, $body];
    $path = (string) parse_url($url, PHP_URL_PATH);
    if (str_ends_with($path, '/branches/main')) {
        return ['status' => 200, 'body' => json_encode(['commit' => ['sha' => str_repeat('a', 40)]])];
    }
    if (str_ends_with($path, '/issues')) {
        return ['status' => 200, 'body' => '[]'];
    }
    if (str_ends_with($path, '/comments')) {
        return ['status' => 201, 'body' => json_encode(['html_url' => 'https://github.com/pl0n3r/factory/issues/137#comment'])];
    }
    if (str_ends_with($path, '/issues/137')) {
        return ['status' => 200, 'body' => json_encode(['html_url' => 'https://github.com/pl0n3r/factory/issues/137'])];
    }
    if (str_ends_with($path, '/git/refs/tags/v1')) {
        return ['status' => 200, 'body' => json_encode(['ref' => 'refs/tags/v1'])];
    }
    if (str_contains($path, '/actions/workflows/')) {
        return ['status' => 204, 'body' => ''];
    }
    return ['status' => 404, 'body' => '{}'];
};
$transport = new ApiTransport($sender);
$api = new ApiClient('ghp_test_only_token', $transport);
$gateway = new Gateway($api);

if ($scenario === 'exact') {
    $gateway->mainSha('pl0n3r/factory');
    $gateway->commentIssue('pl0n3r/factory', 137, 'approved');
    $gateway->closeIssue('pl0n3r/factory', 137);
    $gateway->moveTag('pl0n3r/factory', 'v1', str_repeat('a', 40));
    $gateway->dispatchWorkflow('pl0n3r/factory', 'release-bootstrap.yml', ['expected_sha' => str_repeat('a', 40), 'gate_issue' => '137']);
    echo json_encode(['calls' => $calls], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit;
}

if ($scenario === 'query') {
    $api->json(
        'GET',
        '/repos/pl0n3r/factory/issues',
        null,
        [200],
        ['state' => 'open', 'per_page' => 100, 'page' => 2],
    );
    echo json_encode(['calls' => $calls], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit;
}

if ($scenario === 'destination') {
    $errors = [];
    foreach ([
        'http://api.github.com/repos/pl0n3r/factory',
        'https://evil.example/repos/pl0n3r/factory',
        'https://api.github.com/organizations/pl0n3r',
        'https://user@api.github.com/repos/pl0n3r/factory',
        'https://api.github.com/repos/pl0n3r/factory#fragment',
        'https://api.github.com/repos/pl0n3r/factory?per_page=1',
    ] as $url) {
        try {
            $transport->request('GET', $url, [], null);
        } catch (Throwable $error) {
            $errors[] = $error->getMessage();
        }
    }
    try {
        ApiTransport::url('/repos/pl0n3r/../factory');
    } catch (Throwable $error) {
        $errors[] = $error->getMessage();
    }
    echo json_encode(['errors' => $errors], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit;
}

fwrite(STDERR, "scenario inválido\n");
exit(2);
