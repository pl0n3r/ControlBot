<?php
declare(strict_types=1);
require __DIR__.'/../src/OpenSshClient.php';
use ControlBot\Production\OpenSshClient;

const HOST='example.internal';
const BLOB='host-key-fixture-532';
function secret(): string { return "-----BEGIN OPENSSH PRIVATE KEY-----\n".str_repeat('A',80)."\n-----END OPENSSH PRIVATE KEY-----\n"; }
function fp(string $blob): string { return 'SHA256:'.rtrim(base64_encode(hash('sha256',$blob,true)),'='); }
function request(array $x=[]): array { return array_replace([
    'version'=>1,'operation_id'=>'ssh.readonly','capability'=>'ssh.readonly','effect'=>'read','timeout_ms'=>8000,
    'host'=>HOST,'port'=>22,'expected_fingerprint'=>fp(BLOB),'secret_kind'=>'private_key','project'=>'brvtal',
    'environment'=>'production','resource'=>'database:primary','run_id'=>'bbbbbbbb-cccc-4ddd-8eee-ffffffffffff','username'=>'deploy_user',
],$x); }
function processResult(int $code=0,string $out='',bool $timed=false,int $ms=5): array {
    return ['exit_code'=>$code,'stdout'=>$out,'stderr'=>'sensitive stderr','duration_ms'=>$ms,'timed_out'=>$timed];
}
function invoke(string $mode,array $req=[],?string $privateKey=null): array {
    $calls=[]; $paths=[]; $modes=[]; $keyFinalNewline=null; $knownLine=null;
    $runner=static function(array $argv,int $timeout) use (&$calls,&$paths,&$modes,&$keyFinalNewline,&$knownLine,$mode): array {
        $calls[]=$argv;
        if ($argv[0]==='ssh-keyscan') {
            if ($mode==='scan_timeout') return processResult(1,'',true);
            if ($mode==='scan_fail') return processResult(1);
            $blob=$mode==='mismatch' ? 'other-host-key' : BLOB;
            $host=(string)$argv[array_key_last($argv)]; $portIndex=array_search('-p',$argv,true);
            $port=is_int($portIndex) ? (int)($argv[$portIndex+1]??22) : 22;
            $target=$port===22 ? $host : '['.$host.']:'.$port;
            return processResult(0,$target.' ssh-ed25519 '.base64_encode($blob)."\n");
        }
        $key=$argv[array_search('-i',$argv,true)+1]; $known=null;
        foreach ($argv as $arg) if (str_starts_with($arg,'UserKnownHostsFile=')) $known=substr($arg,19);
        $paths=[$key,$known]; $modes=[fileperms($key)&0777,fileperms($known)&0777];
        $keyFinalNewline=str_ends_with((string)file_get_contents($key),"\n");
        $knownLine=trim((string)file_get_contents($known));
        if ($mode==='ssh_timeout') return processResult(1,'leak='.secret(),true,20);
        if ($mode==='ssh_fail') return processResult(255,'leak='.secret(),false,20);
        return processResult(0,'leak='.secret(),false,20);
    };
    $unlinker=$mode==='cleanup_fail' ? static function(string $path): bool { @unlink($path); return false; } : null;
    $client=new OpenSshClient($runner,$unlinker,sys_get_temp_dir());
    $result=$client(request($req),$privateKey??secret());
    return ['result'=>$result,'calls'=>$calls,'paths'=>$paths,'modes'=>$modes,'paths_exist'=>array_map('file_exists',$paths),
        'key_final_newline'=>$keyFinalNewline,'known_line'=>$knownLine];
}

$name=$argv[1]??'';
if ($name==='success') $out=invoke('success');
elseif ($name==='review_regressions') $out=['ipv6'=>invoke('success',['host'=>'::1']),'no_newline'=>invoke('success',[],rtrim(secret(),"\n"))];
elseif ($name==='host_failures') $out=['mismatch'=>invoke('mismatch'),'unavailable'=>invoke('scan_fail'),'timeout'=>invoke('scan_timeout')];
elseif ($name==='invalid') {
    $cases=['extra'=>request()+['command'=>'id'],'operation'=>request(['operation_id'=>'ssh.command']),
        'secret_kind'=>request(['secret_kind'=>'password']),'host'=>request(['host'=>'bad host']),
        'username'=>request(['username'=>'bad user']),'fingerprint'=>request(['expected_fingerprint'=>'SHA256:short'])];
    $out=[]; foreach ($cases as $k=>$req) $out[$k]=invoke('success',$req);
    $bad=new OpenSshClient(static fn(array $a,int $t):array=>processResult());
    $out['private_key']=$bad(request(),'not-a-private-key');
} elseif ($name==='process_failures') $out=['failure'=>invoke('ssh_fail'),'timeout'=>invoke('ssh_timeout'),'cleanup'=>invoke('cleanup_fail')];
else exit(2);
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
