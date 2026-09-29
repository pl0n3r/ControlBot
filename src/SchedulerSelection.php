<?php
declare(strict_types=1);
namespace ControlBot\Scheduler;
use InvalidArgumentException;

final class SchedulerSelection
{
    private const POLICY='factory-dispatcher-v2';
    private const PRIORITIES=['critical','high','medium','low'];

    public static function request(array $rows): array
    {
        if(!array_is_list($rows)||$rows===[]||count($rows)>64) throw new InvalidArgumentException('Selection candidates invalid.');
        $candidates=[];
        foreach($rows as $raw){
            if(!is_array($raw)) throw new InvalidArgumentException('Selection candidate invalid.');
            $candidate=self::candidate($raw); $key=$candidate['key'];
            if(isset($candidates[$key])) throw new InvalidArgumentException('Selection candidate duplicated.');
            $candidates[$key]=$candidate;
        }
        ksort($candidates,SORT_STRING); $candidates=array_values($candidates);
        $canonical=['version'=>1,'policy_ref'=>self::POLICY,'candidates'=>$candidates];
        return $canonical+['fingerprint'=>hash('sha256',json_encode($canonical,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES))];
    }

    public static function validateDecision(array $requestRaw,array $decisionRaw,array $currentRows): array
    {
        $request=self::canonicalRequest($requestRaw); $current=self::request($currentRows);
        if(!hash_equals($request['fingerprint'],$current['fingerprint'])) throw new InvalidArgumentException('Selection readiness drift.');
        self::fields($decisionRaw,['version','policy_ref','request_fingerprint','selected_key','selection_reason','telemetry'],'SelectionDecision');
        if(($decisionRaw['version']??null)!==1||($decisionRaw['policy_ref']??null)!==self::POLICY)
            throw new InvalidArgumentException('SelectionDecision policy invalid.');
        $fingerprint=self::sha256($decisionRaw['request_fingerprint'],'request_fingerprint');
        if(!hash_equals($request['fingerprint'],$fingerprint)) throw new InvalidArgumentException('SelectionDecision fingerprint invalid.');
        $selectedKey=self::id($decisionRaw['selected_key'],'selected_key'); $byKey=[];
        foreach($current['candidates'] as $row) $byKey[$row['key']]=$row;
        $selected=$byKey[$selectedKey]??null;
        if($selected===null) throw new InvalidArgumentException('SelectionDecision candidate unknown.');
        if(!$selected['readiness']['ready']) throw new InvalidArgumentException('SelectionDecision candidate not ready.');
        return [
            'version'=>1,'policy_ref'=>self::POLICY,'request_fingerprint'=>$request['fingerprint'],'selected_key'=>$selectedKey,
            'selection_reason'=>self::safeText($decisionRaw['selection_reason'],'selection_reason',240),
            'telemetry'=>self::telemetry($decisionRaw['telemetry'],$current,$selectedKey),
            'selected'=>[
                'key'=>$selected['key'],'source_ref'=>$selected['source_ref'],'priority'=>$selected['priority'],
                'generation'=>$selected['generation'],'required_capabilities'=>$selected['required_capabilities'],'account_id'=>$selected['account_id'],
            ],
        ];
    }

    private static function canonicalRequest(array $raw): array
    {
        self::fields($raw,['version','policy_ref','candidates','fingerprint'],'SelectionRequest');
        if(($raw['version']??null)!==1||($raw['policy_ref']??null)!==self::POLICY||!is_array($raw['candidates']))
            throw new InvalidArgumentException('SelectionRequest invalid.');
        $fingerprint=self::sha256($raw['fingerprint'],'fingerprint'); $rebuilt=self::request($raw['candidates']);
        if(!hash_equals($rebuilt['fingerprint'],$fingerprint)) throw new InvalidArgumentException('SelectionRequest fingerprint invalid.');
        return $rebuilt;
    }

    private static function candidate(array $raw): array
    {
        self::fields($raw,['version','policy_ref','key','source_ref','priority','generation','required_capabilities','account_id','readiness'],'SelectionCandidate');
        if(($raw['version']??null)!==1||($raw['policy_ref']??null)!==self::POLICY||!is_array($raw['readiness']))
            throw new InvalidArgumentException('Selection candidate policy invalid.');
        self::fields($raw['readiness'],['ready','reasons','open_dependencies','unknown_dependencies'],'SelectionReadiness');
        if(!is_bool($raw['readiness']['ready'])) throw new InvalidArgumentException('Selection readiness flag invalid.');
        $ready=$raw['readiness']['ready'];
        $reasons=self::unique($raw['readiness']['reasons'],'readiness.reasons','slug');
        $open=self::unique($raw['readiness']['open_dependencies'],'readiness.open_dependencies','id');
        $unknown=self::unique($raw['readiness']['unknown_dependencies'],'readiness.unknown_dependencies','id');
        if($ready&&($reasons!==[]||$open!==[]||$unknown!==[])) throw new InvalidArgumentException('Ready candidate cannot carry blockers.');
        if(!$ready&&$reasons===[]) throw new InvalidArgumentException('Blocked candidate requires reasons.');
        if($open!==[]&&!in_array('open_dependencies',$reasons,true)) throw new InvalidArgumentException('Open dependencies reason missing.');
        if($unknown!==[]&&!in_array('unknown_dependencies',$reasons,true)) throw new InvalidArgumentException('Unknown dependencies reason missing.');
        return [
            'version'=>1,'policy_ref'=>self::POLICY,'key'=>self::id($raw['key'],'key'),'source_ref'=>self::workRef($raw['source_ref']),
            'priority'=>self::enum($raw['priority'],self::PRIORITIES,'priority'),'generation'=>self::positiveInt($raw['generation'],'generation'),
            'required_capabilities'=>self::unique($raw['required_capabilities'],'required_capabilities','slug'),'account_id'=>self::id($raw['account_id'],'account_id'),
            'readiness'=>['ready'=>$ready,'reasons'=>$reasons,'open_dependencies'=>$open,'unknown_dependencies'=>$unknown],
        ];
    }

