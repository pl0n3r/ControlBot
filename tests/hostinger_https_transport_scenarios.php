<?php
declare(strict_types=1);
require __DIR__ . '/../src/HostingerHttpsTransportSimulator.php';
use ControlBot\Hostinger\HostingerHttpsTransportSimulator;

function command(string $tenant = 'alpha', string $id = 'initial', string $key = 'initial-key', string $method = 'POST'): array
{
    return [
        'tenant_ref' => 'fixture:tenant/' . $tenant,
        'operation_ref' => 'fixture:mutation/' . $id,
        'method' => $method,
        'idempotency_key' => 'fixture:key/' . $key,
    ];
}
function http(int $code, bool $tls = true, int $redirects = 0, string $kind = 'http'): array
{
    return ['kind' => $kind, 'status_code' => $code, 'tls_verified' => $tls, 'redirect_count' => $redirects];
}
$mode = $argv[1] ?? '';
$simulator = new HostingerHttpsTransportSimulator();
if ($mode === 'contract') {
    $original = command();
    $first = $simulator->simulate($original, http(202, kind: 'accepted'));
    $again = $simulator->simulate($original, ['kind'=>'timeout']);
    $different = $original;
    $different['method'] = 'PATCH';
    $collision = $simulator->simulate($different, http(202, kind: 'accepted'));
    $foreignSameKey = command('bravo');
    $foreignSameKey['idempotency_key'] = $original['idempotency_key'];
    $foreign = $simulator->simulate($foreignSameKey, http(201, kind: 'accepted'));
    $bravo = $simulator->simulate(command('bravo', 'bravo-op', 'bravo-key'), http(200, kind: 'accepted'));
    $result = compact('first','again','collision','foreign','bravo');
    $result['attempts'] = $simulator->attemptCount();
} elseif ($mode === 'closed') {
    $events = [
        'timeout' => ['kind'=>'timeout'],
        'network' => ['kind'=>'network_error'],
        'ambiguous' => ['kind'=>'ambiguous'],
        'certificate' => ['kind'=>'certificate_invalid'],
        'redirect' => http(302, redirects: 1, kind: 'redirect'),
        'redirect_count' => http(200, redirects: 1),
        'client' => http(403),
        'server' => http(503),
        'unverified_tls' => http(200, tls: false),
        'missing_tls' => ['kind'=>'http','status_code'=>200,'redirect_count'=>0],
        'not_http' => ['kind'=>'http','status_code'=>'200','tls_verified'=>true,'redirect_count'=>0],
        'unknown_kind' => ['kind'=>'success'],
        'body_injection' => ['kind'=>'accepted','status_code'=>200,'tls_verified'=>true,'redirect_count'=>0,'body'=>'private@example.com'],
    ];
    $result = ['receipts'=>[]];
    foreach ($events as $key => $event) {
        $result['receipts'][$key] = $simulator->simulate(command(id:$key, key:$key.'-key'), $event);
    }
    $result['attempts'] = $simulator->attemptCount();
} elseif ($mode === 'privacy') {
    $bad = [
        'email' => ['tenant_ref'=>'person@example.com'],
        'phone' => ['operation_ref'=>'+573001234567'],
        'document' => ['operation_ref'=>'1234567890'],
        'secret' => ['idempotency_key'=>'ghp_example-secret'],
        'unsafe_url' => ['endpoint'=>'https://real.example.net'],
        'name' => ['full_name'=>'Jane Doe'],
        'nested' => ['payload'=>['identification'=>'12345678']],
        'array_tenant' => ['tenant_ref'=>['fixture:tenant/alpha']],
    ];
    $result = ['receipts'=>[]];
    foreach ($bad as $label => $change) {
        $result['receipts'][$label] = $simulator->simulate(array_replace(command(), $change), http(200, kind: 'accepted'));
    }
    $result['attempts'] = $simulator->attemptCount();
} elseif ($mode === 'states') {
    $mutation = command(id:'mutating-op', key:'mutating-key');
    $initial = $simulator->simulate($mutation, ['kind'=>'timeout']);
    $retry = $simulator->simulate($mutation, http(200, kind: 'accepted'));
    $result = compact('initial','retry');
    $result['attempts'] = $simulator->attemptCount();
    $result['success_http'] = $simulator->simulate(
        command(id:'second-op', key:'second-key'), http(204, kind:'accepted'));
    $result['attempts_after'] = $simulator->attemptCount();
} else {
    fwrite(STDERR, "unknown synthetic scenario\n");
    exit(2);
}
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
