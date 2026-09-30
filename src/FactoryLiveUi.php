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
    private const STATES=['healthy','degraded','critical','unknown','blocked','pending'];
    private const FRESH=['current','stale','unknown'];
    private const SENSITIVE='/(?:password|passwd|secret|token|cookie|authorization|bearer|private[_ -]?key|api[_ -]?key|dsn)/i';

    public static function render(array $snapshot): string
    {
        self::fields($snapshot,['version','observed_at','sections','tool_usage','fingerprint'],'snapshot');
        if($snapshot['version']!==1||!is_int($snapshot['observed_at'])||!is_array($snapshot['sections']))
            throw new InvalidArgumentException('Factory live UI snapshot invalid.');
        self::safe($snapshot); $body='';
        foreach(self::SECTIONS as $key=>$label){
            $rows=$snapshot['sections'][$key]??null;
            if(!is_array($rows)||!array_is_list($rows)||$rows===[])throw new InvalidArgumentException('Factory live UI section invalid.');
            $cards=''; foreach($rows as $row)$cards.=self::card($row,$key);
            $body.='<section class="panel section" data-section="'.$key.'"><p class="eyebrow">'.self::e(strtoupper($label)).'</p><h2>'.self::e($label).'</h2><div class="signal-grid">'.$cards.'</div></section>';
        }
        $tool=self::card($snapshot['tool_usage'],'tool_usage');
        return '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>ControlBot · Fábrica viva</title><style>'.self::styles().'</style></head><body><main class="shell" aria-labelledby="factory-live-title"><header class="top"><p class="eyebrow"><span>CONTROLBOT</span> / FÁBRICA VIVA</p><h1 id="factory-live-title">Fábrica viva</h1><p>Solo lectura · evidencia, freshness y antigüedad visibles.</p></header>'.$body.'<section class="panel section" data-section="tool_usage"><p class="eyebrow">HERRAMIENTAS</p><h2>Uso y costes</h2><div class="signal-grid">'.$tool.'</div></section></main></body></html>';
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
    private static function fields(mixed $row,array $keys,string $label): void { if(!is_array($row)||array_is_list($row))throw new InvalidArgumentException($label.' invalid.');$a=array_keys($row);sort($a);sort($keys);if($a!==$keys)throw new InvalidArgumentException($label.' fields invalid.'); }
    private static function one(mixed $v,array $allowed,string $label): string { if(!is_string($v)||!in_array($v,$allowed,true))throw new InvalidArgumentException($label.' invalid.');return $v; }
    private static function scalar(mixed $v): string { if(!is_int($v)||$v<0)throw new InvalidArgumentException('Age invalid.');return (string)$v; }
    private static function text(mixed $v): string { if(!is_string($v)||trim($v)===''||preg_match('/[\x00-\x1f\x7f]/u',$v)===1||preg_match(self::SENSITIVE,$v)===1)throw new InvalidArgumentException('Unsafe text.');return trim($v); }
    private static function safe(mixed $v): void { if(is_array($v)){foreach($v as $k=>$x){if(is_string($k)&&preg_match(self::SENSITIVE,$k)===1)throw new InvalidArgumentException('Sensitive field.');self::safe($x);}return;}if(is_string($v)&&preg_match(self::SENSITIVE,$v)===1)throw new InvalidArgumentException('Sensitive value.'); }
    private static function e(string $v): string { return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }

    private static function styles(): string
    {
        return UiTheme::tokensCss().<<<'CSS'
*{box-sizing:border-box}html{background:var(--bg)}body{margin:0;color:var(--text);background:var(--bg);font-family:Inter,system-ui,sans-serif}.shell{width:min(100%,1180px);margin:auto;padding:24px 16px 48px}.top{padding:8px 0 20px;border-bottom:1px solid var(--line)}.top p{color:var(--muted);line-height:1.5}.eyebrow{color:var(--muted);font:700 .72rem/1.2 "JetBrains Mono",monospace;letter-spacing:.08em}.eyebrow span{color:var(--cyan)}h1{font-size:clamp(2rem,10vw,3.4rem);margin:.25rem 0}h2{margin:.4rem 0 1rem}.section{margin-top:16px}.panel{border:1px solid var(--line);border-radius:10px;background:var(--panel);padding:18px}.signal-grid{display:grid;grid-template-columns:1fr;gap:12px}.signal{min-width:0;border:1px solid var(--line);border-left:4px solid var(--muted);padding:14px;background:var(--panel-raised);overflow-wrap:anywhere}.signal-head{display:flex;justify-content:space-between;gap:10px;font:700 .72rem/1.2 "JetBrains Mono",monospace;text-transform:uppercase}.state-healthy.fresh-current{border-left-color:var(--green)}.state-critical{border-left-color:var(--red)}.state-degraded,.state-blocked,.fresh-stale{border-left-color:var(--amber)}.fresh-unknown,.state-unknown{border-left-color:var(--muted)}dl{margin:12px 0 0}dl div{display:grid;grid-template-columns:70px minmax(0,1fr);gap:8px;border-top:1px solid var(--line);padding:8px 0}dt{color:var(--muted)}dd{margin:0;overflow-wrap:anywhere}.issue-link{display:inline-block;min-height:44px;margin-top:10px;color:var(--cyan);padding:10px 0}.issue-link:focus-visible{outline:3px solid var(--amber);outline-offset:3px}@media(min-width:760px){.shell{padding:36px 28px 64px}.signal-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation:none!important;transition:none!important;scroll-behavior:auto!important}}
CSS;
    }
}
