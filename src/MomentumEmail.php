<?php
declare(strict_types=1);

namespace ControlBot\Momentum;

use InvalidArgumentException;

final class MomentumEmail
{
    private const MESSAGE_CLASSES=['marketing','transactional'];
    private const CONSENT=['granted','denied','unknown'];
    private const BINARY_STATE=['active','clear','unknown'];
    private const FRESHNESS=['current','stale','unknown'];

    public static function program(array $raw,array $campaignRaw,array $brandRaw): array
    {
        $campaign=MomentumCampaign::campaign($campaignRaw,$brandRaw);
        self::fields($raw,[
            'version','email_program_id','venture_id','campaign_ref','audience_ref','message_class','execution',
        ],'EmailProgram');
        if($raw['version']!==1||$raw['execution']!==false)
            throw new InvalidArgumentException('EmailProgram version or execution invalid.');
        $venture=self::venture($raw['venture_id']);
        $campaignRef=self::opaque($raw['campaign_ref'],'campaign');
        $audienceRef=self::opaque($raw['audience_ref'],'audience');
        if($venture!==$campaign['venture_id']||$campaignRef!==$campaign['campaign_id']
            ||$audienceRef!==$campaign['audience_ref']||!in_array('email',$campaign['channels'],true))
            throw new InvalidArgumentException('EmailProgram campaign scope mismatch.');
        return [
            'version'=>1,
            'email_program_id'=>self::opaque($raw['email_program_id'],'email-program'),
            'venture_id'=>$venture,
            'campaign_ref'=>$campaignRef,
            'audience_ref'=>$audienceRef,
            'message_class'=>self::choice($raw['message_class'],self::MESSAGE_CLASSES,'message_class'),
            'execution'=>false,
        ];
    }

    public static function audienceState(array $raw,array $programRaw,array $campaignRaw,array $brandRaw): array
    {
        $program=self::program($programRaw,$campaignRaw,$brandRaw);
        self::fields($raw,[
            'version','email_program_id','venture_id','audience_ref','consent','suppression','unsubscribe',
        ],'EmailAudienceState');
        if($raw['version']!==1) throw new InvalidArgumentException('EmailAudienceState version invalid.');
        $programId=self::opaque($raw['email_program_id'],'email-program');
        $venture=self::venture($raw['venture_id']);
        $audience=self::opaque($raw['audience_ref'],'audience');
        if($programId!==$program['email_program_id']||$venture!==$program['venture_id']||$audience!==$program['audience_ref'])
            throw new InvalidArgumentException('EmailAudienceState scope mismatch.');

        $consent=self::signal($raw['consent'],'consent',self::CONSENT);
        $suppression=self::signal($raw['suppression'],'suppression',self::BINARY_STATE);
        $unsubscribe=self::signal($raw['unsubscribe'],'unsubscribe',self::BINARY_STATE);
        $marketingEligible=$program['message_class']==='marketing'
            &&self::current($consent,'granted')
            &&self::current($suppression,'clear')
            &&self::current($unsubscribe,'clear');

        return [
            'version'=>1,'email_program_id'=>$programId,'venture_id'=>$venture,'audience_ref'=>$audience,
            'message_class'=>$program['message_class'],'consent'=>$consent,'suppression'=>$suppression,
            'unsubscribe'=>$unsubscribe,'marketing_eligible'=>$marketingEligible,'execution'=>false,
        ];
    }

    private static function signal(mixed $raw,string $namespace,array $states): array
    {
        self::fields($raw,['state_ref','status','source_ref','observed_at','freshness'],ucfirst($namespace).'Signal');
        return [
            'state_ref'=>self::opaque($raw['state_ref'],$namespace),
            'status'=>self::choice($raw['status'],$states,'status'),
            'source_ref'=>self::opaque($raw['source_ref'],'source'),
            'observed_at'=>self::natural($raw['observed_at'],'observed_at'),
            'freshness'=>self::choice($raw['freshness'],self::FRESHNESS,'freshness'),
        ];
    }

    private static function current(array $signal,string $status): bool
    {
        return $signal['freshness']==='current'&&$signal['status']===$status;
    }

    private static function opaque(mixed $value,string $namespace): string
    {
        if(is_string($value)&&preg_match('/^'.preg_quote($namespace,'/').':[a-f0-9]{32}$/D',$value)===1) return $value;
        throw new InvalidArgumentException($namespace.' ref invalid.');
    }

    private static function venture(mixed $value): string
    {
        if(is_string($value)&&preg_match('/^[a-z][a-z0-9-]{1,63}$/D',$value)===1) return $value;
        throw new InvalidArgumentException('venture_id invalid.');
    }

    private static function natural(mixed $value,string $label): int
    {
        if(is_int($value)&&$value>=0) return $value;
        throw new InvalidArgumentException($label.' invalid.');
    }

    private static function choice(mixed $value,array $allowed,string $label): string
    {
        if(is_string($value)&&in_array($value,$allowed,true)) return $value;
        throw new InvalidArgumentException($label.' invalid.');
    }

    private static function fields(mixed $row,array $expected,string $label): void
    {
        if(!is_array($row)||array_is_list($row)) throw new InvalidArgumentException($label.' invalid.');
        $actual=array_keys($row);sort($actual);sort($expected);
        if($actual!==$expected) throw new InvalidArgumentException($label.' fields invalid.');
    }
}
