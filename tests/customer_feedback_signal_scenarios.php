<?php
declare(strict_types=1);

require_once __DIR__.'/../src/CustomerSuccessCore.php';
require_once __DIR__.'/../src/CustomerFeedbackSignal.php';

use ControlBot\CustomerSuccess\CustomerFeedbackSignal;
use InvalidArgumentException;

function ref(string $namespace,string $char): string { return $namespace.':'.str_repeat($char,32); }

function rejected(callable $fn): bool
{
    try { $fn(); return false; }
    catch (InvalidArgumentException) { return true; }
}

function support(string $freshness='fresh'): array
{
    $known=$freshness!=='unknown';
    return [
        'version'=>1,
        'signal_id'=>ref('support','a'),
        'venture_id'=>'venture-condor',
        'product_ref'=>ref('product','b'),
        'category'=>'bug',
        'severity'=>'medium',
        'pattern_ref'=>ref('pattern','c'),
        'knowledge_ref'=>null,
        'resolution_state'=>'investigating',
        'evidence_ref'=>$known?ref('evidence','d'):null,
        'freshness'=>$freshness,
        'observed_at'=>$known?1700000000:null,
        'escalation_ref'=>null,
    ];
}

function dimensions(): array
{
    $names=[
        'activation','adoption','churn_risk','onboarding','reliability_impact',
        'renewal_signal','satisfaction','support_burden','usage_recency',
    ];
    $rows=[];
    foreach($names as $index=>$name){
        $rows[]=[
            'name'=>$name,
            'status'=>'measured',
            'value_ref'=>ref('metric',dechex(($index%6)+1)),
            'evidence_ref'=>ref('evidence',dechex(($index%6)+7)),
            'freshness'=>'fresh',
            'confidence'=>$name==='churn_risk'?0.42:0.8,
            'nature'=>$name==='churn_risk'?'inferred':'observed',
        ];
    }
    return $rows;
}

function snapshot(): array
{
    return [
        'version'=>1,
        'snapshot_id'=>ref('snapshot','e'),
        'venture_id'=>'venture-condor',
        'product_ref'=>ref('product','b'),
        'observed_at'=>1700000100,
        'dimensions'=>dimensions(),
    ];
}

function input(string $char='f',array $targets=['product_intelligence','discovery']): array
{
    return ['version'=>1,'feedback_ref'=>ref('feedback',$char),'targets'=>$targets];
}

$scenario=$argv[1]??'';
switch($scenario){
case 'scope':
    $good=CustomerFeedbackSignal::fromSupport(input(),support(),'venture-condor');
    $cross=rejected(fn()=>CustomerFeedbackSignal::fromSupport(input(),support(),'venture-other'));
    $missing=support(); unset($missing['product_ref']);
    $missingProduct=rejected(fn()=>CustomerFeedbackSignal::fromSupport(input(),$missing,'venture-condor'));
    $extra=input(); $extra['customer_text']='should never pass';
    $extraRejected=rejected(fn()=>CustomerFeedbackSignal::fromSupport($extra,support(),'venture-condor'));
    echo json_encode(compact('good','cross','missingProduct','extraRejected')); break;

case 'closed':
    $good=CustomerFeedbackSignal::fromSupport(
        input('1',['product_intelligence','discovery']),support(),'venture-condor'
    );
    $duplicate=rejected(fn()=>CustomerFeedbackSignal::fromSupport(
        input('2',['discovery','discovery']),support(),'venture-condor'
    ));
    $unknown=rejected(fn()=>CustomerFeedbackSignal::fromSupport(
        input('3',['product_intelligence','crm']),support(),'venture-condor'
    ));
    $second=CustomerFeedbackSignal::fromHealthDimension(
        input('0',['discovery']),snapshot(),'satisfaction','venture-condor'
    );
    $collection=CustomerFeedbackSignal::collection([$good,$second]);
    $duplicatedCollection=rejected(fn()=>CustomerFeedbackSignal::collection([$good,$good]));
    echo json_encode(compact('good','duplicate','unknown','collection','duplicatedCollection')); break;

case 'fail_closed':
    $stale=CustomerFeedbackSignal::fromSupport(input('4'),support('stale'),'venture-condor');
    $unknownSupport=CustomerFeedbackSignal::fromSupport(
        input('5',['discovery']),support('unknown'),'venture-condor'
    );
    $churn=CustomerFeedbackSignal::fromHealthDimension(
        input('6',['discovery']),snapshot(),'churn_risk','venture-condor'
    );
    $unknownSnapshot=snapshot();
    foreach($unknownSnapshot['dimensions'] as &$row){
        if($row['name']==='usage_recency'){
            $row['status']='unknown';
            $row['value_ref']=null;
            $row['evidence_ref']=null;
            $row['freshness']='unknown';
            $row['confidence']=0.0;
        }
    }
    unset($row);
    $unknownHealth=CustomerFeedbackSignal::fromHealthDimension(
        input('7',['discovery']),$unknownSnapshot,'usage_recency','venture-condor'
    );
    echo json_encode(compact('stale','unknownSupport','churn','unknownHealth')); break;

case 'privacy':
    $checks=[];
    foreach(['transcript','ticket_body','message_body','email','phone','attachment_content','customer_text'] as $field){
        $raw=input(); $raw[$field]='sensitive';
        $checks[$field]=rejected(fn()=>CustomerFeedbackSignal::fromSupport($raw,support(),'venture-condor'));
    }
    $bad=input(); $bad['feedback_ref']='person@example.test';
    $checks['direct_ref']=rejected(fn()=>CustomerFeedbackSignal::fromSupport($bad,support(),'venture-condor'));
    $supportPii=support(); $supportPii['ticket_body']='secret';
    $checks['source_extra']=rejected(fn()=>CustomerFeedbackSignal::fromSupport(input(),$supportPii,'venture-condor'));
    echo json_encode($checks); break;

case 'pure':
    $reflection=new ReflectionClass(CustomerFeedbackSignal::class);
    $methods=array_map(
        static fn(ReflectionMethod $method): string=>$method->getName(),
        array_filter($reflection->getMethods(ReflectionMethod::IS_PUBLIC),static fn(ReflectionMethod $method): bool=>$method->getDeclaringClass()->getName()===CustomerFeedbackSignal::class)
    );
    sort($methods,SORT_STRING);
    echo json_encode(['methods'=>$methods]); break;

default:
    fwrite(STDERR,"unknown scenario\n"); exit(2);
}
