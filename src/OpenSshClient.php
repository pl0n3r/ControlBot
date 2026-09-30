<?php
declare(strict_types=1);
namespace ControlBot\Production;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class OpenSshClient
{
    private const FIELDS=['version','operation_id','capability','effect','timeout_ms','host','port','expected_fingerprint','secret_kind','project','environment','resource','run_id','username'];
    private const KEY_TYPES=['ssh-ed25519','ecdsa-sha2-nistp256','ecdsa-sha2-nistp384','ecdsa-sha2-nistp521','ssh-rsa'];
    private readonly Closure $runner;
    private readonly Closure $unlinker;
    private readonly string $tempDir;

    public function __construct(?callable $runner=null, ?callable $unlinker=null, ?string $tempDir=null)
    {
        $this->runner=$runner===null ? static fn(array $argv,int $timeout):array=>self::nativeRun($argv,$timeout) : Closure::fromCallable($runner);
        $this->unlinker=$unlinker===null ? static fn(string $path):bool=>unlink($path) : Closure::fromCallable($unlinker);
        $dir=realpath($tempDir??sys_get_temp_dir());
        if ($dir===false || !is_dir($dir) || !is_writable($dir)) throw new InvalidArgumentException('Directorio temporal SSH inválido.');
        $this->tempDir=$dir;
    }

