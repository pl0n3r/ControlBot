<?php
declare(strict_types=1);

require __DIR__.'/../src/VentureIdentity.php';
require __DIR__.'/../src/VentureAccessSource.php';
require __DIR__.'/../src/DecisionRights.php';
require __DIR__.'/../src/VerifiedAccessContext.php';

use ControlBot\Business\DecisionRights;
use ControlBot\Business\VerifiedAccessContext;
use ControlBot\Business\VentureAccessRuntime;
use ControlBot\Business\VentureAccessSource;

const NOW=5000;
const SCOPE='venture:alpha';
const POLICY='controlbot:policy/business-os-v1';

final class FixtureSource implements VentureAccessSource
{
    public array $calls=[];
    public function __construct(private array $row) {}
    public function resolve(string $identityId,string $scope,string $capability,int $now): array
    {
        $this->calls[]=compact('identityId','scope','capability','now');
        return $this->row;
    }
}

function identity(string $name='ADMIN'): array {
    return [
        'version'=>1,'identity_id'=>'identity-admin','kind'=>'human','display_name'=>$name,
        'state'=>'active','source_ref'=>'controlbot:identity/identity-admin','observed_at'=>4900,
    ];
}
function grant(string $cap='hostinger.read',string $level='L2_VENTURE_ADMIN',?int $expires=6000): array {
    return [
        'version'=>1,'grant_id'=>'grant-infra','identity_id'=>'identity-admin',
        'role'=>$level==='L1_OPERATOR'?'operator':'venture_admin','capability'=>$cap,
        'scope'=>SCOPE,'authority_level'=>$level,'policy_ref'=>POLICY,'budget_limit'=>null,
        'granted_at'=>4800,'expires_at'=>$expires,
    ];
}
function snapshot(): array {
    return ['identity'=>identity(),'scope'=>SCOPE,'active_policy_refs'=>[POLICY],'grant'=>grant()];
}
function query(string $cap='hostinger.read'): array {
    return ['identity_id'=>'identity-admin','scope'=>SCOPE,'capability'=>$cap];
}
function blocked(callable $fn): bool {
    try { $fn(); return false; } catch (Throwable) { return true; }
}
function issued(array $row=null,string $cap='hostinger.read'): array {
    $source=new FixtureSource($row??snapshot());
    $context=VerifiedAccessContext::fromServerSource($source,query($cap),NOW);
    return [$context,$source];
}

$case=$argv[1]??'';
if($case==='structural'){
    $reflection=new ReflectionClass(VerifiedAccessContext::class);
    $raw=snapshot();
    echo json_encode([
        'constructor_private'=>$reflection->getConstructor()?->isPrivate()===true,
        'legacy_raw_factory_absent'=>!method_exists(VerifiedAccessContext::class,'fromServerSnapshot'),
        'array_is_context'=>$raw instanceof VerifiedAccessContext,
        'object_is_context'=>(object)$raw instanceof VerifiedAccessContext,
        'raw_as_source_blocked'=>blocked(fn()=>VerifiedAccessContext::fromServerSource($raw,query(),NOW)),
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='server'){
    [$context,$source]=issued();
    echo json_encode([
        'summary'=>$context->safeSummary(),
        'source_call'=>$source->calls[0],
        'decision'=>DecisionRights::evaluate(
            $context->decisionContext(),$context->grant(),
            ['capability'=>'hostinger.read','required_authority_level'=>'L2_VENTURE_ADMIN'],NOW
        ),
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='copy'){
    [$context]=issued();
    $shape=(object)['decisionContext'=>$context->decisionContext(),'grant'=>$context->grant()];
    echo json_encode([
        'shape_is_context'=>$shape instanceof VerifiedAccessContext,
        'clone_blocked'=>blocked(fn()=>clone $context),
        'serialize_blocked'=>blocked(fn()=>serialize($context)),
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='readonly'){
    $raw=snapshot(); $before=$raw; [$context,$source]=issued($raw);
    echo json_encode([
        'unchanged'=>$raw===$before,'source_calls'=>count($source->calls),
        'has_audit'=>array_key_exists('audit',$context->decisionContext()),
        'has_top_level_state'=>array_key_exists('state',$context->decisionContext()),
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='secrets'){
    $bad=snapshot(); $bad['identity']['display_name']='Bearer abcdefghijklmnop';
    echo json_encode([
        'sensitive_blocked'=>blocked(fn()=>issued($bad)),
        'normal_allowed'=>!blocked(fn()=>issued()),
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='decisions'){
    [$allow]=issued();
    $ownerRow=snapshot(); $ownerRow['grant']=grant('hostinger.read','L1_OPERATOR');
    [$owner]=issued($ownerRow);
    echo json_encode([
        'allow'=>DecisionRights::evaluate($allow->decisionContext(),$allow->grant(),
            ['capability'=>'hostinger.read','required_authority_level'=>'L2_VENTURE_ADMIN'],NOW),
        'owner'=>DecisionRights::evaluate($owner->decisionContext(),$owner->grant(),
            ['capability'=>'hostinger.read','required_authority_level'=>'L2_VENTURE_ADMIN'],NOW),
        'deny'=>DecisionRights::evaluate($allow->decisionContext(),$allow->grant(),
            ['capability'=>'config.write','required_authority_level'=>'L2_VENTURE_ADMIN'],NOW),
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
fwrite(STDERR,"scenario invalid\n"); exit(2);
