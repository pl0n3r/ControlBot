<?php
declare(strict_types=1);
require __DIR__.'/../src/RemoteWorkspaceBroker.php';
require __DIR__.'/../src/SshConnectionWorkspaceResolver.php';
use ControlBot\Production\{RemoteWorkspaceBroker,SshConnectionWorkspaceResolver};

const REF='workspace:brvtal:prod';
const PATH='/srv/apps/brvtal/current';
function meta(array $x=[]): array {return array_replace([
 'version'=>1,'workspace_ref'=>REF,'provider'=>'hostinger','project'=>'brvtal',
 'environment'=>'production','generation'=>1,'issued_at'=>'2027-01-15T07:00:00Z','revoked_at'=>null],$x);}
function input(array $x=[]): array {return array_replace([
 'workspace_ref'=>REF,'provider'=>'hostinger','project'=>'brvtal','environment'=>'production'],$x);}
function broker(): RemoteWorkspaceBroker {$b=new RemoteWorkspaceBroker(['hostinger-executor']);$b->register(meta(),PATH);return $b;}
function resolver(RemoteWorkspaceBroker $b): SshConnectionWorkspaceResolver {return new SshConnectionWorkspaceResolver($b);}
function run(SshConnectionWorkspaceResolver $r,array $in,?callable $c=null): array {
 return $r->resolve($in,$c??static fn(string $path):array=>['seen'=>$path]);
}
$s=$argv[1]??'';
if($s==='valid'){
 $b=broker();$seen=null;$out=run(resolver($b),input(),static function(string $path)use(&$seen){$seen=$path;return ['message'=>'used '.$path];});
 echo json_encode(['out'=>$out,'seen'=>$seen],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
if($s==='context'){
 $b=broker();$calls=0;$r=resolver($b);$c=static function()use(&$calls){$calls++;return 'x';};
 $cases=[
  'generation'=>input()+['generation'=>1],
  'provider'=>input(['provider'=>'other']),
  'project'=>input(['project'=>'condor']),
  'environment'=>input(['environment'=>'staging']),
 ];
 $out=[];foreach($cases as $k=>$v)$out[$k]=run($r,$v,$c);
 echo json_encode(['out'=>$out,'calls'=>$calls],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($s==='denies'){
 $calls=0;$c=static function()use(&$calls){$calls++;return 'x';};
 $unknown=run(resolver(broker()),input(['workspace_ref'=>'workspace:missing']),$c);
 $rev=broker();$rev->revoke(REF,'2027-01-15T08:00:00Z');$revoked=run(resolver($rev),input(),$c);
 $scope=run(resolver(broker()),input(['project'=>'condor']),$c);
 echo json_encode(['unknown'=>$unknown,'revoked'=>$revoked,'scope'=>$scope,'calls'=>$calls],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($s==='drift'){
 $b=broker();$r=resolver($b);$out=run($r,input(),static function(string $path)use($b){
  $b->rotate(REF,'workspace:brvtal:prod:v2','2027-01-15T08:00:00Z','/srv/apps/brvtal/releases/2');
  return ['ok'=>true,'path_seen'=>$path];
 });
 echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
if($s==='redaction'){
 $b=broker();$r=resolver($b);
 $ok=run($r,input(),static fn(string $path):array=>['message'=>'workspace='.$path]);
 $fail=run($r,input(),static function(string $path):never{throw new RuntimeException('failed at '.$path);});
 echo json_encode(['ok'=>$ok,'fail'=>$fail],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
exit(2);