    public function __invoke(array $request,string $secret): array
    {
        try { $r=self::request($request); } catch (InvalidArgumentException) { return self::result('failed','ssh_request_invalid','SSH request rejected.'); }
        if ($r['operation_id']!=='ssh.readonly' || $r['capability']!=='ssh.readonly' || $r['effect']!=='read') return self::result('failed','ssh_operation_unsupported','SSH operation unsupported.');
        if ($r['secret_kind']!=='private_key') return self::result('failed','ssh_secret_kind_unsupported','SSH authentication type unsupported.');
        if (!self::privateKey($secret)) return self::result('failed','ssh_private_key_invalid','SSH private key rejected.');

        $probeMs=min(3000,max(500,intdiv($r['timeout_ms'],3)));
        try { $scan=$this->run(self::keyscanArgv($r,$probeMs),$probeMs); }
        catch (Throwable) { return self::result('failed','ssh_host_identity_probe_failed','SSH host identity could not be verified.'); }
        $elapsed=$scan['duration_ms'];
        if ($scan['timed_out']) return self::result('timed_out','ssh_host_identity_timeout','SSH host identity probe timed out.',$elapsed);
        if ($scan['exit_code']!==0) return self::result('failed','ssh_host_identity_unavailable','SSH host identity unavailable.',$elapsed);
        $hostKey=self::hostKey($scan['stdout'],$r);
        if ($hostKey['state']!=='match') return self::result('failed',$hostKey['state']==='mismatch'?'ssh_host_key_mismatch':'ssh_host_key_invalid','SSH host identity rejected.',$elapsed);
        $remaining=$r['timeout_ms']-$elapsed;
        if ($remaining<1) return self::result('timed_out','ssh_timeout','SSH read-only probe timed out.',$elapsed);

        $keyPath=$knownPath=null;
        $result=self::result('failed','ssh_process_failed','SSH read-only probe failed.',$elapsed);
        try {
            $keyPath=$this->temp('cb-key-',$secret);
            $knownPath=$this->temp('cb-host-',$hostKey['line']."
");
            $ssh=$this->run(self::sshArgv($r,$keyPath,$knownPath,$remaining),$remaining);
            $elapsed+=$ssh['duration_ms'];
            $result=$ssh['timed_out'] || $elapsed>$r['timeout_ms']
                ? self::result('timed_out','ssh_timeout','SSH read-only probe timed out.',$elapsed)
                : ($ssh['exit_code']===0
                    ? self::result('success','ssh_readonly_probe_ok','SSH read-only probe succeeded.',$elapsed)
                    : self::result('failed','ssh_probe_failed','SSH read-only probe failed.',$elapsed));
        } catch (Throwable) {
            $result=self::result('failed','ssh_process_failed','SSH read-only probe failed.',$elapsed);
        } finally {
            $knownOk=$this->cleanup($knownPath); $keyOk=$this->cleanup($keyPath);
            if (!$knownOk || !$keyOk) $result=self::result('failed','ssh_cleanup_failed','SSH temporary material cleanup failed.',$elapsed);
        }
        return $result;
    }

    private static function request(array $r): array
    {
        if (array_is_list($r) || count($r)!==count(self::FIELDS) || array_diff(self::FIELDS,array_keys($r))!==[] || array_diff(array_keys($r),self::FIELDS)!==[]) throw new InvalidArgumentException('fields');
        foreach (['operation_id','capability','effect','host','expected_fingerprint','secret_kind','project','environment','resource','run_id','username'] as $k)
            if (!is_string($r[$k]) || $r[$k]==='' || strlen($r[$k])>180) throw new InvalidArgumentException($k);
        if ($r['version']!==1 || !is_int($r['port']) || $r['port']<1 || $r['port']>65535 || !is_int($r['timeout_ms']) || $r['timeout_ms']<500 || $r['timeout_ms']>30000) throw new InvalidArgumentException('range');
        if (!self::host($r['host']) || preg_match('/^[A-Za-z_][A-Za-z0-9._-]{0,63}$/D',$r['username'])!==1
            || preg_match('/^SHA256:[A-Za-z0-9+\/]{16,86}={0,2}$/D',$r['expected_fingerprint'])!==1
            || preg_match('/^[a-z][a-z0-9-]{1,63}$/D',$r['project'])!==1
            || preg_match('/^[a-z][a-z0-9-]{1,31}$/D',$r['environment'])!==1
            || preg_match('/^[a-z][a-z0-9._:-]{1,119}$/D',$r['resource'])!==1
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD',$r['run_id'])!==1) throw new InvalidArgumentException('context');
        return $r;
    }

    private static function host(string $host): bool
    {
        if (filter_var($host,FILTER_VALIDATE_IP)!==false) return true;
        if (strlen($host)>253 || str_contains($host,'..')) return false;
        foreach (explode('.',$host) as $label) if ($label==='' || strlen($label)>63 || preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/D',$label)!==1) return false;
        return true;
    }

    private static function privateKey(string $secret): bool
    {
        return strlen($secret)>=64 && strlen($secret)<=32768 && !str_contains($secret,"\0")
            && preg_match('/\A-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----\R.+\R-----END [A-Z0-9 ]*PRIVATE KEY-----\R?\z/s',$secret)===1;
    }

    private static function keyscanArgv(array $r,int $timeout): array
    {
        return ['ssh-keyscan','-T',(string)max(1,(int)ceil($timeout/1000)),'-p',(string)$r['port'],$r['host']];
    }

    private static function hostKey(string $stdout,array $r): array
    {
        $valid=false; $targets=[$r['host'],'['.$r['host'].']:'.$r['port']];
        foreach (preg_split('/\R/',$stdout)?:[] as $line) {
            $parts=preg_split('/\s+/',trim($line));
            if (count($parts)!==3 || !in_array($parts[0],$targets,true) || !in_array($parts[1],self::KEY_TYPES,true)) continue;
            $blob=base64_decode($parts[2],true); if ($blob===false || $blob==='') continue;
            $valid=true; $fp='SHA256:'.rtrim(base64_encode(hash('sha256',$blob,true)),'=');
            if (hash_equals($r['expected_fingerprint'],$fp)) return ['state'=>'match','line'=>trim($line)];
        }
        return ['state'=>$valid?'mismatch':'invalid','line'=>null];
    }

    private static function sshArgv(array $r,string $key,string $known,int $timeout): array
    {
        $seconds=(string)max(1,(int)ceil($timeout/1000));
        return ['ssh','-F','/dev/null','-p',(string)$r['port'],'-i',$key,
            '-o','BatchMode=yes','-o','IdentitiesOnly=yes','-o','StrictHostKeyChecking=yes',
            '-o','UserKnownHostsFile='.$known,'-o','GlobalKnownHostsFile=/dev/null',
            '-o','PasswordAuthentication=no','-o','KbdInteractiveAuthentication=no',
            '-o','NumberOfPasswordPrompts=0','-o','ClearAllForwardings=yes',
            '-o','ProxyCommand=none','-o','PermitLocalCommand=no','-o','RequestTTY=no',
            '-o','ConnectTimeout='.$seconds,$r['username'].'@'.$r['host'],'true'];
    }

    private function temp(string $prefix,string $content): string
    {
        $path=tempnam($this->tempDir,$prefix);
        if ($path===false || !chmod($path,0600) || file_put_contents($path,$content,LOCK_EX)===false || (fileperms($path)&0777)!==0600) {
            if (is_string($path) && file_exists($path)) @unlink($path);
            throw new RuntimeException('Temporary SSH material rejected.');
        }
        return $path;
    }

    private function cleanup(?string $path): bool
    {
        if ($path===null || !file_exists($path)) return true;
        try { $ok=($this->unlinker)($path); } catch (Throwable) { return false; }
        return $ok===true && !file_exists($path);
    }

    private function run(array $argv,int $timeout): array
    {
        $raw=($this->runner)($argv,$timeout);
        $fields=['exit_code','stdout','stderr','duration_ms','timed_out'];
        if (!is_array($raw) || array_diff($fields,array_keys($raw))!==[] || !is_int($raw['exit_code']) || !is_string($raw['stdout']) || !is_string($raw['stderr'])
            || !is_int($raw['duration_ms']) || $raw['duration_ms']<0 || !is_bool($raw['timed_out'])) throw new RuntimeException('Process result invalid.');
        return $raw;
    }

    private static function nativeRun(array $argv,int $timeout): array
    {
        $start=microtime(true); $pipes=[]; $proc=proc_open($argv,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
        if (!is_resource($proc)) throw new RuntimeException('Process unavailable.');
        fclose($pipes[0]); stream_set_blocking($pipes[1],false); stream_set_blocking($pipes[2],false);
        $out=$err=''; $timed=false; $last=null;
        do {
            $out.=stream_get_contents($pipes[1]); $err.=stream_get_contents($pipes[2]);
            $out=substr($out,0,32768); $err=substr($err,0,32768); $last=proc_get_status($proc);
            if (!$last['running']) break;
            if ((microtime(true)-$start)*1000>=$timeout) { $timed=true; proc_terminate($proc); break; }
            usleep(10000);
        } while (true);
        $out.=stream_get_contents($pipes[1]); $err.=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $closed=proc_close($proc); $exit=is_array($last) && ($last['exitcode']??-1)>=0 ? $last['exitcode'] : $closed;
        return ['exit_code'=>$exit,'stdout'=>substr($out,0,32768),'stderr'=>substr($err,0,32768),'duration_ms'=>(int)round((microtime(true)-$start)*1000),'timed_out'=>$timed];
    }

    private static function result(string $status,string $code,string $summary,int $duration=0): array
    {
        return ['status'=>$status,'code'=>$code,'summary'=>$summary,'artifacts'=>[],'duration_ms'=>max(0,$duration)];
    }
}
