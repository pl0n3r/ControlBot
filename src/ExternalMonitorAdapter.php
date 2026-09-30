<?php
declare(strict_types=1);

namespace ControlBot\Observability;

use Closure;
use InvalidArgumentException;

final class ExternalMonitorAdapter
{
    private readonly Closure $httpTransport;
    private readonly Closure $alertTransport;

    public function __construct(callable $httpTransport, callable $alertTransport)
    {
        $this->httpTransport=Closure::fromCallable($httpTransport);
        $this->alertTransport=Closure::fromCallable($alertTransport);
    }

    public function probe(array $raw): array
    {
        self::fields($raw,['version','endpoint_kind','url','observed_at','timeout_ms','max_redirects','max_body_bytes','source_ref'],'ProbeRequest');
        if($raw['version']!==1) throw new InvalidArgumentException('ProbeRequest version invalid.');
        $kind=self::choice($raw['endpoint_kind'],['health','home'],'endpoint_kind');
        $url=self::endpointUrl($raw['url'],$kind);
        $timeout=self::boundedInt($raw['timeout_ms'],250,15000,'timeout_ms');
        $redirects=self::boundedInt($raw['max_redirects'],0,3,'max_redirects');
        $bodyLimit=self::boundedInt($raw['max_body_bytes'],128,65536,'max_body_bytes');
        $result=($this->httpTransport)(['url'=>$url,'timeout_ms'=>$timeout,'max_redirects'=>$redirects,'max_body_bytes'=>$bodyLimit]);
        self::fields($result,['outcome','http_status','latency_ms','body','redirects'],'HttpResult');
        $outcome=self::choice($result['outcome'],['response','timeout','network_error'],'outcome');
        $status=$result['http_status'];$latency=$result['latency_ms'];$body=$result['body'];
        if(!is_int($latency)||$latency<0||$latency>$timeout+5000) throw new InvalidArgumentException('latency_ms invalid.');
        if(!is_int($result['redirects'])||$result['redirects']<0||$result['redirects']>$redirects) throw new InvalidArgumentException('redirect limit exceeded.');
        if(!is_string($body)||strlen($body)>$bodyLimit) throw new InvalidArgumentException('response body invalid.');
        if($outcome==='response'){
            if(!is_int($status)||$status<100||$status>599) throw new InvalidArgumentException('http_status invalid.');
        }elseif($status!==null||$body!=='') throw new InvalidArgumentException('failed probe leaked response data.');
        [$version,$sha]=self::healthMetadata($kind,$status,$body);
        return ExternalMonitorCore::probe([
            'version'=>1,'endpoint_kind'=>$kind,'observed_at'=>self::positiveInt($raw['observed_at'],'observed_at'),
            'outcome'=>$outcome,'http_status'=>$status,'latency_ms'=>$latency,'reported_version'=>$version,
            'reported_sha'=>$sha,'freshness'=>'fresh','source_ref'=>self::safeRef($raw['source_ref'],'source_ref'),
        ]);
    }

