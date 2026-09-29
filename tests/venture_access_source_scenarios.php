<?php
declare(strict_types=1);
require __DIR__.'/../src/Approvals.php';
require __DIR__.'/../src/OwnerSession.php';
require __DIR__.'/../src/GitHub.php';
require __DIR__.'/../src/ApprovalEndpoint.php';
require __DIR__.'/../src/GateInbox.php';
require __DIR__.'/../src/DecisionBatch.php';
require __DIR__.'/../src/DecisionUi.php';
require __DIR__.'/../src/DecisionHistory.php';
require __DIR__.'/../src/DecisionQuestions.php';
require __DIR__.'/../src/VentureIdentity.php';
require __DIR__.'/../src/DecisionRights.php';
require __DIR__.'/../src/IdentityCenter.php';
require __DIR__.'/../src/VentureAccessRuntime.php';
require __DIR__.'/../src/VentureAccessSource.php';
require __DIR__.'/../src/DecisionRuntime.php';

use ControlBot\Approvals\AppendOnlyAuditLog;
use ControlBot\Approvals\ApprovalEndpoint;
use ControlBot\Business\VentureAccessSource;
use ControlBot\Decisions\DecisionRuntime;
use ControlBot\Security\OwnerSessionService;
use ControlBot\Security\TokenVault;

const NOW=5000, POLICY='controlbot:policy/business-os-v1';
final class FakeAccessSource implements VentureAccessSource {
    public int $calls=0;
    public function __construct(private array $row,private string $mode='ok'){}
    public function resolve(string $identityId,string $scope,string $capability,int $now): array {
        $this->calls++;
        if($this->mode==='missing') throw new RuntimeException('missing');
        $row=$this->row;
        if($this->mode==='capability') $row['grant']['capability']='config.write';
        if($this->mode==='policy') $row['active_policy_refs']=['controlbot:policy/other'];
        return $row;
    }
}
function ident(string $name='ADMIN'):array{return ['version'=>1,'identity_id'=>'identity-admin','kind'=>'human','display_name'=>$name,'state'=>'active','source_ref'=>'controlbot:identity/identity-admin','observed_at'=>4900];}
function grant():array{return ['version'=>1,'grant_id'=>'grant-infra','identity_id'=>'identity-admin','role'=>'venture_admin','capability'=>'hostinger.read','scope'=>'venture:alpha','authority_level'=>'L2_VENTURE_ADMIN','policy_ref'=>POLICY,'budget_limit'=>null,'granted_at'=>4800,'expires_at'=>6000];}
function row(string $name='ADMIN'):array{return ['identity'=>ident($name),'scope'=>'venture:alpha','active_policy_refs'=>[POLICY],'grant'=>grant()];}
function query(array $extra=[]):array{return ['identity_id'=>'identity-admin','scope'=>'venture:alpha','capability'=>'hostinger.read']+$extra;}
function blocked(callable $f):bool{try{$f();return false;}catch(Throwable){return true;}}
function runtime(?VentureAccessSource $source):array{
    $vault=new TokenVault(base64_encode(str_repeat('K',SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    $sessions=new OwnerSessionService('pl0n3r',$vault); $session=[];
    $sessions->establishTrustedOAuthSession($session,'pl0n3r','server-token');
    $auditPath=tempnam(sys_get_temp_dir(),'source-'); $audit=new AppendOnlyAuditLog($auditPath);
    $factory=static fn(string $token):array=>throw new RuntimeException('network not expected');
    $runtime=new DecisionRuntime($sessions,new ApprovalEndpoint($sessions,$audit,$factory),$audit,$factory,['pl0n3r/factory'],null,$source);
    return [$runtime,$session,$auditPath];
}
$case=$argv[1]??'';
if($case==='request'){
    $source=new FakeAccessSource(row()); [$rt,$session,$path]=runtime($source);
    $out=['extra_blocked'=>blocked(fn()=> $rt->resolveVentureAccess($session,'pl0n3r/factory',query(['grant'=>grant()]),NOW)),'calls'=>$source->calls];
}elseif($case==='resolve'){
    $source=new FakeAccessSource(row()); [$rt,$session,$path]=runtime($source);
    $out=['resolved'=>$rt->resolveVentureAccess($session,'pl0n3r/factory',query(),NOW),'calls'=>$source->calls];
}elseif($case==='mismatch'){
    $source=new FakeAccessSource(row(),'capability'); [$rt,$session,$path]=runtime($source);
    $a=blocked(fn()=> $rt->resolveVentureAccess($session,'pl0n3r/factory',query(),NOW));
    $source2=new FakeAccessSource(row(),'policy'); [$rt2,$session2,$path2]=runtime($source2);
    $b=blocked(fn()=> $rt2->resolveVentureAccess($session2,'pl0n3r/factory',query(),NOW));
    [$rt3,$session3,$path3]=runtime(null);
    $c=blocked(fn()=> $rt3->resolveVentureAccess($session3,'pl0n3r/factory',query(),NOW));
    $out=['capability'=>$a,'policy'=>$b,'missing'=>$c]; @unlink($path2); @unlink($path3);
}elseif($case==='composition'){
    $source=new FakeAccessSource(row()); [$rt,$session,$path]=runtime($source);
    $request=query(); $resolved=$rt->resolveVentureAccess($session,'pl0n3r/factory',$request,NOW);
    $out=['request_keys'=>array_keys($request),'calls'=>$source->calls,'grant_id'=>$resolved['grant']['grant_id']];
}elseif($case==='readonly'){
    $base=row(); $source=new FakeAccessSource($base); [$rt,$session,$path]=runtime($source);
    $resolved=$rt->resolveVentureAccess($session,'pl0n3r/factory',query(),NOW);
    $out=['source_unchanged'=>$base===row(),'output_keys'=>array_keys($resolved),'has_runtime_state'=>isset($resolved['audit'])||isset($resolved['state'])];
}elseif($case==='secret'){
    $source=new FakeAccessSource(row('Bearer abcdefghijklmnop')); [$rt,$session,$path]=runtime($source);
    $out=['blocked'=>blocked(fn()=> $rt->resolveVentureAccess($session,'pl0n3r/factory',query(),NOW))];
}else{fwrite(STDERR,"scenario invalid\n");exit(2);}
@unlink($path);
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
