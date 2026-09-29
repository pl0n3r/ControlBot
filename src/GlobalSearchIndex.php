<?php
declare(strict_types=1);

namespace ControlBot\Search;

use InvalidArgumentException;

final class GlobalSearchIndex
{
    private const SOURCES=['github','controlbot','factory'];
    private const MAX_ENTITIES=1000;
    private const MAX_CHANGES=1000;

    public static function emptyState(): array
    {
        return ['version'=>1,'watermarks'=>[],'entities'=>[]];
    }

    public static function applyBatch(array $stateRaw,array $batchRaw): array
    {
        $state=self::state($stateRaw);
        $batch=self::batch($batchRaw);
        $source=$batch['source'];
        $current=$state['watermarks'][$source]??null;

        if($current!==null){
            if($batch['watermark']<$current['value']){
                throw new InvalidArgumentException('Batch watermark stale.');
            }
            if($batch['watermark']===$current['value']){
                if(!hash_equals($current['batch_fingerprint'],$batch['fingerprint'])){
                    throw new InvalidArgumentException('Batch watermark conflict.');
                }
                return $state;
            }
        }

        $entities=$state['entities'];
        foreach($batch['changes'] as $change){
            $key=self::entityKey($source,$change['source_identity']);
            if($change['op']==='delete'){
                $entities[$key]=[
                    'source'=>$source,
                    'source_identity'=>$change['source_identity'],
                    'deleted'=>true,
                    'document'=>null,
                ];
                continue;
            }
            $entities[$key]=[
                'source'=>$source,
                'source_identity'=>$change['source_identity'],
                'deleted'=>false,
                'document'=>$change['document'],
            ];
        }
        if(count($entities)>self::MAX_ENTITIES){
            throw new InvalidArgumentException('Index entity limit exceeded.');
        }
        ksort($entities,SORT_STRING);

        $watermarks=$state['watermarks'];
        $watermarks[$source]=[
            'value'=>$batch['watermark'],
            'batch_fingerprint'=>$batch['fingerprint'],
        ];
        ksort($watermarks,SORT_STRING);

        return self::state(['version'=>1,'watermarks'=>$watermarks,'entities'=>$entities]);
    }

    public static function search(array $stateRaw,array $queryRaw): array
    {
        $state=self::state($stateRaw);
        $documents=[];
        foreach($state['entities'] as $entity){
            if(!$entity['deleted']){
                $documents[]=$entity['document'];
            }
        }
        return GlobalSearchCore::search($queryRaw,$documents);
    }

    public static function fingerprint(array $stateRaw): string
    {
        return self::hash(self::state($stateRaw));
    }

    private static function state(array $raw): array
    {
        self::fields($raw,['version','watermarks','entities'],'GlobalSearchIndexState');
        if(($raw['version']??null)!==1){
            throw new InvalidArgumentException('Index state version invalid.');
        }
        if(!is_array($raw['watermarks'])||($raw['watermarks']!==[]&&array_is_list($raw['watermarks']))){
            throw new InvalidArgumentException('Index watermarks invalid.');
        }
        if(!is_array($raw['entities'])||($raw['entities']!==[]&&array_is_list($raw['entities']))
            ||count($raw['entities'])>self::MAX_ENTITIES){
            throw new InvalidArgumentException('Index entities invalid.');
        }

        $watermarks=[];
        foreach($raw['watermarks'] as $source=>$row){
            $source=self::source($source);
            self::fields($row,['value','batch_fingerprint'],'Watermark');
            $watermarks[$source]=[
                'value'=>self::positive($row['value'],'watermark'),
                'batch_fingerprint'=>self::sha($row['batch_fingerprint'],'batch_fingerprint'),
            ];
        }
        ksort($watermarks,SORT_STRING);

        $entities=[];
        foreach($raw['entities'] as $key=>$row){
            if(!is_string($key)){
                throw new InvalidArgumentException('Index entity key invalid.');
            }
            self::fields($row,['source','source_identity','deleted','document'],'IndexEntity');
            $source=self::source($row['source']);
            $identity=self::sourceIdentity($row['source_identity']);
            if(!hash_equals(self::entityKey($source,$identity),$key)||!is_bool($row['deleted'])){
                throw new InvalidArgumentException('Index entity identity invalid.');
            }
            if($row['deleted']){
                if($row['document']!==null){
                    throw new InvalidArgumentException('Deleted entity document invalid.');
                }
                $document=null;
            }else{
                if(!is_array($row['document'])){
                    throw new InvalidArgumentException('Index entity document invalid.');
                }
                $document=self::document($row['document'],$source);
            }
            $entities[$key]=[
                'source'=>$source,'source_identity'=>$identity,
                'deleted'=>$row['deleted'],'document'=>$document,
            ];
        }
        ksort($entities,SORT_STRING);
        return ['version'=>1,'watermarks'=>$watermarks,'entities'=>$entities];
    }

