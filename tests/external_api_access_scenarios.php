<?php
declare(strict_types=1);

require __DIR__.'/../src/Approvals.php';
require __DIR__.'/../src/OwnerSession.php';
require __DIR__.'/../src/GitHub.php';
require __DIR__.'/../src/ApprovalEndpoint.php';
require __DIR__.'/../src/VentureIdentity.php';
require __DIR__.'/../src/VentureAccessSource.php';
require __DIR__.'/../src/DecisionRights.php';
require __DIR__.'/../src/DecisionRuntime.php';
require __DIR__.'/../src/VerifiedAccessContext.php';
require __DIR__.'/../src/ExternalApiContract.php';
require __DIR__.'/../src/ExternalApiAccess.php';

use ControlBot\Approvals\AppendOnlyAuditLog;
use ControlBot\Business\VerifiedAccessContext;
use ControlBot\Business\VentureAccessSource;
use ControlBot\Decisions\DecisionRuntime;
use ControlBot\ExternalApi\ExternalApiAccess;
use ControlBot\Security\OwnerSessionService;
use ControlBot\Security\TokenVault;
use InvalidArgumentException;

const NOW=7000;
const SCOPE='venture:alpha';
const POLICY='controlbot:policy/external-owner-v1';

final class ExternalApiAccessFixtureSource implements VentureAccessSource
{
    public function __construct(private array $row) {}
    public function resolve(string $identityId,string $scope,string $capability,int $now): array
    {
        return $this->row;
    }
}

function identity(string $id='identity-owner'): array {
    return [
        'version'=>1,'identity_id'=>$id,'kind'=>'human','display_name'=>'Owner',
        'state'=>'active','source_ref'=>'controlbot:identity/owner','observed_at'=>6900,
    ];
}
function grant(
    string $capability,
    string $level='L4_OWNER',
    string $scope=SCOPE,
    string $identityId='identity-owner',
    string $policy=POLICY,
): array {
    return [
        'version'=>1,'grant_id'=>'grant-owner-api','identity_id'=>$identityId,
        'role'=>$level==='L4_OWNER'?'owner':'portfolio_admin',
        'capability'=>$capability,'scope'=>$scope,'authority_level'=>$level,
        'policy_ref'=>$policy,'budget_limit'=>null,'granted_at'=>6800,'expires_at'=>8000,
    ];
}
function snapshot(
    string $capability,
    string $level='L4_OWNER',
    string $scope=SCOPE,
): array {
    return [
        'identity'=>identity(),'scope'=>$scope,'active_policy_refs'=>[POLICY],
        'grant'=>grant($capability,$level,$scope),
    ];
}
function query(string $capability,string $scope=SCOPE): array {
    return ['identity_id'=>'identity-owner','scope'=>$scope,'capability'=>$capability];
}
function issued(string $capability,string $level='L4_OWNER',string $scope=SCOPE,?array $row=null): VerifiedAccessContext {
    $source=new ExternalApiAccessFixtureSource($row??snapshot($capability,$level,$scope));
    $vault=new TokenVault(base64_encode(str_repeat('A',SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    $sessions=new OwnerSessionService('pl0n3r',$vault);
    $session=[];
    $sessions->establishTrustedOAuthSession($session,'pl0n3r','fixture-server-value');
    $path=tempnam(sys_get_temp_dir(),'external-api-access-');
    $audit=new AppendOnlyAuditLog($path);
    try {
        $runtime=DecisionRuntime::fromServer($sessions,$audit,['pl0n3r/ControlBot'],$source);
        return VerifiedAccessContext::fromDecisionRuntime(
            $runtime,$session,'pl0n3r/ControlBot',query($capability,$scope),NOW
        );
    } finally {
        @unlink($path);
    }
}
function invalid(callable $fn): bool {
    try { $fn(); return false; } catch (InvalidArgumentException) { return true; }
}
function typeBlocked(callable $fn): bool {
    try { $fn(); return false; } catch (TypeError) { return true; }
}

$case=$argv[1]??'';
if($case==='nominal'){
    $raw=snapshot('owner.cockpit.read');
    echo json_encode([
        'constructor_private'=>(new ReflectionClass(VerifiedAccessContext::class))->getConstructor()?->isPrivate()===true,
        'raw_rejected'=>typeBlocked(fn()=>ExternalApiAccess::authorize(
            $raw,'GET','/api/v1/cockpit',SCOPE,NOW
        )),
        'valid'=>ExternalApiAccess::authorize(
            issued('owner.cockpit.read'),'GET','/api/v1/cockpit',SCOPE,NOW
        ),
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='owner_read'){
    $agent=snapshot('owner.cockpit.read'); $agent['identity']['kind']='agent';
    echo json_encode([
        'owner'=>ExternalApiAccess::authorize(
            issued('owner.cockpit.read'),'GET','/api/v1/cockpit',SCOPE,NOW
        ),
        'lower'=>ExternalApiAccess::authorize(
            issued('owner.cockpit.read','L3_GROUP_INSTITUTION'),'GET','/api/v1/cockpit',SCOPE,NOW
        ),
        'agent'=>ExternalApiAccess::authorize(
            issued('owner.cockpit.read','L4_OWNER',SCOPE,$agent),'GET','/api/v1/cockpit',SCOPE,NOW
        ),
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='mismatch'){
    $wrongPolicy=snapshot('owner.cockpit.read');
    $wrongPolicy['grant']['policy_ref']='controlbot:policy/not-active';
    $wrongIdentity=snapshot('owner.cockpit.read');
    $wrongIdentity['grant']['identity_id']='identity-other';
    echo json_encode([
        'capability'=>ExternalApiAccess::authorize(
            issued('owner.inbox.read'),'GET','/api/v1/cockpit',SCOPE,NOW
        ),
        'scope'=>ExternalApiAccess::authorize(
            issued('owner.cockpit.read'),'GET','/api/v1/cockpit','venture:beta',NOW
        ),
        'policy_mint_rejected'=>invalid(fn()=>issued('owner.cockpit.read','L4_OWNER',SCOPE,$wrongPolicy)),
        'identity_mint_rejected'=>invalid(fn()=>issued('owner.cockpit.read','L4_OWNER',SCOPE,$wrongIdentity)),
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='mutation'){
    echo json_encode(ExternalApiAccess::authorize(
        issued('owner.decision.write'),
        'POST','/api/v1/owner-decisions/{decision_id}/decision',SCOPE,NOW
    ),JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='surface'){
    $source=file_get_contents(__DIR__.'/../src/ExternalApiAccess.php');
    $public=array_map(
        static fn(ReflectionMethod $m): string=>$m->getName(),
        (new ReflectionClass(ExternalApiAccess::class))->getMethods(ReflectionMethod::IS_PUBLIC)
    );
    echo json_encode(['public'=>$public,'source'=>$source],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
fwrite(STDERR,"Unknown external API access scenario\n"); exit(2);
