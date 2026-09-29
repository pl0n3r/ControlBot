<?php
declare(strict_types=1);

namespace ControlBot\Business;

require_once __DIR__.'/UiTheme.php';

use ControlBot\Ui\UiTheme;
use InvalidArgumentException;

final class IncidentUi
{
    private const SENSITIVE='/(?:bearer\\s+|password|passwd|token|secret|cookie|authorization|private[_ -]?key|api[_ -]?key)/i';
    private const CLASSES=['root_cause'=>'Causa raíz','independent_bug'=>'Bug independiente','contributing_factor'=>'Factor contribuyente','preventive_change'=>'Prevención','unknown'=>'Evidencia pendiente'];

    public static function render(array $timeline,array $postmortem,array $lesson): string
    {
        self::assertContracts($timeline,$postmortem,$lesson);
        $incident=self::e($timeline['incident_ref']);
        $duration=$timeline['duration_seconds']===null?'UNKNOWN':self::e((string)$timeline['duration_seconds']).' s';
        $status=$timeline['recovered_at']===null?'OPEN / UNKNOWN':'RECOVERED';
        $owner=$lesson['owner_action_required']?'OWNER ACTION REQUIRED':'NO OWNER ACTION';
        $findings='';
        foreach(self::CLASSES as $class=>$label){
            $rows=array_values(array_filter($postmortem['findings'],static fn(array $r):bool=>$r['classification']===$class));
            $findings.=self::section($label,$rows===[]?'<p class="unknown">Sin evidencia materializada.</p>':self::findingRows($rows));
        }
        $recovery='Canario #'.self::e((string)$postmortem['recovery']['canary_issue']).' → cola serial '.self::e('#'.implode(' → #',$postmortem['recovery']['serial_queue']));
        $timelineHtml='';
        foreach($timeline['events'] as $event){
            $timelineHtml.='<li><span>'.self::e((string)$event['timestamp']).' · '.self::e($event['kind']).'</span>'.self::evidence($event['evidence_ref']).'</li>';
        }
        $lessonEvidence=''; foreach($lesson['evidence_refs'] as $ref) $lessonEvidence.=self::evidence($ref);
        $lessonHtml='<h3>'.self::e($lesson['title']).'</h3><p>'.self::e($lesson['summary']).'</p>'
            .'<p>Publication: <strong>'.self::e($lesson['publication_state']).'</strong></p>'
            .'<p>Fingerprint <code>'.self::e($lesson['candidate_fingerprint']).'</code></p>'
            .'<p>Dedupe <code>'.self::e($lesson['dedupe_marker']).'</code></p>'
            .'<div class="lesson-evidence" aria-label="Lesson evidence">'.$lessonEvidence.'</div>';
        return '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>ControlBot · Incidente</title><style>'.self::styles().'</style></head>'
            .'<body><main class="shell" aria-labelledby="incident-title"><header><p class="eyebrow">CONTROLBOT / INCIDENT</p><h1 id="incident-title">'. $incident .'</h1><p class="status">'.self::e($status).' · MTTR '.$duration.'</p><p class="owner">'.self::e($owner).'</p></header>'
            .'<section class="grid"><article class="panel"><h2>Severidad / impacto</h2><p class="unknown">SEVERITY UNKNOWN · IMPACT UNKNOWN · no materializados por el core actual.</p></article>'
            .'<article class="panel"><h2>Timeline</h2><ul>'.$timelineHtml.'</ul></article>'
            .$findings
            .self::section('Mitigación / recovery','<p>'.self::e($recovery).'</p>')
            .self::section('Fix permanente','<p class="unknown">UNKNOWN · no materializado por el core actual.</p>')
            .self::section('Lesson candidate',$lessonHtml)
            .'</section></main></body></html>';
    }

    private static function assertContracts(array $timeline,array $postmortem,array $lesson): void
    {
        self::fields($timeline,['version','incident_ref','opened_at','detected_at','recovered_at','duration_seconds','events'],'timeline');
        self::fields($postmortem,['version','incident_ref','duration_seconds','findings','recovery'],'postmortem');
        self::fields($lesson,['version','incident_ref','publication_state','title','summary','root_cause_facts','independent_bugs','contributing_factors','preventive_rules','evidence_refs','owner_action_required','candidate_fingerprint','dedupe_marker'],'lesson');
        if(($timeline['version']??null)!==1||($postmortem['version']??null)!==1||($lesson['version']??null)!==1
            ||$timeline['incident_ref']!==$postmortem['incident_ref']||$timeline['incident_ref']!==$lesson['incident_ref']
            ||$timeline['duration_seconds']!==$postmortem['duration_seconds']||!is_bool($lesson['owner_action_required']))
            throw new InvalidArgumentException('Incident UI contract mismatch.');
        self::safe($timeline);self::safe($postmortem);self::safe($lesson);
    }

    private static function findingRows(array $rows): string
    {
        $html='<ul>';
        foreach($rows as $row){
            $html.='<li><span>'.self::e($row['summary']).'</span>'.self::evidence($row['evidence_ref']);
            if($row['owner_action_required']) $html.='<strong>OWNER ACTION REQUIRED</strong>';
            $html.='</li>';
        }
        return $html.'</ul>';
    }

    private static function section(string $title,string $body): string
    { return '<article class="panel"><h2>'.self::e($title).'</h2>'.$body.'</article>'; }

    private static function evidence(string $ref): string
    {
        if(str_starts_with($ref,'https://github.com/')) return '<a href="'.self::e($ref).'">Ver evidencia</a>';
        return '<code>'.self::e($ref).'</code>';
    }

    private static function fields(array $row,array $expected,string $label): void
    { $actual=array_keys($row);sort($actual);sort($expected);if($actual!==$expected) throw new InvalidArgumentException('Incident UI '.$label.' fields invalid.'); }

    private static function safe(array $value): void
    {
        $json=json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        if(strlen($json)>150000||preg_match(self::SENSITIVE,$json)===1) throw new InvalidArgumentException('Incident UI sensitive material.');
    }

    private static function e(string $value): string
    { return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }

    private static function styles(): string
    {
        return UiTheme::tokensCss().<<<'CSS'
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,system-ui,sans-serif}.shell{width:min(100%,1100px);margin:auto;padding:24px 16px 48px}header{display:grid;gap:10px;border-bottom:1px solid var(--line);padding-bottom:18px}.eyebrow,.status,.owner,code{font-family:"JetBrains Mono",ui-monospace,monospace}.eyebrow{color:var(--cyan)}h1,h2,h3,p{overflow-wrap:anywhere}.grid{display:grid;grid-template-columns:1fr;gap:12px;margin-top:18px}.panel{min-width:0;padding:15px;border:1px solid var(--line);border-radius:10px;background:var(--panel)}ul{display:grid;gap:10px;padding-left:18px}li{min-width:0}.unknown{color:var(--amber)}.owner{color:var(--amber);font-weight:700}a{color:var(--cyan)}a:focus-visible{outline:2px solid var(--amber);outline-offset:3px}@media(min-width:760px){.shell{padding:40px 28px 64px}.grid{grid-template-columns:repeat(2,minmax(0,1fr))}.panel:nth-child(2){grid-column:span 2}}@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation:none!important;transition:none!important;scroll-behavior:auto!important}}
CSS;
    }
}
