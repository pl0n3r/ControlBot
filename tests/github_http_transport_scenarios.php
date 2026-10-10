<?php
declare(strict_types=1);
require __DIR__ . '/../src/GitHubHttpTransport.php';
use ControlBot\GitHub\GitHubHttpTransport;

const FAKE_SECRET = 'fake-token-never-log-XYZ';
function request(string $suffix = '/issues/42', string $method = 'PATCH', string $type = 'issue.update', string $id = 'intent-good-0001'): array
{
    return [
        'intent_id' => $id,
        'project_id' => 'controlbot',
        'repository_id' => 'pl0n3r/ControlBot',
        'type' => $type,
        'idempotency_key' => 'idempotency:' . $id,
        'method' => $method,
        'url' => 'https://api.github.com/repos/pl0n3r/ControlBot' . $suffix,
        'body' => ['title' => 'offline-only'],
    ];
}
function response(int $status = 200, string $body = '{"ok":true}', bool $tls = true, int $redirect = 0): array
{
    return ['status_code'=>$status, 'body'=>$body, 'tls_verified'=>$tls, 'redirect_count'=>$redirect];
}
$scenario = $argv[1] ?? '';
if ($scenario === 'allowlist') {
    $calls = 0; $secrets = 0;
    $transport = new GitHubHttpTransport(
        static function () use (&$calls) { ++$calls; return response(); },
        static function () use (&$secrets) { ++$secrets; return FAKE_SECRET; },
    );
    $bad = [];
    $cases = [
        'outside_host' => ['url'=>'https://evil.example/repos/pl0n3r/ControlBot/issues/42'],
        'http' => ['url'=>'http://api.github.com/repos/pl0n3r/ControlBot/issues/42'],
        'user_info' => ['url'=>'https://api.github.com@evil.example/repos/pl0n3r/ControlBot/issues/42'],
        'foreign_repo' => ['url'=>'https://api.github.com/repos/stranger/repo/issues/42'],
        'untyped_path' => ['url'=>'https://api.github.com/repos/pl0n3r/ControlBot/hooks'],
        'query' => ['url'=>'https://api.github.com/repos/pl0n3r/ControlBot/issues/42?x=1'],
        'encoded_traversal' => ['url'=>'https://api.github.com/repos/pl0n3r/ControlBot/issues/%2e%2e'],
        'bad_method' => ['method'=>'POST'],
        'bad_type' => ['type'=>'repo.delete'],
        'dot_repository_parent' => [
            'repository_id'=>'pl0n3r/..',
            'url'=>'https://api.github.com/repos/pl0n3r/../issues',
            'method'=>'POST', 'type'=>'issue.create',
        ],
        'dot_repository_self' => [
            'repository_id'=>'pl0n3r/.',
            'url'=>'https://api.github.com/repos/pl0n3r/./issues',
            'method'=>'POST', 'type'=>'issue.create',
        ],
        'dot_workflow_parent' => [
            'url'=>'https://api.github.com/repos/pl0n3r/ControlBot/actions/workflows/../dispatches',
            'method'=>'POST', 'type'=>'workflow.dispatch',
        ],
        'dot_workflow_self' => [
            'url'=>'https://api.github.com/repos/pl0n3r/ControlBot/actions/workflows/./dispatches',
            'method'=>'POST', 'type'=>'workflow.dispatch',
        ],
    ];
    foreach ($cases as $key=>$change) {
        $bad[$key] = $transport->dispatch(array_replace(request(id:'intent-'.$key.'-001'), $change));
    }
    $out = ['receipts'=>$bad, 'sender_calls'=>$calls, 'secret_calls'=>$secrets];
} elseif ($scenario === 'closed') {
    $calls=0;
    $transport = new GitHubHttpTransport(
        static function ($method, $url, $payload, $token, $guards) use (&$calls) {
            ++$calls;
            return match($calls) {
                1 => response(200, '{}', false),
                2 => response(302, '', true),
                3 => response(200, str_repeat('x', 1025)),
                default => response(200, '{}', true, 1),
            };
        },
        static fn()=>FAKE_SECRET,
        1024,
    );
    $out = [
        'tls'=>$transport->dispatch(request(id:'intent-test-tls-0001')),
        'redirect'=>$transport->dispatch(request(id:'intent-test-redirect-0001')),
        'oversize'=>$transport->dispatch(request(id:'intent-test-oversize-0001')),
        'redirect_count'=>$transport->dispatch(request(id:'intent-test-follow-0001')),
        'calls'=>$calls,
    ];
} elseif ($scenario === 'secret') {
    $calls = 0;
    $transport = new GitHubHttpTransport(
        static function () use (&$calls) { ++$calls; throw new RuntimeException('debug '.FAKE_SECRET); },
        static fn()=>FAKE_SECRET,
    );
    $req = request();
    $first = $transport->dispatch($req);
    $again = $transport->dispatch($req);
    $other = $req; $other['body'] = ['title'=>'changed'];
    $conflict = $transport->dispatch($other);
    $badProvider = new GitHubHttpTransport(static fn()=>response(), static function () { throw new RuntimeException(FAKE_SECRET); });
    $out = ['first'=>$first, 'again'=>$again, 'conflict'=>$conflict,
        'bad_provider'=>$badProvider->dispatch(request(id:'intent-provider-0001')),
        'calls'=>$calls];
    $sameRoute = new GitHubHttpTransport(static fn()=>response(), static fn()=>FAKE_SECRET);
    $firstType = request('/issues/42/comments', 'POST', 'issue.reserve', 'intent-type-change-0001');
    $sameRoute->dispatch($firstType);
    $secondType = $firstType; $secondType['type'] = 'issue.release';
    $out['type_conflict'] = $sameRoute->dispatch($secondType);
    // Replaying the same route/payload under another identity must not return
    // a receipt belonging to a different project or intent.
    $identityCalls = 0; $identitySecrets = 0;
    $identityTransport = new GitHubHttpTransport(
        static function () use (&$identityCalls) { ++$identityCalls; return response(); },
        static function () use (&$identitySecrets) { ++$identitySecrets; return FAKE_SECRET; },
    );
    $original = request(id:'intent-identity-0001');
    $identityTransport->dispatch($original);
    $anotherProject = $original; $anotherProject['project_id'] = 'different-project';
    $out['project_conflict'] = $identityTransport->dispatch($anotherProject);
    $anotherIntent = $original; $anotherIntent['intent_id'] = 'intent-identity-0002';
    $out['intent_conflict'] = $identityTransport->dispatch($anotherIntent);
    $out['identity_calls'] = $identityCalls;
    $out['identity_secrets'] = $identitySecrets;
} elseif ($scenario === 'reentrant') {
    $calls = 0; $secrets = 0; $nested = null;
    $req = request(id:'intent-reentrant-0001');
    $transport = null;
    $transport = new GitHubHttpTransport(
        static function ($method, $url, $payload, $token, $guards) use (&$transport, &$calls, &$nested, $req) {
            ++$calls;
            // The nested dispatch is identical and must not call this sender.
            $nested = $transport->dispatch($req);
            return response(200, '{"ok":true}');
        },
        static function () use (&$secrets) { ++$secrets; return FAKE_SECRET; },
    );
    $first = $transport->dispatch($req);
    $again = $transport->dispatch($req);
    $out = ['first'=>$first, 'again'=>$again, 'nested'=>$nested,
        'sender_calls'=>$calls, 'secret_calls'=>$secrets];
} elseif ($scenario === 'local') {
    $calls = 0; $observed = [];
    $transport = new GitHubHttpTransport(
        static function ($method,$url,$payload,$token,$guards) use (&$calls,&$observed) {
            ++$calls;
            $observed = ['method'=>$method, 'url'=>$url, 'guards'=>$guards,
                'token_is_fake'=>$token === FAKE_SECRET];
            return response(201, '{"number":42}');
        },
        static fn()=>FAKE_SECRET,
    );
    $req=request('/issues','POST','issue.create');
    $first=$transport->dispatch($req);
    $again=$transport->dispatch($req);
    $out=['first'=>$first, 'again'=>$again, 'calls'=>$calls, 'observed'=>$observed];
} else { fwrite(STDERR, "unknown scenario\n"); exit(2); }
echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
