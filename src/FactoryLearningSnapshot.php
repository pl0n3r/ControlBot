<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

final class FactoryLearningSnapshot
{
    private const PROJECTS=['Condor','GrindFlow','BRVTAL','FactoryRunner','ControlBot','AutoFactory','Factory'];
    private const LAYERS=[
        'product_business','application','data','infrastructure','ci_cd','quality',
        'security_privacy','production_observability','governance_agents','costs_limits',
    ];
    private const METRICS=[
        'lessons_per_week_project','incidents_by_class','mttr_seconds',
        'blockers_with_cause','fix_feat_ratio','learning_gaps',
    ];
    private const SECTIONS=['batches','owner_decisions','releases','blockers','production','quality','work','learning'];
    private const STATES=['healthy','degraded','critical','unknown','blocked','pending'];
    private const FRESH=['current','stale','unknown'];
    private const SENSITIVE='/(?:password|passwd|secret|token|cookie|authorization|bearer|private[_ -]?key|api[_ -]?key|dsn)/i';
    private const PII='/(?:[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}|\+?(?=(?:[0-9(). -]*[0-9]){10})[0-9][0-9(). -]{7,}[0-9])/i';

    public static function build(array $snapshot): array
    {
        self::fields($snapshot,['version','observed_at','sections','tool_usage','fingerprint'],'snapshot');
        if($snapshot['version']!==1||!is_int($snapshot['observed_at'])||!is_array($snapshot['sections']))
            throw new InvalidArgumentException('Learning snapshot invalid.');
        if(!is_string($snapshot['fingerprint'])||preg_match('/^[a-f0-9]{64}$/D',$snapshot['fingerprint'])!==1)
            throw new InvalidArgumentException('Learning fingerprint invalid.');
        $safeSnapshot=$snapshot;
        unset($safeSnapshot['fingerprint']);
        self::safe($safeSnapshot);

        $layers=[];foreach(self::LAYERS as $layer)$layers[$layer]=['status'=>'UNKNOWN','signals'=>[]];
        $metrics=[];foreach(self::METRICS as $metric)$metrics[$metric]=[
            'global'=>self::unknownMetric(),'by_project'=>[],
        ];
        $real=[];$auto=[];$recurrence=self::unknownMetric();

        foreach(self::SECTIONS as $section){
            $rows=$snapshot['sections'][$section]??null;
            if(!is_array($rows)||!array_is_list($rows)||$rows===[])
                throw new InvalidArgumentException('Learning section invalid.');
            foreach($rows as $row){
                $signal=self::signal($row,$section);
                $data=is_array($row['data'])&&!array_is_list($row['data'])?$row['data']:[];

                if(array_key_exists('layer',$data)){
                    $layer=self::choice($data['layer'],self::LAYERS,'layer');
                    $firstSignal=$layers[$layer]['signals']===[];
                    $layers[$layer]['signals'][]=$signal;
                    $layers[$layer]['status']=$firstSignal
                        ?$signal['status']
                        :self::worse($layers[$layer]['status'],$signal['status']);
                }

                if($section==='learning'&&array_key_exists('metric',$data)){
                    $metric=self::choice($data['metric'],self::METRICS,'metric');
                    if(!array_key_exists('metric_value',$data))throw new InvalidArgumentException('Metric value missing.');
                    self::metricValue($data['metric_value']);
                    $candidate=self::metric($signal,$data['metric_value']);
                    $project=$signal['project'];$scope=$signal['scope'];
                    if($project!==null){
                        $current=$metrics[$metric]['by_project'][$project]??self::unknownMetric();
                        if(self::betterEvidence($candidate,$current))
                            $metrics[$metric]['by_project'][$project]=$candidate;
                    }elseif($scope==='global'&&self::betterEvidence($candidate,$metrics[$metric]['global'])){
                        $metrics[$metric]['global']=$candidate;
                    }
                }

                if(($data['kind']??null)==='incident'&&($data['open']??null)===true){
                    if(array_key_exists('auto',$data)&&!is_bool($data['auto']))throw new InvalidArgumentException('Incident auto flag invalid.');
                    $title=is_string($data['title']??null)?trim($data['title']):$signal['label'];
                    $item=$signal+['title'=>$title];
                    $isAuto=($data['auto']??false)===true||preg_match('/^\[AUTO\]/i',$title)===1;
                    if($isAuto)$auto[]=$item;else $real[]=$item;
                }

                if($section==='learning'&&isset($data['lesson_ref'],$data['incident_ref'],$data['recurrence_count'])
                    && is_string($data['lesson_ref'])&&trim($data['lesson_ref'])!==''
                    && is_string($data['incident_ref'])&&trim($data['incident_ref'])!==''
                    && is_int($data['recurrence_count'])&&$data['recurrence_count']>=0){
                    $candidate=self::metric($signal,$data['recurrence_count']);
                    if(self::betterEvidence($candidate,$recurrence))$recurrence=$candidate;
                }
            }
        }

        foreach($layers as &$layer)usort($layer['signals'],static fn(array $a,array $b):int=>$a['id']<=>$b['id']);unset($layer);
        foreach($metrics as &$metric)ksort($metric['by_project'],SORT_STRING);unset($metric);
        usort($real,static fn(array $a,array $b):int=>$a['id']<=>$b['id']);
        usort($auto,static fn(array $a,array $b):int=>$a['id']<=>$b['id']);

        return [
            'version'=>1,'observed_at'=>$snapshot['observed_at'],'layers'=>$layers,'metrics'=>$metrics,
            'recurrence'=>$recurrence,'incidents'=>['real'=>$real,'auto_notices'=>$auto],
        ];
    }

