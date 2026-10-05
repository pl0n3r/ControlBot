<?php
declare(strict_types=1);

namespace ControlBot\Production;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class HostingerPublicApi
{
    public const BASE_URL='https://developers.hostinger.com';
    private const MAX_BODY=1_000_000;

    public static function request(
        string $method,
        string $url,
        string $bearer,
        callable $transport,
    ): array {
        self::target($method,$url);
        self::bearer($bearer);
        $response=$transport($method,$url,[
            'Accept'=>'application/json',
            'Content-Type'=>'application/json',
            'Authorization'=>'Bearer '.$bearer,
        ]);
        if(
            !is_array($response)
            || !is_int($response['status']??null)
            || !is_array($response['headers']??null)
            || !is_string($response['body']??null)
        ){
            throw new RuntimeException('Hostinger transport invalid.');
        }
        if(strlen($response['body'])>self::MAX_BODY){
            throw new RuntimeException('Hostinger payload too large.');
        }
        if($response['status']!==200){
            throw new RuntimeException('Hostinger API read failed.');
        }
        try{
            $json=json_decode($response['body'],true,64,JSON_THROW_ON_ERROR);
        }catch(JsonException){
            throw new RuntimeException('Hostinger JSON invalid.');
        }
        if(!is_array($json)){
            throw new RuntimeException('Hostinger JSON invalid.');
        }
        return $json;
    }

    private static function target(string $method,string $url): void
    {
        if($method!=='GET'){
            throw new InvalidArgumentException('Hostinger method denied.');
        }
        $parts=parse_url($url);
        if(
            !is_array($parts)
            || ($parts['scheme']??null)!=='https'
            || ($parts['host']??null)!=='developers.hostinger.com'
            || isset($parts['user'],$parts['pass'],$parts['port'])
        ){
            throw new InvalidArgumentException('Hostinger URL denied.');
        }
        $path=$parts['path']??'';
        $allowed=$path==='/api/hosting/v1/websites'
            || preg_match(
                '~^/api/hosting/v1/accounts/[A-Za-z0-9._-]{2,64}/cron-jobs(?:/[A-Za-z0-9._-]{1,80}/output)?$~D',
                $path,
            )===1;
        if(!$allowed){
            throw new InvalidArgumentException('Hostinger path denied.');
        }
    }

    private static function bearer(string $value): void
    {
        if(
            $value==='' || strlen($value)>1000
            || preg_match('/[\x00-\x20\x7f]/',$value)===1
        ){
            throw new InvalidArgumentException('Hostinger credential invalid.');
        }
    }
}
