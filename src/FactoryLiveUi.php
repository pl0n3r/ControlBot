<?php
declare(strict_types=1);

namespace ControlBot\Business;

require_once __DIR__.'/UiTheme.php';

use ControlBot\Ui\UiTheme;
use InvalidArgumentException;

final class FactoryLiveUi
{
    private const SECTIONS=[
        'owner_decisions'=>'Decisiones del dueño','batches'=>'Tandas','releases'=>'Releases',
        'blockers'=>'Bloqueos','production'=>'Producción','quality'=>'Calidad',
        'work'=>'Trabajo en curso','learning'=>'Aprendizaje',
    ];
    private const MATRIX_HEADER=['batches'=>'Tanda','owner_decisions'=>'Decisiones','releases'=>'Releases','blockers'=>'Bloqueos','incidents'=>'Incidentes'];
    private const DEPARTMENT_LABELS=['governance'=>'Gobernanza','sre_infra_dba'=>'SRE / Infra / DBA','security'=>'Seguridad','qa_engineering'=>'QA / Ingeniería','legal_privacy'=>'Legal / Privacidad'];
    private const STATES=['healthy','degraded','critical','unknown','blocked','pending'];
    private const FRESH=['current','stale','unknown'];
    private const MATRIX_STATUS=['GREEN','AMBER','RED','UNKNOWN','STALE'];
    private const SENSITIVE='/(?:password|passwd|secret|token|cookie|authorization|bearer|private[_ -]?key|api[_ -]?key|dsn)/i';

    public static function render(array $snapshot,?array $matrix=null): string
    {
        self::fields($snapshot,['version','observed_at','sections','tool_usage','fingerprint'],'snapshot');
        if($snapshot['version']!==1||!is_int($snapshot['observed_at'])||!is_array($snapshot['sections']))
            throw new InvalidArgumentException('Factory live UI snapshot invalid.');
        self::safe($snapshot); if($matrix!==null)self::safe($matrix);
        $body='';
        foreach(self::SECTIONS as $key=>$label){
            $rows=$snapshot['sections'][$key]??null;
            if(!is_array($rows)||!array_is_list($rows)||$rows===[])throw new InvalidArgumentException('Factory live UI section invalid.');
            $cards=''; foreach($rows as $row)$cards.=self::card($row,$key);
            $body.='<section class="panel section" data-section="'.$key.'"><p class="eyebrow">'.self::e(strtoupper($label)).'</p><h2>'.self::e($label).'</h2><div class="signal-grid">'.$cards.'</div></section>';
        }
        $tool=self::card($snapshot['tool_usage'],'tool_usage');
        $matrixHtml=$matrix===null?'':self::matrix($matrix);
        return '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>ControlBot · Fábrica viva</title><style>'.self::styles().'</style></head><body><main class="shell" aria-labelledby="factory-live-title"><header class="top"><p class="eyebrow"><span>CONTROLBOT</span> / FÁBRICA VIVA</p><h1 id="factory-live-title">Fábrica viva</h1><p>Solo lectura · evidencia, freshness y antigüedad visibles.</p></header>'.$matrixHtml.$body.'<section class="panel section" data-section="tool_usage"><p class="eyebrow">HERRAMIENTAS</p><h2>Uso y costes</h2><div class="signal-grid">'.$tool.'</div></section></main></body></html>';
    }

    private static function matrix(array $matrix): string
    {
        self::fields($matrix,['version','header','projects','departments','cells'],'matrix');
        if($matrix['version']!==1||!is_array($matrix['header'])||!is_array($matrix['projects'])||!is_array($matrix['departments'])||!is_array($matrix['cells']))
            throw new InvalidArgumentException('Factory live matrix UI invalid.');
        $header='';
        foreach(self::MATRIX_HEADER as $key=>$label){
            $item=$matrix['header'][$key]??null;self::fields($item,['status','count','signal'],'matrix.header');
            $status=self::one($item['status'],self::MATRIX_STATUS,'matrix.status');
            if(!is_int($item['count'])||$item['count']<0)throw new InvalidArgumentException('Matrix count invalid.');
            $header.='<article class="matrix-summary status-'.strtolower($status).'"><span>'.self::e($label).'</span><strong>'.self::e($status).'</strong><small>'.self::e((string)$item['count']).' señal(es)</small>'.self::matrixSignal($item['signal']).'</article>';
        }
        $thead='<tr><th scope="col">Departamento</th>';
        foreach($matrix['projects'] as $project)$thead.='<th scope="col">'.self::e(self::text($project)).'</th>';
        $thead.='</tr>';$rows='';
        foreach($matrix['departments'] as $department){
            if(!is_string($department)||!isset(self::DEPARTMENT_LABELS[$department]))throw new InvalidArgumentException('Matrix department invalid.');
            $rows.='<tr><th scope="row">'.self::e(self::DEPARTMENT_LABELS[$department]).'</th>';
            foreach($matrix['projects'] as $project){
                $cell=$matrix['cells'][$project][$department]??null;self::fields($cell,['status','signal'],'matrix.cell');
                $status=self::one($cell['status'],self::MATRIX_STATUS,'matrix.cell.status');
                $rows.='<td class="matrix-cell status-'.strtolower($status).'"><strong>'.self::e($status).'</strong>'.self::matrixSignal($cell['signal']).'</td>';
            }
            $rows.='</tr>';
        }
        return '<section class="panel matrix-section" data-section="operations_matrix"><p class="eyebrow">MATRIZ OPERATIVA</p><h2>Departamentos × proyectos</h2><div class="ops-header">'.$header.'</div><div class="matrix-scroll" tabindex="0" aria-label="Matriz operativa desplazable"><table><thead>'.$thead.'</thead><tbody>'.$rows.'</tbody></table></div></section>';
    }

