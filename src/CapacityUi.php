<?php
declare(strict_types=1);

namespace ControlBot\Ui;

use InvalidArgumentException;

final class CapacityUi
{
    public static function render(array $view): string
    {
        self::fields($view, ['state','presence','scheduler','message'], 'CapacityView');
        $state=self::enum($view['state'], ['loading','empty','error','ready'], 'state');
        $message=self::text($view['message'], 'message', 240, true);

        if($state!=='ready'){
            if($view['presence']!==null || $view['scheduler']!==null)
                throw new InvalidArgumentException('Non-ready capacity view must not carry snapshots.');
            $label=match($state){
                'loading'=>'Cargando capacidad…',
                'empty'=>'Sin señales de capacidad.',
                'error'=>'No fue posible cargar capacidad.',
            };
            return self::shell('<section class="capacity-state capacity-'.$state.'" data-state="'.$state.'" role="status">'
                .'<h1>AI Capacity</h1><p>'.$label.'</p>'
                .($message===null?'':'<p class="capacity-detail">'.self::e($message).'</p>')
                .'</section>');
        }

        if(!is_array($view['presence']) || !is_array($view['scheduler']))
            throw new InvalidArgumentException('Ready capacity view requires authoritative snapshots.');
        $presence=self::presence($view['presence']);
        $scheduler=self::scheduler($view['scheduler'], $presence['idle_capacity']);

        $metrics=[
            ['Open sessions', count($presence['sessions']), 'open-sessions'],
            ['Healthy sessions', $presence['healthy_sessions'], 'healthy-sessions'],
            ['Idle capacity', $presence['idle_capacity'], 'idle-capacity'],
            ['Dispatchable capacity', $scheduler['dispatchable_capacity'], 'dispatchable-capacity'],
        ];
        $cards='';
        foreach($metrics as [$label,$value,$key]){
            $cards.='<article class="capacity-card" data-metric="'.$key.'"><span>'.$label.'</span><strong>'.$value.'</strong></article>';
        }

        $status='<div class="capacity-status" data-capacity-state="'.self::e($presence['capacity_state']).'">'
            .'<strong>Capacity state:</strong> '.self::e($presence['capacity_state'])
            .' · <strong>Presence:</strong> '.self::e($presence['presence_state'])
            .'</div>';

        $accounts='';
        foreach($presence['accounts'] as $account){
            $accounts.='<li data-account-state="'.self::e($account['observed_state']).'">'
                .'<strong>'.self::e($account['account_id']).'</strong>'
                .' <span>'.self::e(self::stateLabel($account['observed_state'])).'</span>'
                .' <span>idle '.$account['idle_sessions'].'</span>'
                .' <span>free '.$account['free_capacity'].'</span>'
                .'</li>';
        }
        if($accounts==='') $accounts='<li class="capacity-empty-row">No accounts observed.</li>';

        $sessionRows='';
        foreach($presence['sessions'] as $session){
            $sessionRows.='<li data-freshness="'.self::e($session['freshness']).'">'
                .'<strong>'.self::e($session['session_id']).'</strong>'
                .' <span>'.self::e($session['state']).'</span>'
                .' <span>'.self::e(self::stateLabel($session['freshness'])).'</span>'
                .'</li>';
        }
        if($sessionRows==='') $sessionRows='<li class="capacity-empty-row">No sessions observed.</li>';

        $html='<section class="capacity-view" data-state="ready"><h1>AI Capacity</h1>'
            .$status
            .'<div class="capacity-metrics">'.$cards.'</div>'
            .'<div class="capacity-columns"><section><h2>Accounts</h2><ul>'.$accounts.'</ul></section>'
            .'<section><h2>Sessions</h2><ul>'.$sessionRows.'</ul></section></div>'
            .'</section>';
        return self::shell($html);
    }

