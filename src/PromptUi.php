<?php
declare(strict_types=1);
namespace ControlBot\Prompt;

require_once __DIR__.'/UiTheme.php';
require_once __DIR__.'/PromptRegistry.php';
require_once __DIR__.'/PromptEvaluation.php';

use ControlBot\Ui\UiTheme;
use InvalidArgumentException;

final class PromptUi
{
    private const DECISIONS=['hold','eligible_for_human_approval'];
    private const REASONS=['candidate_safety_or_policy_failed','insufficient_evidence','candidate_dominates_current','tie_keep_current','candidate_not_strictly_better','evaluation_supports_candidate'];

    public static function render(array $history,array $setRaw,array $currentRaw,array $candidateRaw,array $promotionRaw): string
    {
        $promotion=self::promotion($promotionRaw);
        $active=PromptRegistry::active($history,$promotion['template_id']);
        $set=PromptEvaluation::evaluationSet($setRaw);
        $current=PromptEvaluation::result($currentRaw);
        $candidate=PromptEvaluation::result($candidateRaw);
        $versions=self::bind($history,$active,$set,$current,$candidate,$promotion);

        $historyHtml=''; $rollback=[];
        foreach($versions as $row){
            $historyHtml.='<li><span>v'.self::e((string)$row['version']).'</span><span>'.self::e($row['status']).'</span><code>'.self::e($row['body_ref']).'</code></li>';
            if($row['status']==='approved'&&$row['version']<$active['version']) $rollback[]=$row['version'];
        }
        $rollbackHtml=$rollback===[]?'<p class="muted">Sin versión approved previa disponible.</p>':'<ul>';
        foreach($rollback as $version) $rollbackHtml.='<li>v'.self::e((string)$version).' · target aprobado previo</li>';
        if($rollback!==[]) $rollbackHtml.='</ul>';

        $reasons='';
        foreach($promotion['reasons'] as $reason) $reasons.='<li><code>'.self::e($reason).'</code></li>';
        $decision=$promotion['decision']==='eligible_for_human_approval'?'Elegible para aprobación humana':'Mantener versión actual';

        return '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
            .'<title>ControlBot · Prompt UI</title><style>'.self::styles().'</style></head><body><main class="shell">'
            .'<header><p class="eyebrow">CONTROLBOT / PROMPT REGISTRY</p><h1>Historial y evidencia de prompts</h1><p class="muted">Proyección read-only. No promueve, revierte ni ejecuta prompts.</p></header>'
            .'<section class="grid" aria-label="Resumen del prompt"><article class="panel"><h2>'.self::e($active['template_id']).'</h2><dl>'
            .self::pair('Task class',$active['task_class']).self::pair('Provider',$active['provider_scope']).self::pair('Versión activa','v'.$active['version'].' · approved').self::pair('Candidata','v'.$promotion['candidate_version'].' · candidate')
            .'</dl></article><article class="panel" data-decision="'.self::e($promotion['decision']).'"><h2>'.self::e($decision).'</h2><p class="gate">human_gate_required = true</p><ul>'.$reasons.'</ul>'
            .self::fingerprint('decision fingerprint',$promotion['fingerprint']).'</article></section>'
            .'<section class="panel"><h2>'.self::e($set['evaluation_set_id']).' · v'.self::e((string)$set['version']).'</h2><p>Sample size: <strong>'.self::e((string)$set['sample_size']).'</strong></p>'
            .self::fingerprint('evaluation set fingerprint',$set['fingerprint']).'<div class="grid">'.self::metrics('Actual · v'.$current['prompt_version'],$current).self::metrics('Candidata · v'.$candidate['prompt_version'],$candidate).'</div></section>'
            .'<section class="grid"><article class="panel"><h2>Historial inmutable</h2><ul class="history">'.$historyHtml.'</ul></article>'
            .'<article class="panel"><h2>Targets previos</h2><p class="muted">Solo versiones approved; la UI no ejecuta rollback.</p>'.$rollbackHtml.'</article></section>'
            .'<footer><code>authority='.self::e($promotion['authority']).'</code></footer></main></body></html>';
    }

    private static function bind(array $history,array $active,array $set,array $current,array $candidate,array $promotion): array
    {
        if($active['status']!=='approved'||$active['version']!==$promotion['current_version']) throw new InvalidArgumentException('Prompt UI active mismatch.');
        if(!array_is_list($history)||$history===[]||count($history)>128) throw new InvalidArgumentException('Prompt UI history invalid.');
        $versions=[]; $candidateRow=null;
        foreach($history as $row){
            if(!is_array($row)||($row['template_id']??null)!==$promotion['template_id']) continue;
            $versions[]=$row;
            if(($row['version']??null)===$promotion['candidate_version']) $candidateRow=$row;
        }
        if(!is_array($candidateRow)||($candidateRow['status']??null)!=='candidate'
            ||($candidateRow['supersedes']??null)!==$active['version']
            ||($candidateRow['task_class']??null)!==$active['task_class']
            ||($candidateRow['provider_scope']??null)!==$active['provider_scope']
            ||($candidateRow['variables_schema']??null)!==$active['variables_schema'])
            throw new InvalidArgumentException('Prompt UI candidate mismatch.');
        foreach([$current,$candidate] as $result){
            if($result['template_id']!==$promotion['template_id']||$result['task_class']!==$active['task_class']
                ||$result['evaluation_set_id']!==$set['evaluation_set_id']||$result['evaluation_set_version']!==$set['version']
                ||$result['sample_size']!==$set['sample_size']||array_keys($result['metrics'])!==$set['metric_names'])
                throw new InvalidArgumentException('Prompt UI evaluation binding invalid.');
        }
        if($current['prompt_version']!==$promotion['current_version']||$candidate['prompt_version']!==$promotion['candidate_version']
            ||!hash_equals($set['fingerprint'],$promotion['evaluation_set_fingerprint']))
            throw new InvalidArgumentException('Prompt UI provenance mismatch.');
        usort($versions,static fn(array $a,array $b): int=>$a['version']<=>$b['version']);
        return $versions;
    }

