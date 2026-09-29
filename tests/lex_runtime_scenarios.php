<?php
declare(strict_types=1);

require __DIR__.'/../src/LexCore.php';
require __DIR__.'/../src/LexJurisdiction.php';
require __DIR__.'/../src/LexGate.php';
require __DIR__.'/../src/LexWatch.php';
require __DIR__.'/../src/LexRuntime.php';

use ControlBot\Legal\LexRuntime;
use InvalidArgumentException;

const NOW=1500;
const SCOPE='venture:condor';

function pack(string $id,string $jurisdiction): array {
    $kind=str_starts_with($jurisdiction,'country:')?'country':(str_starts_with($jurisdiction,'region:')?'region':'supranational');
    return [
        'schema_version'=>1,'pack_id'=>$id,'jurisdiction'=>$jurisdiction,'kind'=>$kind,
        'pack_version'=>'1.0.0','state'=>'active','freshness'=>'fresh','reviewed_at'=>1000,'expires_at'=>3000,
        'responsible_refs'=>['controlbot:identity/legal-owner'],
        'sources'=>[['source_id'=>'source-official','authority'=>'Official authority','title'=>'Fixture source','uri'=>'https://example.test/legal-source','reviewed_at'=>1000]],
        'controls'=>[['control_id'=>'control-privacy','domain'=>'privacy','requirement_ref'=>'controlbot:lex/requirement/privacy','source_refs'=>['source-official'],'human_review_required'=>false]],
        'assumption_refs'=>[],'exclusion_refs'=>[],'compatibility'=>['lex_core_contract'=>1,'deprecated_by'=>null],
    ];
}

function registry(string $status,array $evidence=[],string $freshness='fresh'): array {
    return ['version'=>1,'scope'=>SCOPE,'obligations'=>[[
        'obligation_id'=>'ob-privacy','scope'=>SCOPE,'market_id'=>'market-co','jurisdiction_pack_id'=>'pack-co-v1',
        'domain'=>'privacy','requirement_ref'=>'controlbot:lex/requirement/privacy','reported_status'=>$status,
        'source_refs'=>['controlbot:lex/source/official'],'evidence_refs'=>$evidence,'observed_at'=>1000,'freshness'=>$freshness,
        'responsible_ref'=>'controlbot:identity/legal-owner','reviewed_at'=>1100,'expires_at'=>2500,'next_review_at'=>2200,
        'severity'=>'high','human_review_required'=>false,'justification_ref'=>null,'assumption_refs'=>[],
    ]]];
}

function gate(string $kind='executable_gap'): array {
    return [
        'version'=>1,'kind'=>$kind,'gap_id'=>'gap-privacy','scope'=>SCOPE,
        'question'=>'¿Qué evidencia falta para cerrar la obligación?','freshness'=>'fresh','severity'=>'high',
        'policy_ref'=>'controlbot:policy/legal-v1','evidence_refs'=>[],'observed_at'=>1200,
        'work_type'=>'compliance_review','requested_capabilities'=>['legal.review'],'required_roles'=>['legal-privacidad'],
        'group_id'=>'group-pl0n3r','venture_id'=>'condor','project_id'=>'condor','repository_ref'=>'pl0n3r/Condor',
        'authority_level'=>'standard','producer_ref'=>'controlbot:lex/runtime',
    ];
}

function runtime(array $jurisdictions=['country:CO'],array $packs=[],?array $before=null,?array $after=null,string $mode='country',string $gateKind='executable_gap'): array {
    return [
        'version'=>1,'scope'=>SCOPE,
        'market'=>['market_id'=>$mode==='global'?'market-global':'market-co','mode'=>$mode,'jurisdictions'=>$jurisdictions,'source_ref'=>'controlbot:market/scope'],
        'packs'=>$packs?:[pack('pack-co-v1','country:CO')],
        'registry_before'=>$before??registry('gap'),'gate'=>gate($gateKind),
        'registry_after'=>$after??registry('compliant',['controlbot:lex/evidence/privacy']),
        'watch'=>['version'=>1,'signals'=>[]],
    ];
}

function blocked(callable $fn): bool {
    try {$fn(); return false;} catch (InvalidArgumentException) {return true;}
}

$name=$argv[1]??'';
if($name==='flow'){
    $out=LexRuntime::evaluate(runtime(),NOW);
}elseif($name==='global'){
    $out=[
        'runtime'=>LexRuntime::evaluate(runtime(
            ['country:CO','country:MX'],
            [pack('pack-co-v1','country:CO'),pack('pack-mx-v1','country:MX')],
            registry('unknown'),registry('unknown'),'global','material_uncertainty'
        ),NOW),
        'empty_blocked'=>blocked(static fn():array=>LexRuntime::evaluate(runtime([], [pack('pack-co-v1','country:CO')],mode:'global'),NOW)),
    ];
}elseif($name==='insufficient'){
    $out=[
        'missing_evidence_blocked'=>blocked(static fn():array=>LexRuntime::evaluate(runtime(after:registry('compliant',[])),NOW)),
        'stale'=>LexRuntime::evaluate(runtime(after:registry('compliant',['controlbot:lex/evidence/privacy'],'stale')),NOW),
        'empty'=>LexRuntime::evaluate(runtime(after:['version'=>1,'scope'=>SCOPE,'obligations'=>[]]),NOW),
    ];
}elseif($name==='authority'){
    $out=LexRuntime::evaluate(runtime(),NOW);
}else{
    fwrite(STDERR,"scenario inválido\n"); exit(2);
}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