    private static function signal(mixed $row,string $section): array
    {
        self::fields($row,['id','authority','state','source_ref','observed_at','freshness','age_seconds','data'],'signal');
        $state=self::choice($row['state'],self::STATES,'state');
        $fresh=self::choice($row['freshness'],self::FRESH,'freshness');
        if(!is_string($row['id'])||trim($row['id'])==='')throw new InvalidArgumentException('Signal id invalid.');
        if($fresh==='unknown'){
            if($state!=='unknown'||$row['source_ref']!==null||$row['observed_at']!==null||$row['age_seconds']!==null)
                throw new InvalidArgumentException('Unknown learning signal incoherent.');
        }elseif(!is_string($row['source_ref'])||!is_int($row['observed_at'])||!is_int($row['age_seconds'])||$row['age_seconds']<0)
            throw new InvalidArgumentException('Learning provenance invalid.');
        if($fresh==='stale'&&$state==='healthy')throw new InvalidArgumentException('Stale learning signal cannot be green.');
        $data=is_array($row['data'])&&!array_is_list($row['data'])?$row['data']:[];
        $label=is_string($data['title']??null)?trim($data['title']):$row['id'];
        $project=array_key_exists('project',$data)?self::project($data['project']):null;
        $scope=null;
        if($section==='learning'&&array_key_exists('scope',$data))
            $scope=self::choice($data['scope'],['global'],'scope');
        if($project!==null&&$scope!==null)
            throw new InvalidArgumentException('Learning project/scope conflict.');
        return [
            'id'=>$row['id'],'label'=>$label,'project'=>$project,'scope'=>$scope,'status'=>self::status($state,$fresh),
            'source_ref'=>$row['source_ref'],'observed_at'=>$row['observed_at'],'freshness'=>$fresh,
            'age_seconds'=>$row['age_seconds'],'evidence_href'=>self::href($data['evidence_ref']??$data['issue_ref']??$row['source_ref']),
        ];
    }