    public function deliver(array $assessment,array $context): array
    {
        self::fields($context,['version','channel','observed_at','evidence_ref'],'AlertContext');
        if($context['version']!==1||($assessment['version']??null)!==1||!is_array($assessment['alert_intent']??null))
            throw new InvalidArgumentException('Alert delivery input invalid.');
        $intent=$assessment['alert_intent'];
        self::fields($intent,['required','severity','code','external_channel_required','evidence_refs'],'AlertIntent');
        if(!is_bool($intent['required'])||!is_bool($intent['external_channel_required'])||$intent['required']!==$intent['external_channel_required'])
            throw new InvalidArgumentException('Alert intent invalid.');
        $severity=self::choice($intent['severity'],['info','warning','critical'],'severity');
        $code=self::slug($intent['code'],'code');
        $refs=self::refs($intent['evidence_refs']);
        $observed=self::positiveInt($context['observed_at'],'observed_at');
        $channel=self::choice($context['channel'],['webhook'],'channel');
        $evidence=self::safeRef($context['evidence_ref'],'evidence_ref');
        $dedupe=hash('sha256',json_encode([$severity,$code,$refs],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
        if(!$intent['required']) return ['attempted'=>false,'dedupe_key'=>$dedupe,'receipt'=>null];
        $payload=['version'=>1,'severity'=>$severity,'code'=>$code,'dedupe_key'=>$dedupe,'evidence_refs'=>$refs];
        $delivery=($this->alertTransport)($payload);
        self::fields($delivery,['status','code'],'AlertTransportResult');
        $status=self::choice($delivery['status'],['delivered','failed'],'delivery.status');
        $deliveryCode=self::slug($delivery['code'],'delivery.code');
        $deliveryId='delivery:'.substr(hash('sha256',$dedupe.'|'.$observed.'|'.$channel),0,32);
        return ['attempted'=>true,'dedupe_key'=>$dedupe,'receipt'=>[
            'delivery_id'=>$deliveryId,'channel'=>$channel,'status'=>$status,'code'=>$deliveryCode,
            'observed_at'=>$observed,'evidence_ref'=>$evidence,
        ]];
    }

    private static function healthMetadata(string $kind,mixed $status,string $body): array
    {
        if($kind!=='health'||!is_int($status)||$status<200||$status>=300||$body==='') return [null,null];
        try{$row=json_decode($body,true,16,JSON_THROW_ON_ERROR);}catch(\JsonException){return [null,null];}
        if(!is_array($row)||array_is_list($row)) return [null,null];
        $version=$row['version']??null;$sha=$row['sha']??null;
        if(!is_string($version)||preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/D',$version)!==1) $version=null;
        if(!is_string($sha)||preg_match('/^[a-f0-9]{40}$/D',$sha)!==1) $sha=null;
        return [$version,$sha];
    }

    private static function endpointUrl(mixed $value,string $kind): string
    {
        if(!is_string($value)||strlen($value)>240||str_contains($value,'@')) throw new InvalidArgumentException('url invalid.');
        $parts=parse_url($value);
        if(!is_array($parts)||($parts['scheme']??null)!=='https'||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])) throw new InvalidArgumentException('url invalid.');
        $host=$parts['host']??'';$path=$parts['path']??'/';
        if(!is_string($host)||preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/Di',$host)!==1) throw new InvalidArgumentException('url host invalid.');
        if(($kind==='health'&&$path!=='/health')||($kind==='home'&&!in_array($path,['','/'],true))) throw new InvalidArgumentException('url path invalid.');
        return $value;
    }
    private static function refs(mixed $rows): array
    {if(!is_array($rows)||!array_is_list($rows)||count($rows)>8)throw new InvalidArgumentException('evidence_refs invalid.');$out=[];foreach($rows as $r)$out[]=self::safeRef($r,'evidence_ref');sort($out,SORT_STRING);return array_values(array_unique($out));}
    private static function safeRef(mixed $value,string $label): string
    {if(!is_string($value)||strlen($value)<1||strlen($value)>180||str_contains($value,'@')||preg_match('/(?:password|passwd|secret|token|cookie|authorization|bearer|api[_ -]?key|private[_ -]?key|dsn)/i',$value)===1||preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/#-]{0,179}$/D',$value)!==1)throw new InvalidArgumentException($label.' invalid.');return $value;}
    private static function slug(mixed $value,string $label): string
    {if(!is_string($value)||preg_match('/^[a-z][a-z0-9._-]{1,79}$/D',$value)!==1)throw new InvalidArgumentException($label.' invalid.');return $value;}
    private static function choice(mixed $value,array $allowed,string $label): string
    {if(!is_string($value)||!in_array($value,$allowed,true))throw new InvalidArgumentException($label.' invalid.');return $value;}
    private static function positiveInt(mixed $value,string $label): int
    {if(!is_int($value)||$value<1)throw new InvalidArgumentException($label.' invalid.');return $value;}
    private static function boundedInt(mixed $value,int $min,int $max,string $label): int
    {if(!is_int($value)||$value<$min||$value>$max)throw new InvalidArgumentException($label.' invalid.');return $value;}
    private static function fields(mixed $row,array $expected,string $label): void
    {if(!is_array($row)||array_is_list($row)){throw new InvalidArgumentException($label.' invalid.');}$actual=array_keys($row);sort($actual);sort($expected);if($actual!==$expected)throw new InvalidArgumentException($label.' fields invalid.');}
}
