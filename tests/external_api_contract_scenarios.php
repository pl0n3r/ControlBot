<?php
declare(strict_types=1);

require __DIR__.'/../src/ExternalApiContract.php';

use ControlBot\ExternalApi\ExternalApiContract;

const REQUEST_ID='11111111111111111111111111111111';
const CORRELATION_ID='22222222222222222222222222222222';
const IDEMPOTENCY='33333333333333333333333333333333';

function mutation(): array {
    return ['version'=>1,'outcome'=>'approve','idempotency_key'=>IDEMPOTENCY];
}
function blocked(callable $fn): bool {
    try { $fn(); return false; } catch (Throwable) { return true; }
}

$case=$argv[1]??'';
if($case==='catalog'){
    echo json_encode(ExternalApiContract::catalog(),JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='envelopes'){
    echo json_encode([
        'ids'=>ExternalApiContract::requestIds([
            'request_id'=>REQUEST_ID,'correlation_id'=>CORRELATION_ID,
        ]),
        'meta'=>ExternalApiContract::responseMeta([
            'version'=>1,'request_id'=>REQUEST_ID,'correlation_id'=>CORRELATION_ID,
            'generated_at'=>1000,
            'freshness'=>['state'=>'current','observed_at'=>990,'source_ref'=>'controlbot:cockpit/snapshot'],
        ]),
        'unknown'=>ExternalApiContract::responseMeta([
            'version'=>1,'request_id'=>REQUEST_ID,'correlation_id'=>CORRELATION_ID,
            'generated_at'=>1000,
            'freshness'=>['state'=>'unknown','observed_at'=>null,'source_ref'=>null],
        ]),
        'error'=>ExternalApiContract::error([
            'version'=>1,'code'=>'forbidden','message'=>'Action is not allowed.',
            'request_id'=>REQUEST_ID,'correlation_id'=>CORRELATION_ID,
        ]),
        'unicode_error'=>ExternalApiContract::error([
            'version'=>1,'code'=>'invalid_request','message'=>str_repeat('é',121),
            'request_id'=>REQUEST_ID,'correlation_id'=>CORRELATION_ID,
        ]),
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='mutation'){
    $forbidden=['identity','role','authority_level','policy_ref','grant','provider_credential','execution_target'];
    $rejected=[];
    foreach($forbidden as $field){
        $candidate=mutation(); $candidate[$field]='client-value';
        $rejected[$field]=blocked(fn()=>ExternalApiContract::decisionMutation($candidate));
    }
    echo json_encode([
        'valid'=>ExternalApiContract::decisionMutation(mutation()),
        'server_fields_rejected'=>$rejected,
        'missing_idempotency_rejected'=>blocked(function(){
            $candidate=mutation(); unset($candidate['idempotency_key']);
            ExternalApiContract::decisionMutation($candidate);
        }),
        'bad_idempotency_rejected'=>blocked(function(){
            $candidate=mutation(); $candidate['idempotency_key']='human-readable-key';
            ExternalApiContract::decisionMutation($candidate);
        }),
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
fwrite(STDERR,"Unknown external API contract scenario\n"); exit(2);
