<?php
declare(strict_types=1);

namespace ControlBot\Observability;

require_once __DIR__.'/ExternalMonitorCore.php';
require_once __DIR__.'/ControlBotBackup.php';
require_once __DIR__.'/UiTheme.php';

use ControlBot\Backup\ControlBotBackup;
use ControlBot\Ui\UiTheme;
use InvalidArgumentException;

final class ObservabilityUi
{
    private const STATES=['healthy','degraded','down','unknown'];

    public static function render(?array $monitorRaw,?array $backupRaw,?array $restoreRaw): string
    {
        $monitor=self::monitor($monitorRaw);
        $backup=$backupRaw===null?null:ControlBotBackup::backupReceipt($backupRaw);
        if($backup===null&&$restoreRaw!==null) throw new InvalidArgumentException('Restore without backup invalid.');
        $restorable=$backupRaw===null
            ? ['restorable'=>false,'reason'=>'backup_evidence_missing']
            : ControlBotBackup::restorable($backupRaw,$restoreRaw);
        $restore=$restorable['restorable']
            ? ControlBotBackup::restoreReceipt($backupRaw,$restoreRaw)
            : null;

        $probe=$monitor['probe'];
        $probeBody=$probe===null
            ? self::empty('Sin evidencia de probe.')
            : self::pairs([
                'Freshness'=>$probe['freshness'],
                'Observado'=>self::time($probe['observed_at']),
                'HTTP'=>$probe['http_status']===null?'unknown':(string)$probe['http_status'],
                'Latencia'=>$probe['latency_ms']===null?'unknown':$probe['latency_ms'].' ms',
                'Versión'=>$probe['reported_version']??'unknown',
                'SHA'=>$probe['reported_sha']===null?'unknown':substr($probe['reported_sha'],0,12),
            ]);
        $backupBody=$backup===null
            ? self::empty('Sin evidencia de backup.')
            : self::pairs([
                'Backup'=>$backup['backup_id'],'Completado'=>self::time($backup['completed_at']),
                'Tamaño'=>$backup['size_bytes'].' bytes','Cifrado'=>$backup['encryption'],'Estado'=>$backup['status'],
            ]);
        $restoreBody=$restore===null
            ? self::empty('Sin restore drill verificado.')
            : self::pairs([
                'Restore'=>$restore['restore_id'],'Backup'=>$restore['backup_id'],'Completado'=>self::time($restore['completed_at']),
                'Entorno aislado'=>$restore['target_environment'],'Estado'=>$restore['status'],
            ]);
        $alert=$monitor['alert_intent'];
        $alertBody=$monitorRaw===null
            ? self::empty('Estado de alerta externa desconocido.')
            : self::pairs([
                'Requerida'=>$alert['required']?'sí':'no','Canal externo'=>$alert['external_channel_required']?'requerido':'no requerido',
                'Severidad'=>$alert['severity'],'Código'=>$alert['code'],'Evidencias'=>(string)count($alert['evidence_refs']),
            ]);

        return '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
            .'<title>ControlBot · Observabilidad y recuperación</title><style>'.self::styles().'</style></head>'
            .'<body><main class="shell" aria-labelledby="obs-title"><header><p class="eyebrow">CONTROLBOT / WATCHDOG</p>'
            .'<h1 id="obs-title">Observabilidad y recuperación</h1><p class="lede">Vista read-only de evidencia validada. No ejecuta alertas, backups ni restores.</p>'
            .'<p class="state state-'.self::e($monitor['application_state']).'" role="status">Monitor: '.self::e($monitor['application_state']).'</p></header>'
            .'<section class="grid" aria-label="Estado del vigilante y recuperación">'
            .self::card('Último probe',$probeBody)
            .self::card('Alerta externa',$alertBody)
            .self::card('Último backup',$backupBody)
            .self::card('Último restore probado',$restoreBody)
            .self::card('Restaurabilidad',self::pairs(['Restorable'=>$restorable['restorable']?'sí':'no','Razón'=>$restorable['reason']]))
            .'</section></main></body></html>';
    }