    private static function project(mixed $value): string
    {
        if(!is_string($value))throw new InvalidArgumentException('project invalid.');
        $key=strtolower(preg_replace('/[^a-z0-9]/i','',$value)??'');
        $map=[
            'condor'=>'Condor','grindflow'=>'GrindFlow','brvtal'=>'BRVTAL',
            'factoryrunner'=>'FactoryRunner','controlbot'=>'ControlBot',
            'autofactory'=>'AutoFactory','factory'=>'Factory',
        ];
        $project=$map[$key]??null;
        if($project===null||!in_array($project,self::PROJECTS,true))
            throw new InvalidArgumentException('project invalid.');
        return $project;
    }

    private static function metric(array $signal,mixed $value): array
    {
        return [
            'status'=>$signal['status'],'value'=>$value,'source_ref'=>$signal['source_ref'],
            'observed_at'=>$signal['observed_at'],'freshness'=>$signal['freshness'],
            'age_seconds'=>$signal['age_seconds'],'evidence_href'=>$signal['evidence_href'],
        ];
    }

    private static function unknownMetric(): array
    {
        return ['status'=>'UNKNOWN','value'=>null,'source_ref'=>null,'observed_at'=>null,'freshness'=>'unknown','age_seconds'=>null,'evidence_href'=>null];
    }

    private static function betterEvidence(array $candidate,array $current): bool
    {
        $fresh=['unknown'=>0,'stale'=>1,'current'=>2];
        $a=$fresh[$candidate['freshness']]??-1;$b=$fresh[$current['freshness']]??-1;
        if($a!==$b)return $a>$b;
        $ca=$candidate['age_seconds'];$cb=$current['age_seconds'];
        return is_int($ca)&&(!is_int($cb)||$ca<$cb);
    }

    private static function worse(string $current,string $candidate): string
    {
        $rank=['GREEN'=>1,'AMBER'=>2,'UNKNOWN'=>3,'STALE'=>4,'RED'=>5];
        return ($rank[$candidate]??0)>($rank[$current]??0)?$candidate:$current;
    }

    private static function status(string $state,string $fresh): string
    {
        if($fresh==='stale')return 'STALE';
        if($fresh==='unknown'||$state==='unknown')return 'UNKNOWN';
        return match($state){'healthy'=>'GREEN','critical','blocked'=>'RED','degraded','pending'=>'AMBER',default=>'UNKNOWN'};
    }

    private static function metricValue(mixed $value): void
    {
        if(is_int($value)||is_float($value))return;
        if(is_string($value)&&trim($value)!=='')return;
        if(is_array($value)&&!array_is_list($value)&&$value!==[])return;
        throw new InvalidArgumentException('Metric value invalid.');
    }

    private static function href(mixed $value): ?string
    {
        if(!is_string($value))return null;
        if(preg_match('~^github:([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)#([1-9][0-9]*)$~D',$value,$m)===1)
            return 'https://github.com/'.$m[1].'/'.$m[2].'/issues/'.$m[3];
        return preg_match('~^https://(?:github\.com|sonarcloud\.io)/[^\s<>"\']+$~D',$value)===1?$value:null;
    }

    private static function safe(mixed $value): void
    {
        if(is_array($value)){foreach($value as $key=>$item){if(is_string($key)&&preg_match(self::SENSITIVE,$key)===1)
            throw new InvalidArgumentException('Sensitive learning field.');self::safe($item);}return;}
        if(is_string($value)&&(preg_match(self::SENSITIVE,$value)===1||preg_match(self::PII,$value)===1))
            throw new InvalidArgumentException('Unsafe learning value.');
    }

    private static function choice(mixed $value,array $allowed,string $label): string
    { if(is_string($value)&&in_array($value,$allowed,true))return $value;throw new InvalidArgumentException($label.' invalid.'); }

    private static function fields(mixed $row,array $expected,string $label): void
    {
        if(!is_array($row)||array_is_list($row))throw new InvalidArgumentException($label.' invalid.');
        $actual=array_keys($row);sort($actual,SORT_STRING);sort($expected,SORT_STRING);
        if($actual!==$expected)throw new InvalidArgumentException($label.' fields invalid.');
    }
}