    private static function batch(array $raw): array
    {
        self::fields($raw,['source','watermark','changes'],'IndexBatch');
        $source=self::source($raw['source']);
        $watermark=self::positive($raw['watermark'],'watermark');
        if(!is_array($raw['changes'])||!array_is_list($raw['changes'])||count($raw['changes'])>self::MAX_CHANGES){
            throw new InvalidArgumentException('Batch changes invalid.');
        }

        $byIdentity=[];
        foreach($raw['changes'] as $rawChange){
            if(!is_array($rawChange)||array_is_list($rawChange)){
                throw new InvalidArgumentException('Index change invalid.');
            }
            $op=$rawChange['op']??null;
            if($op==='upsert'){
                self::fields($rawChange,['op','source_identity','document'],'IndexUpsert');
                $identity=self::sourceIdentity($rawChange['source_identity']);
                if(!is_array($rawChange['document'])){
                    throw new InvalidArgumentException('Index document invalid.');
                }
                $change=['op'=>'upsert','source_identity'=>$identity,'document'=>self::document($rawChange['document'],$source)];
            }elseif($op==='delete'){
                self::fields($rawChange,['op','source_identity'],'IndexDelete');
                $identity=self::sourceIdentity($rawChange['source_identity']);
                $change=['op'=>'delete','source_identity'=>$identity];
            }else{
                throw new InvalidArgumentException('Index change operation invalid.');
            }
            if(isset($byIdentity[$identity])){
                throw new InvalidArgumentException('Duplicate source identity in batch.');
            }
            $byIdentity[$identity]=$change;
        }
        ksort($byIdentity,SORT_STRING);
        $changes=array_values($byIdentity);
        $basis=['source'=>$source,'watermark'=>$watermark,'changes'=>$changes];
        return $basis+['fingerprint'=>self::hash($basis)];
    }

    private static function document(array $document,string $source): array
    {
        self::fields($document,[
            'version','type','title','project','repo','number_or_id','state','updated_at','snippet',
            'source','canonical_url','freshness','roles','access',
        ],'SearchDocument');
        if(($document['version']??null)!==1
            ||!in_array($document['access']??null,['allow','deny','unknown'],true)){
            throw new InvalidArgumentException('SearchDocument invalid.');
        }
        if(($document['source']??null)!==$source){
            throw new InvalidArgumentException('Document source mismatch.');
        }

        $validation=$document;
        $validation['access']='allow';
        GlobalSearchCore::search([
            'version'=>1,'text'=>'__index_validation__','project'=>null,'type'=>null,
            'state'=>null,'role'=>null,'page'=>1,'per_page'=>1,
        ],[$validation]);

        $roles=$document['roles'];
        sort($roles,SORT_STRING);
        return [
            'version'=>$document['version'],'type'=>$document['type'],'title'=>$document['title'],
            'project'=>$document['project'],'repo'=>$document['repo'],'number_or_id'=>$document['number_or_id'],
            'state'=>$document['state'],'updated_at'=>$document['updated_at'],'snippet'=>$document['snippet'],
            'source'=>$document['source'],'canonical_url'=>$document['canonical_url'],
            'freshness'=>$document['freshness'],'roles'=>array_values(array_unique($roles)),'access'=>$document['access'],
        ];
    }

    private static function source(mixed $value): string
    {
        if(!is_string($value)||!in_array($value,self::SOURCES,true)){
            throw new InvalidArgumentException('source invalid.');
        }
        return $value;
    }

    private static function sourceIdentity(mixed $value): string
    {
        if(!is_string($value)||strlen($value)<1||strlen($value)>160||str_contains($value,'@')
            ||preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/#-]*$/D',$value)!==1
            ||preg_match('/(?:token|secret|password|cookie|authorization|dsn):/i',$value)===1){
            throw new InvalidArgumentException('source_identity invalid.');
        }
        return $value;
    }

    private static function entityKey(string $source,string $identity): string
    {
        return $source.'|'.$identity;
    }

    private static function positive(mixed $value,string $label): int
    {
        if(!is_int($value)||$value<1){
            throw new InvalidArgumentException($label.' invalid.');
        }
        return $value;
    }

    private static function sha(mixed $value,string $label): string
    {
        if(!is_string($value)||preg_match('/^[0-9a-f]{64}$/D',$value)!==1){
            throw new InvalidArgumentException($label.' invalid.');
        }
        return $value;
    }

    private static function fields(mixed $raw,array $expected,string $label): void
    {
        if(!is_array($raw)||array_is_list($raw)){
            throw new InvalidArgumentException($label.' invalid.');
        }
        $actual=array_keys($raw);
        sort($actual,SORT_STRING);
        sort($expected,SORT_STRING);
        if($actual!==$expected){
            throw new InvalidArgumentException($label.' fields invalid.');
        }
    }

    private static function hash(array $value): string
    {
        return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    }
}
