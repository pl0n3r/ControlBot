<?php
declare(strict_types=1);

require __DIR__.'/../src/CapabilityPolicy.php';
require __DIR__.'/../src/CapabilityGrant.php';
require __DIR__.'/../src/SecretReference.php';
require __DIR__.'/../src/SecretsBroker.php';
require __DIR__.'/../src/HostingerPublicApi.php';
require __DIR__.'/../src/HostingerPublicApiAdapter.php';
require __DIR__.'/../src/HostingerPublicApiRuntime.php';

use ControlBot\Production\{
    CapabilityGrant,
    HostingerPublicApiRuntime,
    SecretReference,
    SecretsBroker
};

const FIXTURE_CREDENTIAL = 'fixture-credential-ALPHA-938271';

function reference(string $id, string $capability, array $replace = []): SecretReference
{
    return SecretReference::fromRecord(array_replace([
        'version' => 1,
        'reference_id' => $id,
        'capability' => $capability,
        'project' => 'controlbot',
        'environment' => 'production',
        'provider' => 'hostinger',
        'secret_kind' => 'api_token',
        'generation' => 1,
        'issued_at' => '2026-10-05T04:00:00Z',
        'revoked_at' => null,
    ], $replace));
}

function brokerWith(array $references): SecretsBroker
{
    $broker = new SecretsBroker(['hostinger-public-api']);
    foreach ($references as $reference) {
        $broker->register($reference, FIXTURE_CREDENTIAL);
    }
    return $broker;
}

function grant(): CapabilityGrant
{
    return CapabilityGrant::issue([
        'version' => 1,
        'grant_id' => '11111111-1111-4111-8111-111111111111',
        'capability' => 'cron.write',
        'project' => 'controlbot',
        'environment' => 'production',
        'resource' => 'cron:orchestrator',
        'operation' => 'create',
        'issue' => 'pl0n3r/ControlBot#722',
        'run_id' => '22222222-2222-4222-8222-222222222222',
        'subject' => 'controlbot-worker',
        'issued_at' => '2026-10-05T04:00:00Z',
        'expires_at' => '2026-10-05T04:30:00Z',
        'backup_receipt_id' => '33333333-3333-4333-8333-333333333333',
        'owner_approval_id' => null,
        'idempotency_key' => 'hostinger-api-runtime-722',
        'revoked_at' => null,
    ]);
}

function scope(): array
{
    return [
        'capability' => 'cron.write',
        'project' => 'controlbot',
        'environment' => 'production',
        'resource' => 'cron:orchestrator',
        'operation' => 'create',
        'issue' => 'pl0n3r/ControlBot#722',
        'run_id' => '22222222-2222-4222-8222-222222222222',
        'subject' => 'controlbot-worker',
    ];
}

$name = $argv[1] ?? '';
$now = strtotime('2026-10-05T04:10:00Z');