    private static function matrixSignal(mixed $signal): string
    {
        if($signal===null)return '<small class="matrix-meta">Sin evidencia</small>';
        self::fields($signal,['id','label','status','source_ref','observed_at','freshness','age_seconds','evidence_href'],'matrix.signal');
        $status=self::one($signal['status'],self::MATRIX_STATUS,'matrix.signal.status');
        $source=$signal['source_ref']===null?'UNKNOWN':self::text($signal['source_ref']);
        $age=$signal['age_seconds']===null?'UNKNOWN':self::scalar($signal['age_seconds']).'s';
        $link='';
        if($signal['evidence_href']!==null){
            $href=self::matrixHref($signal['evidence_href']);
            $link='<a class="issue-link" href="'.self::e($href).'">Evidencia</a>';
        }
        return '<small class="matrix-meta">'.self::e(self::text($signal['label'])).' · '.self::e($source).' · '.self::e($age).' · '.self::e($status).'</small>'.$link;
    }

    private static function card(mixed $row,string $section): string
    {
        self::fields($row,['id','authority','state','source_ref','observed_at','freshness','age_seconds','data'],$section);
        $state=self::one($row['state'],self::STATES,'state'); $fresh=self::one($row['freshness'],self::FRESH,'freshness');
        if($fresh!=='current'&&$state==='healthy')throw new InvalidArgumentException('Non-current signal cannot be green.');
        $source=$row['source_ref']===null?'UNKNOWN':self::text($row['source_ref']);
        $age=$row['age_seconds']===null?'UNKNOWN':self::scalar($row['age_seconds']).'s';
        $title=is_string($row['data']['title']??null)?self::text($row['data']['title']):self::text($row['id']);
        $link='';
        if($section==='owner_decisions'&&is_array($row['data'])){
            $ref=$row['data']['issue_ref']??null; $href=self::issueHref($ref);
            if($href!==null)$link='<a class="issue-link" href="'.self::e($href).'">Abrir Issue de decisión</a>';
        }
        return '<article class="signal state-'.self::e($state).' fresh-'.self::e($fresh).'"><div class="signal-head"><strong>'.self::e(strtoupper($state)).'</strong><span>'.self::e(strtoupper($fresh)).'</span></div><h3>'.self::e($title).'</h3>'.self::dataHtml($row['data']).'<dl><div><dt>Fuente</dt><dd>'.self::e($source).'</dd></div><div><dt>Edad</dt><dd>'.self::e($age).'</dd></div></dl>'.$link.'</article>';
    }

