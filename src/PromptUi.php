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
    private const REASONS=[
        'candidate_safety_or_policy_failed',
        'insufficient_evidence',
        'candidate_dominates_current',
        'tie_keep_current',
        'candidate_not_strictly_better',
        'evaluation_supports_candidate',
    ];
    private const SENSITIVE='/(?:password|passwd|secret|token|cookie|authorization|bearer|credential|private[_ -]?key|api[_ -]?key|otp|recovery[_ -]?code)/i';

    public static function render(
        array $history,
        array $evaluationSetRaw,
        array $currentResultRaw,
        array $candidateResultRaw,
        array $promotionRaw
    ): string {
        $promotion=self::promotion($promotionRaw);
        $active=PromptRegistry::active($history,$promotion['template_id']);
        $set=PromptEvaluation::evaluationSet($evaluationSetRaw);
        $current=PromptEvaluation::result($currentResultRaw);
        $candidate=PromptEvaluation::result($candidateResultRaw);

        self::bind($history,$active,$set,$current,$candidate,$promotion);

        $versions=self::templateHistory($history,$promotion['template_id']);
        $historyHtml='';
        $rollback=[];
        foreach($versions as $version){
            $historyHtml.='<li><span class="version">v'.self::e((string)$version['version']).'</span>'
                .'<span class="status">'.self::e($version['status']).'</span>'
                .'<code>'.self::e($version['body_ref']).'</code></li>';
            if($version['status']==='approved'&&$version['version']<$active['version']) $rollback[]=$version['version'];
        }

        $rollbackHtml=$rollback===[]?'<p class="muted">Sin versión approved previa disponible.</p>':'<ul class="rollback">';
        if($rollback!==[]){
            foreach($rollback as $version) $rollbackHtml.='<li>v'.self::e((string)$version).' · target aprobado previo</li>';
            $rollbackHtml.='</ul>';
        }

        $reasons='';
        foreach($promotion['reasons'] as $reason) $reasons.='<li><code>'.self::e($reason).'</code> · '.self::e(self::reasonLabel($reason)).'</li>';

        $decisionLabel=$promotion['decision']==='eligible_for_human_approval'
            ? 'Elegible para aprobación humana'
            : 'Mantener versión actual';

        return '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
            .'<title>ControlBot · Prompt UI</title><style>'.self::styles().'</style></head><body>'
            .'<main class="shell"><header class="hero"><p class="eyebrow">CONTROLBOT / PROMPT REGISTRY</p>'
            .'<h1>Historial y evidencia de prompts</h1>'
            .'<p class="lede">Proyección read-only. Esta vista no promueve, revierte ni ejecuta prompts.</p></header>'
            .'<section class="summary" aria-label="Resumen del prompt">'
            .'<article class="panel"><p class="label">Template</p><h2>'.self::e($active['template_id']).'</h2>'
            .'<dl><div><dt>Task class</dt><dd>'.self::e($active['task_class']).'</dd></div>'
            .'<div><dt>Provider</dt><dd>'.self::e($active['provider_scope']).'</dd></div>'
            .'<div><dt>Versión activa</dt><dd>v'.self::e((string)$active['version']).' · approved</dd></div>'
            .'<div><dt>Candidata</dt><dd>v'.self::e((string)$promotion['candidate_version']).' · candidate</dd></div></dl></article>'
            .'<article class="panel decision" data-decision="'.self::e($promotion['decision']).'">'
            .'<p class="label">Decisión proyectada</p><h2>'.self::e($decisionLabel).'</h2>'
            .'<p class="gate">human_gate_required = true</p><ul>'.$reasons.'</ul>'
            .'<p class="fingerprint"><span>decision fingerprint</span><code>'.self::e($promotion['fingerprint']).'</code></p>'
            .'</article></section>'
            .'<section class="panel" aria-labelledby="evaluation-title"><p class="label">Evaluación</p>'
            .'<h2 id="evaluation-title">'.self::e($set['evaluation_set_id']).' · v'.self::e((string)$set['version']).'</h2>'
            .'<p>Sample size: <strong>'.self::e((string)$set['sample_size']).'</strong></p>'
            .'<p class="fingerprint"><span>evaluation set fingerprint</span><code>'.self::e($set['fingerprint']).'</code></p>'
            .'<div class="metrics">'.self::metrics('Actual · v'.$current['prompt_version'],$current).self::metrics('Candidata · v'.$candidate['prompt_version'],$candidate).'</div>'
            .'</section>'
            .'<section class="history-grid"><article class="panel"><p class="label">Historial inmutable</p><h2>Versiones</h2>'
            .'<ul class="history">'.$historyHtml.'</ul></article>'
            .'<article class="panel"><p class="label">Rollback</p><h2>Targets previos</h2>'
            .'<p class="muted">Solo se muestran targets ya approved. La UI no ejecuta rollback.</p>'.$rollbackHtml.'</article></section>'
            .'<footer><code>authority='.self::e($promotion['authority']).'</code></footer>'
            .'</main></body></html>';
    }

    private static function bind(
        array $history,
        array $active,
        array $set,
        array $current,
        array $candidate,
        array $promotion
    ): void {
        if($active['status']!=='approved'||$active['version']!==$promotion['current_version'])
            throw new InvalidArgumentException('Prompt UI active version mismatch.');

        $candidateRow=null;
        foreach($history as $row){
            if(is_array($row)
                &&($row['template_id']??null)===$promotion['template_id']
                &&($row['version']??null)===$promotion['candidate_version']) $candidateRow=$row;
        }
        if(!is_array($candidateRow)||($candidateRow['status']??null)!=='candidate')
            throw new InvalidArgumentException('Prompt UI candidate version mismatch.');

        foreach([$current,$candidate] as $result){
            if($result['template_id']!==$promotion['template_id']
                ||$result['task_class']!==$active['task_class']
                ||$result['evaluation_set_id']!==$set['evaluation_set_id']
                ||$result['evaluation_set_version']!==$set['version']
                ||$result['sample_size']!==$set['sample_size']
                ||array_keys($result['metrics'])!==$set['metric_names'])
                throw new InvalidArgumentException('Prompt UI evaluation binding invalid.');
        }
        if($current['prompt_version']!==$promotion['current_version']
            ||$candidate['prompt_version']!==$promotion['candidate_version'])
            throw new InvalidArgumentException('Prompt UI evaluation versions invalid.');
        if(!hash_equals($set['fingerprint'],$promotion['evaluation_set_fingerprint']))
            throw new InvalidArgumentException('Prompt UI evaluation set fingerprint mismatch.');
    }

    private static function promotion(array $raw): array
    {
        $expected=[
            'version','decision','template_id','current_version','candidate_version',
            'evaluation_fingerprint','evaluation_set_fingerprint','reasons',
            'human_gate_required','authority','fingerprint'
        ];
        self::fields($raw,$expected,'Prompt promotion projection');
        if($raw['version']!==1) throw new InvalidArgumentException('Prompt promotion projection version invalid.');
        if(!is_string($raw['decision'])||!in_array($raw['decision'],self::DECISIONS,true))
            throw new InvalidArgumentException('Prompt promotion projection decision invalid.');
        $template=self::slug($raw['template_id'],'template_id');
        foreach(['current_version','candidate_version'] as $key){
            if(!is_int($raw[$key])||$raw[$key]<1) throw new InvalidArgumentException('Prompt promotion projection versions invalid.');
        }
        if($raw['candidate_version']<=$raw['current_version'])
            throw new InvalidArgumentException('Prompt promotion projection version order invalid.');
        foreach(['evaluation_fingerprint','evaluation_set_fingerprint'] as $key){
            if(!is_string($raw[$key])||preg_match('/^[a-f0-9]{64}$/D',$raw[$key])!==1)
                throw new InvalidArgumentException('Prompt promotion projection fingerprint invalid.');
        }
        if(!is_array($raw['reasons'])||!array_is_list($raw['reasons'])||$raw['reasons']===[])
            throw new InvalidArgumentException('Prompt promotion projection reasons invalid.');
        $reasons=[];
        foreach($raw['reasons'] as $reason){
            if(!is_string($reason)||!in_array($reason,self::REASONS,true)||in_array($reason,$reasons,true))
                throw new InvalidArgumentException('Prompt promotion projection reason invalid.');
            $reasons[]=$reason;
        }
        sort($reasons,SORT_STRING);
        if($raw['decision']==='eligible_for_human_approval'&&$reasons!==['evaluation_supports_candidate'])
            throw new InvalidArgumentException('Prompt promotion projection eligibility evidence invalid.');
        if($raw['decision']==='hold'&&in_array('evaluation_supports_candidate',$reasons,true))
            throw new InvalidArgumentException('Prompt promotion projection hold evidence invalid.');
        if($raw['human_gate_required']!==true||$raw['authority']!=='human_approval_required')
            throw new InvalidArgumentException('Prompt promotion projection authority invalid.');

        $canonical=[
            'version'=>1,
            'decision'=>$raw['decision'],
            'template_id'=>$template,
            'current_version'=>$raw['current_version'],
            'candidate_version'=>$raw['candidate_version'],
            'evaluation_fingerprint'=>$raw['evaluation_fingerprint'],
            'evaluation_set_fingerprint'=>$raw['evaluation_set_fingerprint'],
            'reasons'=>$reasons,
            'human_gate_required'=>true,
            'authority'=>'human_approval_required',
        ];
        $fingerprint=self::fingerprint($canonical);
        if(!is_string($raw['fingerprint'])||!hash_equals($fingerprint,$raw['fingerprint']))
            throw new InvalidArgumentException('Prompt promotion projection integrity invalid.');
        return $canonical+['fingerprint'=>$fingerprint];
    }

    private static function templateHistory(array $history,string $templateId): array
    {
        if(!array_is_list($history)||$history===[]||count($history)>128)
            throw new InvalidArgumentException('Prompt UI history invalid.');
        $rows=[];
        foreach($history as $row){
            if(is_array($row)&&($row['template_id']??null)===$templateId) $rows[]=$row;
        }
        if($rows===[]) throw new InvalidArgumentException('Prompt UI template history unavailable.');
        usort($rows,static fn(array $a,array $b): int=>$a['version']<=>$b['version']);
        return $rows;
    }

    private static function metrics(string $title,array $result): string
    {
        $items='';
        foreach($result['metrics'] as $name=>$value){
            $items.='<div><dt>'.self::e($name).'</dt><dd>'.self::e(self::number($value)).'</dd></div>';
        }
        return '<article class="metric-card"><h3>'.self::e($title).'</h3><dl>'.$items
            .'<div><dt>safety</dt><dd>'.self::e($result['safety_result']).'</dd></div>'
            .'<div><dt>policy</dt><dd>'.self::e($result['policy_result']).'</dd></div></dl></article>';
    }

    private static function reasonLabel(string $reason): string
    {
        return match($reason){
            'candidate_safety_or_policy_failed'=>'Safety o policy impide promoción.',
            'insufficient_evidence'=>'Evidencia insuficiente; se conserva la versión actual.',
            'candidate_dominates_current'=>'La evaluación favorece a la candidata.',
            'tie_keep_current'=>'Empate; se conserva la versión actual.',
            'candidate_not_strictly_better'=>'La candidata no mejora de forma estricta.',
            'evaluation_supports_candidate'=>'La evaluación permite elevar la candidata a aprobación humana.',
            default=>throw new InvalidArgumentException('Prompt UI reason invalid.'),
        };
    }

    private static function fields(mixed $raw,array $expected,string $label): void
    {
        if(!is_array($raw)||array_is_list($raw)) throw new InvalidArgumentException($label.' fields invalid.');
        $actual=array_keys($raw);sort($actual,SORT_STRING);sort($expected,SORT_STRING);
        if($actual!==$expected) throw new InvalidArgumentException($label.' fields invalid.');
    }

    private static function slug(mixed $value,string $label): string
    {
        if(!is_string($value)||preg_match('/^[a-z][a-z0-9._-]{1,63}$/D',$value)!==1)
            throw new InvalidArgumentException($label.' invalid.');
        self::safe($value);
        return $value;
    }

    private static function safe(string $value): void
    {
        if(preg_match(self::SENSITIVE,$value)===1)
            throw new InvalidArgumentException('Sensitive prompt UI metadata invalid.');
    }

    private static function number(float $value): string
    {
        $out=rtrim(rtrim(sprintf('%.4F',$value),'0'),'.');
        return $out===''?'0':$out;
    }

    private static function fingerprint(array $value): string
    {
        return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }

    private static function styles(): string
    {
        return UiTheme::tokensCss().<<<'CSS'
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,system-ui,sans-serif}.shell{width:min(100%,1100px);margin:auto;padding:24px 16px 48px}.eyebrow,.label,code,.fingerprint{font-family:"JetBrains Mono",ui-monospace,monospace}.eyebrow{color:var(--cyan);letter-spacing:.08em;font-size:.72rem}.hero{padding-bottom:18px;border-bottom:1px solid var(--line);margin-bottom:16px}h1,h2,h3,p,code,dd{overflow-wrap:anywhere}h1{font-size:clamp(1.9rem,9vw,3rem);margin:.2rem 0}.lede,.muted{color:var(--muted);line-height:1.55}.summary,.history-grid,.metrics{display:grid;grid-template-columns:1fr;gap:12px}.panel{min-width:0;padding:16px;border:1px solid var(--line);border-radius:10px;background:var(--panel);margin-top:12px}.label{margin:0;color:var(--cyan);font-size:.72rem;text-transform:uppercase;letter-spacing:.06em}.panel h2{margin:.45rem 0 1rem}.panel h3{margin:.25rem 0 .8rem}.panel dl{display:grid;gap:8px;margin:0}.panel dl div{display:flex;justify-content:space-between;gap:16px;border-bottom:1px solid var(--line);padding:7px 0}.panel dt{color:var(--muted)}.panel dd{margin:0;text-align:right}.decision{border-color:var(--line-strong)}.gate{color:var(--amber)}ul{padding-left:20px}.history{list-style:none;padding:0;margin:0}.history li{display:grid;grid-template-columns:auto auto 1fr;gap:10px;align-items:center;padding:9px 0;border-bottom:1px solid var(--line)}.history code{text-align:right}.version{color:var(--cyan)}.status{color:var(--muted)}.rollback{margin-bottom:0}.fingerprint{display:grid;gap:6px;color:var(--muted);font-size:.72rem}.fingerprint code{color:var(--text)}.metric-card{padding:12px;border:1px solid var(--line);border-radius:8px;background:var(--panel-raised)}footer{padding-top:18px;color:var(--muted)}a:focus-visible,.panel:focus-visible{outline:3px solid var(--amber);outline-offset:3px}@media(min-width:760px){.shell{padding:40px 28px 64px}.summary,.history-grid,.metrics{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation:none!important;transition:none!important;scroll-behavior:auto!important}}
CSS;
    }
}
