<?php
declare(strict_types=1);
require __DIR__ . '/../src/FactoryOrchestratorLiveCache.php';
use ControlBot\Business\FactoryOrchestratorLiveCache;
function view(int $now = 220): array
{
    return seal(['version'=>1,'observed_at'=>$now,'source_snapshot'=>str_repeat('a',64),'read_only'=>true,
        'central'=>['activity_state'=>'UNKNOWN','available'=>null,'reserved'=>null,'blocked'=>null,
            'source_ref'=>null,'observed_at'=>null,'freshness'=>'unknown','age_seconds'=>null],
        'fronts'=>[],'owner_decisions'=>[]]);
}
function seal(array $body): array
{
    unset($body['fingerprint']); return $body + ['fingerprint'=>hash('sha256',json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES))];
}
function temporaryCache(): array
{
    $dir = sys_get_temp_dir() . '/cb769-' . bin2hex(random_bytes(5));
    if (!mkdir($dir, 0700)) throw new RuntimeException('test temp directory unavailable');
    return [$dir, $dir . '/cache.json'];
}
function clean(string $dir): void { foreach (glob($dir . '/*') ?: [] as $path) @unlink($path); @rmdir($dir); }
function failClosed(string $path, int $now): bool
{
    try { FactoryOrchestratorLiveCache::remember($path, $now, 10, 60, 20, static function (): array { throw new RuntimeException('source down'); }); }
    catch (RuntimeException $e) { return $e->getMessage() === 'snapshot unavailable'; }
    return false;
}
$scenario = $argv[1] ?? '';
[$dir, $path] = temporaryCache();
try {
    if ($scenario === 'corrupt-json') {
        file_put_contents($path, '{invalid-json');
        $calls = 0; $load = static function () use (&$calls): array { $calls++; return view(); }; $a = FactoryOrchestratorLiveCache::remember($path, 220, 10, 60, 20, $load);
        $b = FactoryOrchestratorLiveCache::remember($path, 222, 10, 60, 20, $load);
        echo json_encode(['statuses'=>[$a['status'],$b['status']], 'calls'=>$calls,
            'payload'=>$b['payload']['central']['activity_state'], 'persisted'=>json_decode((string) file_get_contents($path),true,32,JSON_THROW_ON_ERROR)['payload']['fingerprint']===view()['fingerprint']], JSON_THROW_ON_ERROR), PHP_EOL;
        exit;
    }
    if ($scenario === 'invalid-sources') {
        $calls = 0; $rejects = [];
        foreach (['invalid-json', 'bad-shape', 'forged-fingerprint', 'consistent-partial', 'impossible-central', 'mismatched-time', 'reversed-window'] as $case) {
            if ($case === 'invalid-json') file_put_contents($path, '{invalid-json');
            else {
                $payload = view();
                if ($case === 'bad-shape') $payload['version'] = 2;
                elseif ($case === 'forged-fingerprint') $payload['fingerprint'] = str_repeat('f', 64);
                else {
                    if ($case === 'consistent-partial') $payload['central'] = ['activity_state' => 'ACTIVE'];
                    elseif ($case === 'impossible-central') {
                        $payload['central'] = ['activity_state'=>'ACTIVE','available'=>0,'reserved'=>0,
                            'blocked'=>0,'source_ref'=>'synthetic','observed_at'=>220,'freshness'=>'current','age_seconds'=>0];
                    }
                    $payload = seal($payload);
                }
                $cachedAt = $case === 'mismatched-time' ? 210 : 220; $next = $case === 'reversed-window' ? 219 : 230;
                file_put_contents($path, json_encode(['cached_at' => $cachedAt, 'next_refresh_at' => $next, 'payload' => $payload], JSON_THROW_ON_ERROR));
            }
            try { FactoryOrchestratorLiveCache::remember($path, 220, 10, 60, 20,
                static function () use (&$calls): array { $calls++; throw new RuntimeException('source down'); });
                $rejects[$case] = false;
            } catch (RuntimeException $e) { $rejects[$case] = $e->getMessage() === 'snapshot unavailable'; }
        }
        $payload = view(); $payload['fingerprint'] = str_repeat('f', 64); file_put_contents($path, json_encode(['cached_at' => 220, 'next_refresh_at' => 230, 'payload' => $payload], JSON_THROW_ON_ERROR));
        $recovered = FactoryOrchestratorLiveCache::remember($path, 220, 10, 60, 20, static function () use (&$calls): array { $calls++; return view(); });
        file_put_contents($path, json_encode(['cached_at' => 225, 'next_refresh_at' => 240, 'payload' => view()], JSON_THROW_ON_ERROR));
        $future = FactoryOrchestratorLiveCache::remember($path, 220, 10, 60, 20, static function () use (&$calls): array { $calls++; return view(); });
        echo json_encode(['rejected'=>$rejects,'calls'=>$calls,'recovered'=>$recovered['status'],'future'=>$future['status'],'fingerprint'=>$recovered['payload']['fingerprint']], JSON_THROW_ON_ERROR), PHP_EOL;
        exit;
    }
    if ($scenario === 'ttl-stale') {
        $calls = 0; $load = static function () use (&$calls): array { $calls++; if ($calls > 1) throw new RuntimeException('offline'); return view(); }; $times = [220, 225, 231, 241, 242]; $rows = [];
        foreach ($times as $now) $rows[] = FactoryOrchestratorLiveCache::remember($path, $now, 10, 60, 20, $load);
        echo json_encode(['statuses'=>array_column($rows,'status'),'calls'=>$calls,'age'=>$rows[3]['age_seconds'],
            'temp'=>count(glob($dir.'/.orchestrator-*')?:[]),'read_only'=>$rows[4]['payload']['read_only']],JSON_THROW_ON_ERROR),PHP_EOL;
        exit;
    }
    if ($scenario === 'two-requests') {
        $malformed = view(); $malformed['central'] = ['activity_state' => 'ACTIVE']; $malformed = seal($malformed);
        file_put_contents($path, json_encode(['cached_at' => 210, 'next_refresh_at' => 230, 'payload' => $malformed], JSON_THROW_ON_ERROR));
        $calls = 0; $load = static function () use (&$calls): array { $calls++; return view(); };
        $first = FactoryOrchestratorLiveCache::remember($path, 220, 10, 60, 20, $load); $second = FactoryOrchestratorLiveCache::remember($path, 223, 10, 60, 20, $load);
        $front = ['id'=>'front-1','repository_ref'=>'pl0n3r/Factory','issue_ref'=>'github:pl0n3r/Factory#1',
            'status'=>'available','progress_percent'=>null,'progress_state'=>'UNKNOWN','source_ref'=>'synthetic','observed_at'=>220,'freshness'=>'current','age_seconds'=>0];
        $linked = view(); $linked['fronts'] = [$front]; unlink($path); $linkValid = FactoryOrchestratorLiveCache::remember($path, 220, 10, 60, 20, static fn (): array => seal($linked))['status'];
        $linked['fronts'][0]['issue_ref'] = 'nota-no-github'; file_put_contents($path, json_encode(['cached_at'=>220,'next_refresh_at'=>240,'payload'=>seal($linked)], JSON_THROW_ON_ERROR)); $linkRejected = failClosed($path, 221);
        echo json_encode(['statuses'=>[$first['status'],$second['status']],'calls'=>$calls,
            'activity'=>[$first['payload']['central']['activity_state'],$second['payload']['central']['activity_state']],
            'age'=>$second['age_seconds'],'link_valid'=>$linkValid,'link_rejected'=>$linkRejected], JSON_THROW_ON_ERROR), PHP_EOL; exit;
    }
    if ($scenario === 'decision-contract') {
        $body = view(); $body['central'] = ['activity_state'=>'ACTIVE','available'=>0,'reserved'=>1,'blocked'=>0,
            'source_ref'=>'synthetic','observed_at'=>220,'freshness'=>'current','age_seconds'=>0];
        $option = ['id'=>'A','label'=>'Approve','effect'=>'Apply change','pros'=>[], 'cons'=>[], 'risk'=>'low','cost'=>null,'reversible'=>true,'explain_simple'=>null];
        $d = ['id'=>'dec-1','issue_ref'=>'https://github.com/pl0n3r/Factory/issues/1','repository_ref'=>'pl0n3r/Factory',
            'issue_number'=>1,'format'=>'structured','title'=>'Decision','title_simple'=>'Decision','summary_simple'=>'Summary',
            'explain_simple'=>null,'why_recommended'=>'Safe','blocks'=>'Release','options'=>[$option],
            'recommendation'=>'A','safe_default'=>'A','expires_at'=>null,'seconds_left'=>null,'expired'=>null,
            'source_ref'=>'synthetic','observed_at'=>220,'freshness'=>'current','age_seconds'=>0];
        $body['owner_decisions'] = [$d]; $rich = seal($body);
        $first = FactoryOrchestratorLiveCache::remember($path, 220, 10, 60, 20, static fn (): array => $rich);
        $second = FactoryOrchestratorLiveCache::remember($path, 221, 10, 60, 20, static function (): array { throw new RuntimeException('should not refresh'); });
        foreach (['title_simple','summary_simple','explain_simple','why_recommended','blocks','recommendation','safe_default','expires_at','seconds_left','expired'] as $key) $d[$key] = null;
        $d['format'] = 'legacy'; $d['options'] = [];
        $body['owner_decisions'] = [$d]; unlink($path);
        $legacy = FactoryOrchestratorLiveCache::remember($path, 220, 10, 60, 20, static fn (): array => seal($body));
        $bad = $rich; $bad['owner_decisions'][0]['options'][0]['reversible'] = 'yes'; $bad = seal($bad); file_put_contents($path, json_encode(['cached_at'=>220,'next_refresh_at'=>240,'payload'=>$bad], JSON_THROW_ON_ERROR));
        $blocked = failClosed($path, 221);
        $invalidOptions = []; foreach (['pros','cons','cost','explain_simple','decision_explain','effect_length','pros_length','pros_control'] as $key) {
            $invalid = $rich;
            if ($key === 'decision_explain') $invalid['owner_decisions'][0]['explain_simple'] = 4;
            elseif ($key === 'effect_length') $invalid['owner_decisions'][0]['options'][0]['effect'] = str_repeat('x',601); elseif ($key === 'pros_length') $invalid['owner_decisions'][0]['options'][0]['pros'] = array_fill(0,7,'valid'); elseif ($key === 'pros_control') $invalid['owner_decisions'][0]['options'][0]['pros'] = ["hello\nworld"];
            else $invalid['owner_decisions'][0]['options'][0][$key] = in_array($key, ['pros','cons'], true) ? [4] : 4;
            file_put_contents($path, json_encode(['cached_at'=>220,'next_refresh_at'=>240,'payload'=>seal($invalid)], JSON_THROW_ON_ERROR)); $invalidOptions[$key] = failClosed($path, 221);
        }
        $badExpiry = $rich; $badExpiry['owner_decisions'][0]['seconds_left'] = -1; $badExpiry = seal($badExpiry); file_put_contents($path, json_encode(['cached_at'=>220,'next_refresh_at'=>240,'payload'=>$badExpiry], JSON_THROW_ON_ERROR)); $expiryBlocked = failClosed($path, 221);
        $badIssue = $rich; $badIssue['owner_decisions'][0]['issue_ref'] = 'https://github.com/pl0n3r/ControlBot/issues/9';
        file_put_contents($path, json_encode(['cached_at'=>220,'next_refresh_at'=>240,'payload'=>seal($badIssue)], JSON_THROW_ON_ERROR)); $issueBlocked = failClosed($path, 221); echo json_encode(['statuses'=>[$first['status'],$second['status'],$legacy['status']],
            'formats'=>[$first['payload']['owner_decisions'][0]['format'],$legacy['payload']['owner_decisions'][0]['format']],
            'blocked'=>$blocked,'expiry_blocked'=>$expiryBlocked,'issue_blocked'=>$issueBlocked,'invalid_options'=>$invalidOptions], JSON_THROW_ON_ERROR), PHP_EOL;
        exit;
    }
    if ($scenario === 'agent-parity') {
        $bad = view(); $bad['agent_activity'] = []; file_put_contents($path, json_encode(['cached_at'=>220,'next_refresh_at'=>240,'payload'=>seal($bad)], JSON_THROW_ON_ERROR));
        $rejected = failClosed($path, 221); $valid = view(); $valid['agent_activity'] = null; $valid = seal($valid); $fresh = FactoryOrchestratorLiveCache::remember($path, 220, 10, 60, 20, static fn (): array => $valid);
        echo json_encode(['rejected'=>$rejected, 'valid'=>$fresh['status']], JSON_THROW_ON_ERROR), PHP_EOL; exit;
    }
    if ($scenario === 'interop-766') {
        $signal = ['truncated'=>true,'omitted'=>['blockers'=>1,'owner_decisions'=>0,'work'=>0],'reason'=>'bounded_signal_budget']; $failure = ['freshness'=>'stale','reason'=>'collector_failed','last_success_at'=>210,'detected_at'=>220]; $valid = []; $invalid = [];
        foreach (['signal_summary'=>$signal,'collector_failure'=>$failure] as $key=>$value) {
            $body = view(); $body[$key] = $value; $body = seal($body); $first = FactoryOrchestratorLiveCache::remember($path, 220, 10, 60, 20, static fn (): array => $body); $hit = FactoryOrchestratorLiveCache::remember($path, 223, 10, 60, 20, static function (): array { throw new RuntimeException('unexpected refresh'); }); $valid[$key] = [$first['status'],$hit['status']];
            if ($key === 'signal_summary') $value['omitted']['blockers'] = -1; else $value['reason'] = 'unexpected';
            $body[$key] = $value; file_put_contents($path, json_encode(['cached_at'=>220,'next_refresh_at'=>240,'payload'=>seal($body)], JSON_THROW_ON_ERROR));
            $invalid[$key] = failClosed($path, 221);
            if ($key === 'collector_failure') { $other = view(); $other[$key] = $failure; $other[$key]['detected_at'] = 219;
                file_put_contents($path, json_encode(['cached_at'=>220,'next_refresh_at'=>240,'payload'=>seal($other)], JSON_THROW_ON_ERROR)); $invalid['collector_time'] = failClosed($path, 221); } unlink($path);
        }
        echo json_encode(['valid'=>$valid,'invalid'=>$invalid], JSON_THROW_ON_ERROR), PHP_EOL; exit;
    }
    fwrite(STDERR, "unknown case\n"); exit(2);
} finally {
    clean($dir);
}
