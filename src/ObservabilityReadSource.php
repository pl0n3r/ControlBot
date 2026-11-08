<?php
declare(strict_types=1);
namespace ControlBot\Observability;
use RuntimeException;
/** Private owner-only read source; missing or stale evidence fails closed. */
final class ObservabilityReadSource {
    public static function readConfigured(array $env,string $op,array $params,int $now):array {
        if(!in_array($op,['observability.snapshot','incidents.read'],true)
            ||($op==='observability.snapshot'&&$params!==[])
            ||($op==='incidents.read'&&(!isset($params['incident_ref'])
                ||count($params)!==1||!is_string($params['incident_ref'])
                ||!preg_match('/^incident:[a-f0-9]{32}$/D',$params['incident_ref']))))
            throw new RuntimeException('Invalid read operation.');
        $path=$env['CONTROLBOT_OBSERVABILITY_SNAPSHOT_PATH']??null;
        if(!is_string($path)||$path===''||strlen($path)>2048||$path[0]!=='/'
            ||str_contains($path,'..')||str_contains($path,'//')||str_contains($path,"\0"))
            throw new RuntimeException('Private snapshot unconfigured.');
        $real=realpath($path);$root=realpath($env['CONTROLBOT_DOCUMENT_ROOT']??($_SERVER['DOCUMENT_ROOT']??''));
        if($real===false||$real!==$path||is_link($path)||!is_file($path)||!is_readable($path)
            ||($root!==false&&($root===$real||str_starts_with($real,rtrim($root,'/').'/'))))
            throw new RuntimeException('Private snapshot inaccessible.');
        $stat=@stat($real);
        if(!$stat||($stat['mode']&0077)!==0||$stat['size']<2||$stat['size']>262144)
            throw new RuntimeException('Private snapshot unsafe.');
        $fp=@fopen($real,'rb');if(!is_resource($fp))throw new RuntimeException('Read failed.');
        try{
            $check=fstat($fp);
            if(!$check||$check['ino']!==$stat['ino']||$check['dev']!==$stat['dev']
                ||($check['mode']&0077)!==0)throw new RuntimeException('Changed file.');
            $raw=stream_get_contents($fp,262145);
        }finally{fclose($fp);}
        if(!is_string($raw)||strlen($raw)<2||strlen($raw)>262144)
            throw new RuntimeException('Malformed file.');
        try{$data=json_decode($raw,true,20,JSON_THROW_ON_ERROR);}
        catch(\Throwable){throw new RuntimeException('Malformed JSON.');}
        if(!is_array($data)||array_is_list($data)||($data['version']??null)!==1)
            throw new RuntimeException('Bad version.');
        $keys=array_keys($data);sort($keys);
        if($keys!==['events','incidents','observed_at','project_ids','ttl_by_source','version']
            ||!is_int($data['observed_at'])||$now<1||$data['observed_at']>$now
            ||$now-$data['observed_at']>300||!is_array($data['events'])
            ||!array_is_list($data['events'])||count($data['events'])>1000
            ||!is_array($data['project_ids'])||!array_is_list($data['project_ids'])
            ||!$data['project_ids']||count($data['project_ids'])>100
            ||!is_array($data['ttl_by_source'])||!is_array($data['incidents'])
            ||count($data['incidents'])>100)
            throw new RuntimeException('Snapshot invalid or stale.');
        if($op==='incidents.read')return ['timeline'=>$data['incidents'][$params['incident_ref']]??null];
        return ['project_ids'=>$data['project_ids'],'events'=>$data['events'],
            'ttl_by_source'=>$data['ttl_by_source']];
    }
}