    private static function dataHtml(array $data): string
    {
        $out=''; foreach($data as $key=>$value){
            if($key==='issue_ref'||$key==='title')continue;
            $out.='<div><dt>'.self::e((string)$key).'</dt><dd>'.self::e(self::value($value)).'</dd></div>';
        }
        return $out===''?'':'<dl class="details">'.$out.'</dl>';
    }
    private static function value(mixed $v): string { if($v===null)return 'UNKNOWN';if(is_bool($v))return $v?'sí':'no';if(is_string($v)||is_int($v)||is_float($v))return (string)$v;if(is_array($v))return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);throw new InvalidArgumentException('Data invalid.'); }
    private static function issueHref(mixed $ref): ?string
    {
        if(!is_string($ref))return null;
        if(preg_match('~^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/issues/[1-9][0-9]*$~D',$ref)===1)return $ref;
        if(preg_match('~^github:([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)#([1-9][0-9]*)$~D',$ref,$m)===1)return 'https://github.com/'.$m[1].'/'.$m[2].'/issues/'.$m[3];
        return null;
    }
    private static function matrixHref(mixed $ref): string
    {
        if(!is_string($ref)||preg_match('~^https://(?:github\.com|sonarcloud\.io)/[^\s<>"\']+$~D',$ref)!==1)
            throw new InvalidArgumentException('Matrix evidence href invalid.');
        return $ref;
    }
    private static function fields(mixed $row,array $keys,string $label): void { if(!is_array($row)||array_is_list($row))throw new InvalidArgumentException($label.' invalid.');$a=array_keys($row);sort($a);sort($keys);if($a!==$keys)throw new InvalidArgumentException($label.' fields invalid.'); }
    private static function one(mixed $v,array $allowed,string $label): string { if(!is_string($v)||!in_array($v,$allowed,true))throw new InvalidArgumentException($label.' invalid.');return $v; }
    private static function scalar(mixed $v): string { if(!is_int($v)||$v<0)throw new InvalidArgumentException('Age invalid.');return (string)$v; }
    private static function text(mixed $v): string { if(!is_string($v)||trim($v)===''||preg_match('/[\x00-\x1f\x7f]/u',$v)===1||preg_match(self::SENSITIVE,$v)===1)throw new InvalidArgumentException('Unsafe text.');return trim($v); }
    private static function safe(mixed $v): void { if(is_array($v)){foreach($v as $k=>$x){if(is_string($k)&&preg_match(self::SENSITIVE,$k)===1)throw new InvalidArgumentException('Sensitive field.');self::safe($x);}return;}if(is_string($v)&&preg_match(self::SENSITIVE,$v)===1)throw new InvalidArgumentException('Sensitive value.'); }
    private static function e(string $v): string { return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }

    private static function styles(): string
    {
        return UiTheme::tokensCss().<<<'CSS'
*{box-sizing:border-box}html{background:var(--bg)}body{margin:0;color:var(--text);background:var(--bg);font-family:Inter,system-ui,sans-serif}.shell{width:min(100%,1180px);margin:auto;padding:24px 16px 48px}.top{padding:8px 0 20px;border-bottom:1px solid var(--line)}.top p{color:var(--muted);line-height:1.5}.eyebrow{color:var(--muted);font:700 .72rem/1.2 "JetBrains Mono",monospace;letter-spacing:.08em}.eyebrow span{color:var(--cyan)}h1{font-size:clamp(2rem,10vw,3.4rem);margin:.25rem 0}h2{margin:.4rem 0 1rem}.section,.matrix-section{margin-top:16px}.panel{border:1px solid var(--line);border-radius:10px;background:var(--panel);padding:18px}.signal-grid,.ops-header{display:grid;grid-template-columns:1fr;gap:12px}.signal,.matrix-summary{min-width:0;border:1px solid var(--line);border-left:4px solid var(--muted);padding:14px;background:var(--panel-raised);overflow-wrap:anywhere}.matrix-summary{display:grid;gap:5px}.matrix-meta{display:block;color:var(--muted);margin-top:6px;overflow-wrap:anywhere}.matrix-scroll{margin-top:14px;overflow-x:auto;border:1px solid var(--line);border-radius:8px}.matrix-scroll:focus-visible,.issue-link:focus-visible{outline:3px solid var(--amber);outline-offset:3px}table{width:100%;min-width:900px;border-collapse:collapse}th,td{padding:12px;border:1px solid var(--line);text-align:left;vertical-align:top}th{background:var(--panel-raised)}.matrix-cell{min-width:115px}.status-green{border-left-color:var(--green)}.status-red{border-left-color:var(--red)}.status-amber,.status-stale{border-left-color:var(--amber)}.status-unknown{border-left-color:var(--muted)}.signal-head{display:flex;justify-content:space-between;gap:10px;font:700 .72rem/1.2 "JetBrains Mono",monospace;text-transform:uppercase}.state-healthy.fresh-current{border-left-color:var(--green)}.state-critical{border-left-color:var(--red)}.state-degraded,.state-blocked,.fresh-stale{border-left-color:var(--amber)}.fresh-unknown,.state-unknown{border-left-color:var(--muted)}dl{margin:12px 0 0}dl div{display:grid;grid-template-columns:70px minmax(0,1fr);gap:8px;border-top:1px solid var(--line);padding:8px 0}dt{color:var(--muted)}dd{margin:0;overflow-wrap:anywhere}.issue-link{display:inline-block;min-height:44px;margin-top:8px;color:var(--cyan);padding:10px 0}@media(min-width:760px){.shell{padding:36px 28px 64px}.signal-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.ops-header{grid-template-columns:repeat(5,minmax(0,1fr))}}@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation:none!important;transition:none!important;scroll-behavior:auto!important}}
CSS;
    }
}