    private static function promotion(array $raw): array
    {
        $expected=['version','decision','template_id','current_version','candidate_version','evaluation_fingerprint','evaluation_set_fingerprint','reasons','human_gate_required','authority','fingerprint'];
        self::fields($raw,$expected);
        if($raw['version']!==1||!in_array($raw['decision'],self::DECISIONS,true)||$raw['human_gate_required']!==true||$raw['authority']!=='human_approval_required')
            throw new InvalidArgumentException('Prompt promotion projection invalid.');
        if(!is_string($raw['template_id'])||preg_match('/^[a-z][a-z0-9._-]{1,63}$/D',$raw['template_id'])!==1
            ||!is_int($raw['current_version'])||!is_int($raw['candidate_version'])||$raw['current_version']<1||$raw['candidate_version']<=$raw['current_version'])
            throw new InvalidArgumentException('Prompt promotion identity invalid.');
        foreach(['evaluation_fingerprint','evaluation_set_fingerprint'] as $key)
            if(!is_string($raw[$key])||preg_match('/^[a-f0-9]{64}$/D',$raw[$key])!==1) throw new InvalidArgumentException('Prompt promotion fingerprint invalid.');
        if(!is_array($raw['reasons'])||!array_is_list($raw['reasons'])||$raw['reasons']===[]) throw new InvalidArgumentException('Prompt promotion reasons invalid.');
        $reasons=$raw['reasons']; sort($reasons,SORT_STRING);
        foreach($reasons as $reason) if(!is_string($reason)||!in_array($reason,self::REASONS,true)) throw new InvalidArgumentException('Prompt promotion reason invalid.');
        if($raw['decision']==='eligible_for_human_approval'&&$reasons!==['evaluation_supports_candidate']) throw new InvalidArgumentException('Prompt promotion evidence invalid.');
        $canonical=['version'=>1,'decision'=>$raw['decision'],'template_id'=>$raw['template_id'],'current_version'=>$raw['current_version'],'candidate_version'=>$raw['candidate_version'],
            'evaluation_fingerprint'=>$raw['evaluation_fingerprint'],'evaluation_set_fingerprint'=>$raw['evaluation_set_fingerprint'],'reasons'=>$reasons,'human_gate_required'=>true,'authority'=>'human_approval_required'];
        $fingerprint=hash('sha256',json_encode($canonical,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION));
        if(!is_string($raw['fingerprint'])||!hash_equals($fingerprint,$raw['fingerprint'])) throw new InvalidArgumentException('Prompt promotion integrity invalid.');
        return $canonical+['fingerprint'=>$fingerprint];
    }

    private static function metrics(string $title,array $result): string
    {
        $rows=''; foreach($result['metrics'] as $name=>$value) $rows.=self::pair($name,rtrim(rtrim(sprintf('%.4F',$value),'0'),'.'));
        return '<article class="metric"><h3>'.self::e($title).'</h3><dl>'.$rows.self::pair('safety',$result['safety_result']).self::pair('policy',$result['policy_result']).'</dl></article>';
    }

    private static function pair(string $name,string $value): string {return '<div><dt>'.self::e($name).'</dt><dd>'.self::e($value).'</dd></div>';}
    private static function fingerprint(string $label,string $value): string {return '<p class="fp"><span>'.self::e($label).'</span><code>'.self::e($value).'</code></p>';}
    private static function fields(mixed $raw,array $expected): void
    {
        if(!is_array($raw)||array_is_list($raw)) throw new InvalidArgumentException('Prompt promotion fields invalid.');
        $actual=array_keys($raw); sort($actual,SORT_STRING); sort($expected,SORT_STRING);
        if($actual!==$expected) throw new InvalidArgumentException('Prompt promotion fields invalid.');
    }
    private static function e(string $value): string {return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private static function styles(): string
    {
        return UiTheme::tokensCss().<<<'CSS'
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,system-ui,sans-serif}.shell{width:min(100%,1080px);margin:auto;padding:24px 16px 48px}header{border-bottom:1px solid var(--line);padding-bottom:16px}.eyebrow,.fp,code{font-family:"JetBrains Mono",ui-monospace,monospace}.eyebrow{color:var(--cyan)}.muted,dt{color:var(--muted)}.gate{color:var(--amber)}.grid{display:grid;grid-template-columns:1fr;gap:12px}.panel,.metric{min-width:0;margin-top:12px;padding:16px;border:1px solid var(--line);border-radius:10px;background:var(--panel)}dl{display:grid;gap:6px}dl div{display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid var(--line);padding:6px 0}dd{margin:0;text-align:right}.history{list-style:none;padding:0}.history li{display:grid;grid-template-columns:auto auto 1fr;gap:10px;padding:7px 0;border-bottom:1px solid var(--line)}.history code,.fp code{text-align:right;overflow-wrap:anywhere}.fp{display:grid;gap:5px;font-size:.72rem}footer{padding-top:18px}:focus-visible{outline:3px solid var(--amber);outline-offset:3px}@media(min-width:760px){.shell{padding:40px 28px 64px}.grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation:none!important;transition:none!important;scroll-behavior:auto!important}}
CSS;
    }
}
