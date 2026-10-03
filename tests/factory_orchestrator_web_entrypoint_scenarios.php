<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/FactoryLiveSnapshot.php';
require_once __DIR__ . '/../src/FactoryOrchestratorWebEntrypoint.php';

use ControlBot\Business\FactoryLiveSnapshot;
use ControlBot\Business\FactoryOrchestratorWebEntrypoint;

function tmpdir(): string {
    $dir = sys_get_temp_dir() . '/cb-web-' . bin2hex(random_bytes(4));
    if (!mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('temp dir unavailable');
    return $dir;
}
function snapshot(int $now): array {
    return FactoryLiveSnapshot::build(['work' => [[
        'id'=>'work:controlbot-656','authority'=>'github_project_snapshot','state'=>'pending',
        'source_ref'=>'github:pl0n3r/ControlBot#656','observed_at'=>$now-5,'freshness'=>'current',
        'data'=>['repository_ref'=>'pl0n3r/ControlBot','issue_ref'=>'github:pl0n3r/ControlBot#656',
            'status'=>'reserved','progress_percent'=>25,'progress_evidence'=>'github:pl0n3r/ControlBot#656'],
    ]]], $now);
}
function save_snapshot(string $path, array $value): void {
    $raw=json_encode($value, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    if (file_put_contents($path,$raw)!==strlen($raw)) throw new RuntimeException('snapshot write failed');
}
function request(string $path,int $now,string $snapshot,string $cache,?string $user='pl0n3r',?string $owner='pl0n3r'): array {
    $server=['REQUEST_METHOD'=>'GET','REQUEST_URI'=>$path];
    if($user!==null)$server['REMOTE_USER']=$user;
    $env=$owner===null?[]:['CONTROLBOT_OWNER_LOGIN'=>$owner];
    return FactoryOrchestratorWebEntrypoint::handle($server,$env,$now,$snapshot,$cache);
}
function cleanup(string $dir): void {
    foreach(glob($dir.'/*')?:[] as $file) @unlink($file);
    @rmdir($dir);
}

$name=$argv[1]??'';$now=1_800_000_000;$dir=tmpdir();$result=null;
try {
    if ($name==='owner'||$name==='json') {
        $file=$dir.'/snapshot.json';save_snapshot($file,snapshot($now));
        $result=request($name==='owner'?'/':'/api/orchestrator-live?source=local',$now,$file,$dir.'/cache.json');
    } elseif ($name==='auth') {
        $file=$dir.'/snapshot.json';save_snapshot($file,snapshot($now));
        $result=[
            'missing'=>request('/',$now,$file,$dir.'/a.json',null,'pl0n3r'),
            'wrong'=>request('/',$now,$file,$dir.'/b.json','other','pl0n3r'),
            'config'=>request('/',$now,$file,$dir.'/c.json','pl0n3r',null),
        ];
    } elseif ($name==='unknown') {
        $invalid=$dir.'/invalid.json';file_put_contents($invalid,'{"observed_at":');
        $stale=$dir.'/stale.json';save_snapshot($stale,snapshot($now-301));
        $result=[
            'missing'=>request('/api/orchestrator-live',$now,$dir.'/absent.json',$dir.'/a.json'),
            'invalid'=>request('/api/orchestrator-live',$now,$invalid,$dir.'/b.json'),
            'stale'=>request('/api/orchestrator-live',$now,$stale,$dir.'/c.json'),
        ];
    } else {
        fwrite(STDERR,"scenario invalid\n");exit(2);
    }
} finally { cleanup($dir); }
echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