    private static function presence(array $raw): array
    {
        self::fields($raw,[
            'version','policy_ref','observed_at','presence_state','capacity_state',
            'healthy_sessions','idle_capacity','sessions','accounts',
        ],'PresenceSnapshot');
        if($raw['version']!==1 || $raw['policy_ref']!=='factory-dispatcher-v2'
            || !is_int($raw['observed_at']) || $raw['observed_at']<0
            || !in_array($raw['presence_state'],['solo','multi','unknown'],true)
            || !in_array($raw['capacity_state'],['idle_capacity','saturated','degraded','unknown'],true)
            || !is_int($raw['healthy_sessions']) || $raw['healthy_sessions']<0
            || !is_int($raw['idle_capacity']) || $raw['idle_capacity']<0
            || !is_array($raw['sessions']) || !array_is_list($raw['sessions'])
            || !is_array($raw['accounts']) || !array_is_list($raw['accounts']))
            throw new InvalidArgumentException('Presence snapshot invalid.');
        if(($raw['capacity_state']==='idle_capacity')!==($raw['idle_capacity']>0))
            throw new InvalidArgumentException('Presence capacity state mismatch.');
        if($raw['healthy_sessions']>count($raw['sessions']))
            throw new InvalidArgumentException('Healthy sessions exceed open sessions.');

        $sessions=[];
        foreach($raw['sessions'] as $row){
            if(!is_array($row)) throw new InvalidArgumentException('Presence session invalid.');
            foreach(['session_id','state','freshness'] as $key)
                if(!array_key_exists($key,$row) || !is_string($row[$key])) throw new InvalidArgumentException('Presence session invalid.');
            if(!in_array($row['freshness'],['healthy','stale','offline','unknown'],true))
                throw new InvalidArgumentException('Presence session freshness invalid.');
            $sessions[]=['session_id'=>self::ref($row['session_id']),'state'=>self::label($row['state']),'freshness'=>$row['freshness']];
        }

        $accounts=[];
        foreach($raw['accounts'] as $row){
            self::fields($row,['account_id','eligible','free_capacity','idle_sessions','observed_state','observed_at'],'PresenceAccount');
            if(!is_bool($row['eligible']) || !is_int($row['free_capacity']) || $row['free_capacity']<0
                || !is_int($row['idle_sessions']) || $row['idle_sessions']<0
                || !is_int($row['observed_at']) || $row['observed_at']<0
                || !in_array($row['observed_state'],['healthy','saturated','rate_limited','requires_login','offline','unknown'],true))
                throw new InvalidArgumentException('Presence account invalid.');
            $accounts[]=[
                'account_id'=>self::ref($row['account_id']),
                'free_capacity'=>$row['free_capacity'],
                'idle_sessions'=>$row['idle_sessions'],
                'observed_state'=>$row['observed_state'],
            ];
        }

        return [
            'presence_state'=>$raw['presence_state'],'capacity_state'=>$raw['capacity_state'],
            'healthy_sessions'=>$raw['healthy_sessions'],'idle_capacity'=>$raw['idle_capacity'],
            'sessions'=>$sessions,'accounts'=>$accounts,
        ];
    }

    private static function scheduler(array $raw,int $idle): array
    {
        foreach(['version','policy_ref','authoritative_idle_capacity','dispatchable_capacity'] as $key)
            if(!array_key_exists($key,$raw)) throw new InvalidArgumentException('Scheduler snapshot invalid.');
        if($raw['version']!==1 || $raw['policy_ref']!=='factory-dispatcher-v2'
            || !is_int($raw['authoritative_idle_capacity']) || $raw['authoritative_idle_capacity']<0
            || !is_int($raw['dispatchable_capacity']) || $raw['dispatchable_capacity']<0
            || $raw['authoritative_idle_capacity']!==$idle
            || $raw['dispatchable_capacity']>$idle)
            throw new InvalidArgumentException('Scheduler snapshot invalid.');
        return ['dispatchable_capacity'=>$raw['dispatchable_capacity']];
    }

    private static function shell(string $body): string
    {
        return '<div class="capacity-shell">'.$body
            .'<style>.capacity-shell{font-family:system-ui,sans-serif;max-width:1100px;margin:auto;padding:16px}'
            .'.capacity-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:16px 0}'
            .'.capacity-card{border:1px solid currentColor;border-radius:12px;padding:14px;display:grid;gap:8px}'
            .'.capacity-card strong{font-size:1.75rem}.capacity-columns{display:grid;grid-template-columns:1fr 1fr;gap:16px}'
            .'.capacity-columns ul{padding-left:20px}.capacity-status{overflow-wrap:anywhere}'
            .'@media(max-width:640px){.capacity-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}'
            .'.capacity-columns{grid-template-columns:1fr}.capacity-shell{padding:12px}}'
            .'</style></div>';
    }

    private static function stateLabel(string $state): string
    {
        return match($state){
            'rate_limited'=>'rate limited',
            'requires_login'=>'login required',
            'stale'=>'stale',
            'offline'=>'offline',
            'unknown'=>'unknown',
            'degraded'=>'degraded',
            default=>$state,
        };
    }

    private static function text(mixed $value,string $label,int $max,bool $nullable=false): ?string
    {
        if($nullable && $value===null) return null;
        if(!is_string($value) || strlen($value)>$max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$value)===1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function label(mixed $value): string
    {
        return self::text($value,'label',80) ?? '';
    }

    private static function ref(mixed $value): string
    {
        if(!is_string($value) || strlen($value)<1 || strlen($value)>180 || str_contains($value,'@')
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/#-]*$/D',$value)!==1)
            throw new InvalidArgumentException('reference invalid.');
        return $value;
    }

    private static function enum(mixed $value,array $allowed,string $label): string
    {
        if(!is_string($value)||!in_array($value,$allowed,true)) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function fields(mixed $row,array $expected,string $label): void
    {
        if(!is_array($row)||array_is_list($row)) throw new InvalidArgumentException($label.' invalid.');
        $keys=array_keys($row); sort($keys); sort($expected);
        if($keys!==$expected) throw new InvalidArgumentException($label.' fields invalid.');
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
    }
}