    private static function telemetry(mixed $raw,array $request,string $selected): array
    {
        self::fields($raw,['ready_not_selected','excluded'],'SelectionTelemetry'); $ready=[];$excluded=[];
        foreach($request['candidates'] as $row){
            if($row['readiness']['ready']){if($row['key']!==$selected)$ready[]=$row['key'];}
            else $excluded[$row['key']]=$row['readiness']['reasons'];
        }
        sort($ready,SORT_STRING);ksort($excluded,SORT_STRING);
        if(self::unique($raw['ready_not_selected']??null,'telemetry.ready_not_selected','id')!==$ready)
            throw new InvalidArgumentException('Selection telemetry ready set invalid.');
        if(!is_array($raw['excluded'])||($raw['excluded']!==[]&&array_is_list($raw['excluded'])))
            throw new InvalidArgumentException('Selection telemetry excluded invalid.');
        $reported=[];
        foreach($raw['excluded'] as $key=>$reasons) $reported[self::id($key,'telemetry.excluded.key')]=self::unique($reasons,'telemetry.excluded.reasons','slug');
        ksort($reported,SORT_STRING);
        if($reported!==$excluded) throw new InvalidArgumentException('Selection telemetry excluded set invalid.');
        return ['ready_not_selected'=>$ready,'excluded'=>$excluded];
    }

    private static function fields(mixed $raw,array $expected,string $label): void
    {
        if(!is_array($raw)) throw new InvalidArgumentException($label.' invalid.');
        $actual=array_keys($raw);sort($actual,SORT_STRING);sort($expected,SORT_STRING);
        if($actual!==$expected) throw new InvalidArgumentException($label.' fields invalid.');
    }
    private static function unique(mixed $values,string $label,string $kind): array
    {
        if(!is_array($values)||!array_is_list($values)||count($values)>64) throw new InvalidArgumentException($label.' invalid.');
        $out=[];foreach($values as $value){$v=$kind==='slug'?self::slug($value,$label):self::id($value,$label);if(in_array($v,$out,true))throw new InvalidArgumentException($label.' duplicated.');$out[]=$v;}
        sort($out,SORT_STRING);return $out;
    }
    private static function enum(mixed $value,array $allowed,string $label): string
    {if(!is_string($value)||!in_array($value,$allowed,true))throw new InvalidArgumentException($label.' invalid.');return $value;}
    private static function positiveInt(mixed $value,string $label): int
    {if(!is_int($value)||$value<1)throw new InvalidArgumentException($label.' invalid.');return $value;}
    private static function sha256(mixed $value,string $label): string
    {if(!is_string($value)||preg_match('/^[0-9a-f]{64}$/D',$value)!==1)throw new InvalidArgumentException($label.' invalid.');return $value;}
    private static function slug(mixed $value,string $label): string
    {if(!is_string($value)||preg_match('/^[a-z][a-z0-9._:-]{0,95}$/D',$value)!==1)throw new InvalidArgumentException($label.' invalid.');return $value;}
    private static function id(mixed $value,string $label): string
    {if(!is_string($value)||preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@\/-]{0,179}$/D',$value)!==1)throw new InvalidArgumentException($label.' invalid.');return $value;}
    private static function workRef(mixed $value): string
    {if(!is_string($value)||preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+#[1-9][0-9]*$/D',$value)!==1)throw new InvalidArgumentException('source_ref invalid.');return $value;}
    private static function safeText(mixed $value,string $label,int $max): string
    {
        if(!is_string($value))throw new InvalidArgumentException($label.' invalid.');$value=trim($value);
        if($value===''||strlen($value)>$max||preg_match('/[\x00-\x1f\x7f]/',$value)===1
            ||preg_match('/(?:-----BEGIN [^-]*PRIVATE KEY-----|\b(?:bearer\s+[A-Za-z0-9._~+\/-]{8,}|(?:password|passwd|token|secret|cookie|authorization|private[_ -]?key|api[_ -]?key|dsn)\s*[:=]\s*\S+|(?:ghp_|gho_|github_pat_)[A-Za-z0-9_]{20,}|(?:sk|rk|pk)-[A-Za-z0-9_-]{12,}))/i',$value)===1
            ||preg_match('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i',$value)===1||preg_match('/(?:\+?\d[\d .()\-]{7,}\d)/',$value)===1)
            throw new InvalidArgumentException($label.' unsafe.');
        return $value;
    }
}