    private static function monitor(?array $raw): array
    {
        if($raw===null) return ['application_state'=>'unknown','probe'=>null,'alert_intent'=>['required'=>false,'external_channel_required'=>false,'severity'=>'warning','code'=>'monitor_evidence_missing','evidence_refs'=>[]]];
        self::fields($raw,['version','application_state','private_workflow_state','owner_capacity_state','billing_mechanism_state','reasons','alert_intent','probe','latest_private_workflow','latest_public_workflow'],'monitor');
        if($raw['version']!==1||!in_array($raw['application_state'],self::STATES,true)) throw new InvalidArgumentException('Monitor state invalid.');
        if($raw['probe']!==null) self::probe($raw['probe']);
        self::alert($raw['alert_intent']);
        return ['application_state'=>$raw['application_state'],'probe'=>$raw['probe'],'alert_intent'=>$raw['alert_intent']];
    }
    private static function probe(mixed $probe): void
    {
        self::fields($probe,['version','endpoint_kind','observed_at','outcome','http_status','latency_ms','reported_version','reported_sha','freshness','source_ref'],'probe');
        if($probe['version']!==1||!is_int($probe['observed_at'])||$probe['observed_at']<0||!in_array($probe['freshness'],['fresh','stale','unknown'],true))
            throw new InvalidArgumentException('Probe projection invalid.');
    }
    private static function alert(mixed $alert): void
    {
        self::fields($alert,['required','severity','code','external_channel_required','evidence_refs'],'alert');
        if(!is_bool($alert['required'])||!is_bool($alert['external_channel_required'])||!is_string($alert['severity'])||!is_string($alert['code'])||!is_array($alert['evidence_refs'])||!array_is_list($alert['evidence_refs']))
            throw new InvalidArgumentException('Alert projection invalid.');
    }
    private static function fields(mixed $row,array $expected,string $label): void
    {if(!is_array($row)||array_is_list($row))throw new InvalidArgumentException($label.' invalid.');$keys=array_keys($row);sort($keys);sort($expected);if($keys!==$expected)throw new InvalidArgumentException($label.' fields invalid.');}
    private static function card(string $title,string $body): string{return '<article class="panel"><h2>'.self::e($title).'</h2>'.$body.'</article>';}
    private static function empty(string $text): string{return '<p class="empty">'.self::e($text).'</p>';}
    private static function pairs(array $rows): string{$html='<dl>';foreach($rows as $k=>$v)$html.='<div><dt>'.self::e((string)$k).'</dt><dd>'.self::e((string)$v).'</dd></div>';return $html.'</dl>';}
    private static function time(int $timestamp): string{return gmdate('Y-m-d H:i:s',$timestamp).' UTC';}
    private static function e(string $value): string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private static function styles(): string
    {
        return UiTheme::tokensCss().<<<'CSS'
*{box-sizing:border-box}body{margin:0;min-height:100vh;background:var(--bg);color:var(--text);font-family:Inter,system-ui,sans-serif}.shell{width:min(100%,1080px);margin:auto;padding:24px 16px 48px}header{display:grid;gap:10px;padding-bottom:18px;border-bottom:1px solid var(--line)}.eyebrow{margin:0;color:var(--cyan);letter-spacing:.08em;font:700 .72rem/1.2 "JetBrains Mono",ui-monospace,monospace}h1,h2{margin:0}h1{font-size:clamp(1.8rem,8vw,2.8rem)}h2{font-size:1rem}.lede,.empty,dt{color:var(--muted)}.lede{margin:0;line-height:1.5}.state{width:fit-content;margin:2px 0 0;padding:7px 10px;border:1px solid var(--line-strong);border-radius:999px;font:700 .76rem/1.2 "JetBrains Mono",ui-monospace,monospace}.state-healthy{color:var(--green)}.state-degraded,.state-unknown{color:var(--amber)}.state-down{color:var(--red)}.grid{display:grid;grid-template-columns:1fr;gap:12px;margin-top:18px}.panel{min-width:0;padding:16px;border:1px solid var(--line);border-radius:10px;background:var(--panel)}dl{display:grid;gap:6px;margin:12px 0 0}dl div{display:flex;justify-content:space-between;gap:12px;padding:6px 0;border-bottom:1px solid var(--line)}dd{margin:0;text-align:right;overflow-wrap:anywhere}.empty{margin:12px 0 0}:focus-visible{outline:2px solid var(--amber);outline-offset:3px}@media(min-width:760px){.shell{padding:40px 28px 64px}.grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation:none!important;transition:none!important;scroll-behavior:auto!important}}
CSS;
    }
}