if ($name === 'canonical') {
    $calls = [];
    $transport = static function (string $method, string $url, array $headers) use (&$calls): array {
        $calls[] = [
            'method' => $method,
            'url' => $url,
            'authorization_present' => isset($headers['Authorization']),
        ];
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $body = $path === '/api/hosting/v1/websites'
            ? ['data' => [['domain' => 'control.example.test', 'website_type' => 'other']]]
            : (str_ends_with($path, '/output')
                ? ['output' => 'collector ok']
                : [['uid' => 'cron_1', 'time' => '*/5 * * * *', 'command' => 'php collector.php']]);
        return [
            'status' => 200,
            'headers' => ['x-ratelimit-remaining' => '89'],
            'body' => json_encode($body, JSON_THROW_ON_ERROR),
        ];
    };

    $webRef = reference('11111111-2222-4333-8444-555555555551', 'hostinger.read');
    $cronRef = reference('11111111-2222-4333-8444-555555555552', 'cron.snapshot');
    $runtime = new HostingerPublicApiRuntime(brokerWith([$webRef, $cronRef]), $transport);

    $web = $runtime->websites($webRef, 'controlbot', 'production', $now);
    $cron = $runtime->cronSnapshot('u123456789', $cronRef, 'controlbot', 'production', $now);
    $output = $runtime->cronOutput('u123456789', 'cron_1', $cronRef, 'controlbot', 'production', $now);

    echo json_encode(compact('web', 'cron', 'output', 'calls'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit;
}

if ($name === 'scope') {
    $calls = 0;
    $transport = static function () use (&$calls): array {
        $calls++;
        return ['status' => 200, 'headers' => [], 'body' => '{"data":[]}'];
    };

    $registered = reference('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeee1', 'hostinger.read');
    $broker = brokerWith([$registered]);
    $runtime = new HostingerPublicApiRuntime($broker, $transport);
    $generation2 = reference(
        'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeee1',
        'hostinger.read',
        ['generation' => 2],
    );

    $providerRegistered = reference(
        'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeee2',
        'hostinger.read',
        ['provider' => 'github'],
    );
    $providerHandle = reference('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeee2', 'hostinger.read');
    $providerRuntime = new HostingerPublicApiRuntime(
        brokerWith([$providerRegistered]),
        $transport,
    );

    $kindRegistered = reference(
        'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeee3',
        'hostinger.read',
        ['secret_kind' => 'password'],
    );
    $kindHandle = reference('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeee3', 'hostinger.read');
    $kindRuntime = new HostingerPublicApiRuntime(
        brokerWith([$kindRegistered]),
        $transport,
    );

    $cases = [
        'capability' => $runtime->cronSnapshot('u123456789', $registered, 'controlbot', 'production', $now),
        'project' => $runtime->websites($registered, 'condor', 'production', $now),
        'environment' => $runtime->websites($registered, 'controlbot', 'staging', $now),
        'generation' => $runtime->websites($generation2, 'controlbot', 'production', $now),
        'provider' => $providerRuntime->websites($providerHandle, 'controlbot', 'production', $now),
        'secret_kind' => $kindRuntime->websites($kindHandle, 'controlbot', 'production', $now),
    ];

    $broker->revoke($registered->referenceId(), '2026-10-05T04:05:00Z');
    $cases['revoked'] = $runtime->websites($registered, 'controlbot', 'production', $now);

    echo json_encode(['cases' => $cases, 'transport_calls' => $calls], JSON_THROW_ON_ERROR), PHP_EOL;
    exit;
}

if ($name === 'write') {
    $calls = 0;
    $transport = static function () use (&$calls): array {
        $calls++;
        throw new RuntimeException('transport should not execute');
    };
    $runtime = new HostingerPublicApiRuntime(
        new SecretsBroker(['hostinger-public-api']),
        $transport,
    );
    $plan = $runtime->planCronWrite(
        grant(),
        scope(),
        'u123456789',
        ['time' => '*/5 * * * *', 'command' => 'php collector.php'],
        $now,
    );
    echo json_encode(['plan' => $plan, 'transport_calls' => $calls], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit;
}

if ($name === 'redaction') {
    $ref = reference('bbbbbbbb-cccc-4ddd-8eee-fffffffffff1', 'hostinger.read');

    $resultTransport = static fn (): array => [
        'status' => 200,
        'headers' => [],
        'body' => json_encode(
            ['data' => [['domain' => FIXTURE_CREDENTIAL, 'website_type' => 'other']]],
            JSON_THROW_ON_ERROR,
        ),
    ];
    $resultRuntime = new HostingerPublicApiRuntime(brokerWith([$ref]), $resultTransport);
    $result = $resultRuntime->websites($ref, 'controlbot', 'production', $now);

    $errorTransport = static function (string $method, string $url, array $headers): never {
        throw new RuntimeException('transport echoed '.$headers['Authorization']);
    };
    $errorRef = reference('bbbbbbbb-cccc-4ddd-8eee-fffffffffff2', 'hostinger.read');
    $errorRuntime = new HostingerPublicApiRuntime(brokerWith([$errorRef]), $errorTransport);
    $error = $errorRuntime->websites($errorRef, 'controlbot', 'production', $now);

    $encoded = json_encode([$result, $error], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    echo json_encode([
        'result' => $result,
        'error' => $error,
        'contains_fixture' => str_contains($encoded, FIXTURE_CREDENTIAL),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit;
}

fwrite(STDERR, "scenario invalid\n");
exit(2);
