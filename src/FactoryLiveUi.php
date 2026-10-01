<?php
declare(strict_types=1);

namespace ControlBot\Business;

require_once __DIR__.'/UiTheme.php';
require_once __DIR__.'/FactoryAccountCapacitySnapshot.php';

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
    private const PII='/(?:[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}|\+?(?=(?:[0-9(). -]*[0-9]){10})[0-9][0-9(). -]{7,}[0-9])/i';

    public static function render(array $snapshot,?array $matrix=null,?array $learning=null,?array $productCosts=null,?array $accountCapacity=null): string
    {
        self::fields($snapshot,['version','observed_at','sections','tool_usage','fingerprint'],'snapshot');
        if($snapshot['version']!==1||!is_int($snapshot['observed_at'])||!is_array($snapshot['sections']))
            throw new InvalidArgumentException('Factory live UI snapshot invalid.');
        self::safe($snapshot); if($matrix!==null)self::safe($matrix); if($learning!==null)self::safe($learning); if($productCosts!==null)self::safe($productCosts);
        $body='';
        foreach(self::SECTIONS as $key=>$label){
            $rows=$snapshot['sections'][$key]??null;
            if(!is_array($rows)||!array_is_list($rows)||$rows===[])throw new InvalidArgumentException('Factory live UI section invalid.');
            $cards=''; foreach($rows as $row)$cards.=self::card($row,$key);
            $body.='<section class="panel section" data-section="'.$key.'"><p class="eyebrow">'.self::e(strtoupper($label)).'</p><h2>'.self::e($label).'</h2><div class="signal-grid">'.$cards.'</div></section>';
        }
        $tool=self::card($snapshot['tool_usage'],'tool_usage');
        $matrixHtml=$matrix===null?'':self::matrix($matrix);
        $learningHtml=$learning===null?'':self::learning($learning);
        $productCostsHtml=$productCosts===null?'':self::productCosts($productCosts,$snapshot['observed_at']);
        $capacityHtml=$accountCapacity===null?'':self::accountCapacity($accountCapacity,$snapshot['observed_at']);
        return '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>ControlBot · Fábrica viva</title><style>'.self::styles().'</style></head><body><main class="shell" aria-labelledby="factory-live-title"><header class="top"><p class="eyebrow"><span>CONTROLBOT</span> / FÁBRICA VIVA</p><h1 id="factory-live-title">Fábrica viva</h1><p>Solo lectura · evidencia, freshness y antigüedad visibles.</p></header>'.$matrixHtml.$learningHtml.$capacityHtml.$productCostsHtml.$body.'<section class="panel section" data-section="tool_usage"><p class="eyebrow">HERRAMIENTAS</p><h2>Uso de herramientas</h2><div class="signal-grid">'.$tool.'</div></section></main></body></html>';
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


    private static function learning(array $learning): string
    {
        self::fields($learning,['version','observed_at','layers','metrics','recurrence','incidents'],'learning');
        if($learning['version']!==1||!is_int($learning['observed_at'])||!is_array($learning['layers'])
            ||!is_array($learning['metrics'])||!is_array($learning['incidents']))
            throw new InvalidArgumentException('Factory learning UI invalid.');
        $layers='';foreach($learning['layers'] as $key=>$layer){
            self::fields($layer,['status','signals'],'learning.layer');
            $status=self::one($layer['status'],self::MATRIX_STATUS,'learning.layer.status');
            if(!is_array($layer['signals'])||!array_is_list($layer['signals']))throw new InvalidArgumentException('Learning signals invalid.');
            $items='';foreach($layer['signals'] as $signal)$items.=self::learningSignal($signal);
            if($items==='')$items='<small class="matrix-meta">Sin evidencia · UNKNOWN</small>';
            $layers.='<article class="learning-card status-'.strtolower($status).'"><h3>'.self::e($key).'</h3><strong>'.self::e($status).'</strong>'.$items.'</article>';
        }
        $metrics='';foreach($learning['metrics'] as $key=>$metric)$metrics.=self::learningMetric($key,$metric);
        self::fields($learning['incidents'],['real','auto_notices'],'learning.incidents');
        $real=self::incidentList($learning['incidents']['real'],'Fallos reales');
        $auto=self::incidentList($learning['incidents']['auto_notices'],'Avisos [AUTO]');
        $recurrence=self::learningMetric('recurrence',$learning['recurrence']);
        return '<section class="panel learning-section" data-section="learning_layers"><p class="eyebrow">CAPAS / DRILL-DOWN</p><h2>Aprendizaje de la fábrica</h2>'
            .'<div class="learning-grid">'.$layers.'</div><h3>Métricas de aprendizaje</h3><div class="learning-grid">'.$metrics.$recurrence.'</div>'
            .'<div class="incident-split">'.$real.$auto.'</div></section>';
    }

    private static function learningSignal(mixed $signal): string
    {
        self::fields($signal,['id','label','project','scope','status','source_ref','observed_at','freshness','age_seconds','evidence_href'],'learning.signal');
        $status=self::one($signal['status'],self::MATRIX_STATUS,'learning.signal.status');
        if($status!=='UNKNOWN'&&$signal['evidence_href']===null)
            throw new InvalidArgumentException('Learning signal evidence required.');
        $source=$signal['source_ref']===null?'UNKNOWN':self::text($signal['source_ref']);
        $age=$signal['age_seconds']===null?'UNKNOWN':self::scalar($signal['age_seconds']).'s';
        $project=$signal['project']!==null
            ?self::text($signal['project'])
            :($signal['scope']==='global'?'GLOBAL':'UNKNOWN');
        $link=$signal['evidence_href']===null?'':'<a class="issue-link" href="'.self::e(self::matrixHref($signal['evidence_href'])).'">Evidencia</a>';
        return '<div class="learning-signal"><b>'.self::e(self::text($signal['label'])).'</b><small class="matrix-meta">'
            .self::e($project).' · '.self::e($source).' · '.self::e($age).' · '.self::e($status).'</small>'.$link.'</div>';
    }

    private static function learningMetric(string $name,mixed $metric): string
    {
        if($name==='recurrence')return self::learningMetricValue('GLOBAL',$metric);
        self::fields($metric,['global','by_project'],'learning.metric');
        if(!is_array($metric['by_project'])||array_is_list($metric['by_project']))
            throw new InvalidArgumentException('Learning metric projects invalid.');
        $body=self::learningMetricValue('GLOBAL',$metric['global']);
        foreach($metric['by_project'] as $project=>$value){
            if(!is_string($project)||trim($project)==='')
                throw new InvalidArgumentException('Learning metric project invalid.');
            $body.=self::learningMetricValue($project,$value);
        }
        return '<section class="learning-metric-group"><h4>'.self::e($name).'</h4>'.$body.'</section>';
    }

    private static function learningMetricValue(string $scope,mixed $metric): string
    {
        self::fields($metric,['status','value','source_ref','observed_at','freshness','age_seconds','evidence_href'],'learning.metric.value');
        $status=self::one($metric['status'],self::MATRIX_STATUS,'learning.metric.status');
        if($status!=='UNKNOWN'&&$metric['evidence_href']===null)
            throw new InvalidArgumentException('Learning metric evidence required.');
        $value=$metric['value']===null?'UNKNOWN':self::value($metric['value']);
        $source=$metric['source_ref']===null?'UNKNOWN':self::text($metric['source_ref']);
        $age=$metric['age_seconds']===null?'UNKNOWN':self::scalar($metric['age_seconds']).'s';
        $link=$metric['evidence_href']===null?'':'<a class="issue-link" href="'.self::e(self::matrixHref($metric['evidence_href'])).'">Evidencia</a>';
        return '<article class="learning-card status-'.strtolower($status).'"><span>'.self::e($scope).'</span><strong>'
            .self::e($value).'</strong><small class="matrix-meta">'.self::e($source).' · '.self::e($age).' · '.self::e($status).'</small>'.$link.'</article>';
    }

    private static function accountCapacity(array $view,int $snapshotObservedAt): string
    {
        $view=FactoryAccountCapacitySnapshot::validate($view,$snapshotObservedAt);
        $rows='';
        foreach($view['accounts'] as $row){
            $alias=self::text($row['accountAlias']);
            if($row['status']==='UNKNOWN'){
                $rows.='<article class="learning-card status-unknown"><span>'.self::e($alias).'</span><strong>UNKNOWN</strong><small class="matrix-meta">Sin evidencia vigente de capacidad</small></article>';
                continue;
            }
            $remaining=$row['status']==='FRESH'?(string)$row['remaining']:'UNKNOWN';
            $css=$row['status']==='FRESH'?'green':'stale';
            $rows.='<article class="learning-card status-'.$css.'"><span>'.self::e($alias).' · '.self::e($row['confidence']).'</span><strong>'
                .self::e((string)$row['sent']).' / '.self::e((string)$row['budget']['limit']).'</strong><small class="matrix-meta">Restante: '
                .self::e($remaining).' · Eventos de límite: '.self::e((string)$row['limitEvents']).'</small><small class="matrix-meta">'
                .self::e($row['source_ref']).' · '.self::e((string)$row['age_seconds']).'s · '.self::e($row['status']).'</small></article>';
        }
        if($rows==='')$rows='<article class="learning-card status-unknown"><strong>UNKNOWN</strong><small class="matrix-meta">Sin evidencia de cuentas</small></article>';
        return '<section class="panel section" data-section="account_capacity"><p class="eyebrow">CAPACIDAD / LÍMITES</p><h2>Capacidad de cuentas</h2><div class="learning-grid">'.$rows.'</div></section>';
    }

    private static function productCosts(array $view,int $snapshotObservedAt): string
    {
        self::fields($view,['version','observed_at','product_analytics','costs','limits','tool_usage'],'product_costs');
        if($view['version']!==1||!is_int($view['observed_at'])||$view['observed_at']<1
            ||$view['observed_at']!==$snapshotObservedAt
            ||!is_array($view['product_analytics'])||!array_is_list($view['product_analytics']))
            throw new InvalidArgumentException('Product costs view invalid.');

        $products='';
        foreach($view['product_analytics'] as $row){
            self::fields($row,['department','project','metric','value','status','source_ref','observed_at','freshness','age_seconds'],'product_metric');
            $department=self::one($row['department'],['product','data_analytics'],'product metric department');
            $status=self::one($row['status'],['measured','unknown'],'product metric status');
            $fresh=self::one($row['freshness'],['current','stale'],'product metric freshness');
            $project=self::text($row['project']);$metric=self::text($row['metric']);$source=self::text($row['source_ref']);
            if(!is_int($row['observed_at'])||$row['observed_at']<1||$row['observed_at']>$view['observed_at'])
                throw new InvalidArgumentException('Product metric observed_at invalid.');
            if(!is_int($row['age_seconds'])||$row['age_seconds']<0
                ||$row['age_seconds']!==$view['observed_at']-$row['observed_at'])
                throw new InvalidArgumentException('Product metric age invalid.');
            $value=$row['value']===null?'UNKNOWN':self::value($row['value']);
            $products.='<article class="learning-card"><span>'.self::e($department).' · '.self::e($project).'</span><strong>'.self::e($value).'</strong><small class="matrix-meta">'
                .self::e($metric).' · '.self::e($source).' · '.self::e((string)$row['observed_at']).' · '.self::e($fresh).' · '.self::e($status).'</small></article>';
        }
        if($products==='')$products='<article class="learning-card"><strong>UNKNOWN</strong><small class="matrix-meta">Sin métricas canónicas medidas</small></article>';

        self::fields($view['costs'],['status','items'],'costs');
        if($view['costs']!==['status'=>'unknown','items'=>[]])
            throw new InvalidArgumentException('Measured cost authority unavailable.');

        self::fields($view['limits'],['status','items'],'limits');
        $limitStatus=self::one($view['limits']['status'],['measured','unknown'],'limit status');
        if(!is_array($view['limits']['items'])||!array_is_list($view['limits']['items']))
            throw new InvalidArgumentException('Limit items invalid.');

        $limitMeasurement=null;
        if($limitStatus==='unknown'){
            if($view['limits']['items']!==[])throw new InvalidArgumentException('Limit status incoherent.');
        }else{
            if(count($view['limits']['items'])!==1)throw new InvalidArgumentException('Limit status incoherent.');
            $limitMeasurement=self::usageMeasurement($view['limits']['items'][0],$view['observed_at'],'limit');
        }

        self::fields($view['tool_usage'],['status','used','limit','source_ref','observed_at','freshness','age_seconds'],'tool_usage_view');
        $toolStatus=self::one($view['tool_usage']['status'],['measured','unknown'],'tool usage status');
        $toolMeasurement=null;
        if($toolStatus==='unknown'){
            if($view['tool_usage']['used']!==null||$view['tool_usage']['limit']!==null
                ||$view['tool_usage']['source_ref']!==null||$view['tool_usage']['observed_at']!==null
                ||$view['tool_usage']['freshness']!=='unknown'||$view['tool_usage']['age_seconds']!==null)
                throw new InvalidArgumentException('Tool usage unknown incoherent.');
        }else{
            $toolMeasurement=self::usageMeasurement([
                'used'=>$view['tool_usage']['used'],'limit'=>$view['tool_usage']['limit'],
                'source_ref'=>$view['tool_usage']['source_ref'],'observed_at'=>$view['tool_usage']['observed_at'],
                'freshness'=>$view['tool_usage']['freshness'],'age_seconds'=>$view['tool_usage']['age_seconds'],
            ],$view['observed_at'],'tool_usage_view');
        }

        if($limitStatus!==$toolStatus)
            throw new InvalidArgumentException('Limit/tool usage status mismatch.');
        if($toolStatus==='measured'&&$limitMeasurement!==$toolMeasurement)
            throw new InvalidArgumentException('Limit/tool usage measurement mismatch.');

        $limits='';
        if($limitMeasurement!==null){
            $limits='<article class="learning-card"><span>Uso / límite</span><strong>'.self::e((string)$limitMeasurement['used']).' / '.self::e((string)$limitMeasurement['limit']).'</strong><small class="matrix-meta">'
                .self::e($limitMeasurement['source_ref']).' · '.self::e((string)$limitMeasurement['observed_at']).' · '.self::e($limitMeasurement['freshness']).'</small></article>';
        }
        if($limits==='')$limits='<article class="learning-card"><strong>UNKNOWN</strong><small class="matrix-meta">Sin fuente canónica medida</small></article>';

        return '<section class="panel section" data-section="product_analytics_costs"><p class="eyebrow">PRODUCTO / DATOS / COSTES</p><h2>Producto y analítica</h2><div class="learning-grid">'.$products.'</div><h3>Costes medidos</h3><div class="learning-grid"><article class="learning-card"><strong>UNKNOWN</strong><small class="matrix-meta">Sin medición monetaria canónica</small></article></div><h3>Límites</h3><div class="learning-grid">'.$limits.'</div></section>';
    }

    private static function usageMeasurement(mixed $row,int $viewObservedAt,string $label): array
    {
        self::fields($row,['used','limit','source_ref','observed_at','freshness','age_seconds'],$label);
        if(!is_int($row['used'])||$row['used']<0||!is_int($row['limit'])||$row['limit']<0
            ||$row['used']>$row['limit'])
            throw new InvalidArgumentException('Usage measurement invalid.');
        $source=self::text($row['source_ref']);
        if($source!==$row['source_ref'])
            throw new InvalidArgumentException('Usage source invalid.');
        if(!is_int($row['observed_at'])||$row['observed_at']<1||$row['observed_at']>$viewObservedAt)
            throw new InvalidArgumentException('Usage observed_at invalid.');
        $fresh=self::one($row['freshness'],['current','stale'],'usage freshness');
        if(!is_int($row['age_seconds'])||$row['age_seconds']<0
            ||$row['age_seconds']!==$viewObservedAt-$row['observed_at'])
            throw new InvalidArgumentException('Usage age invalid.');
        return [
            'used'=>$row['used'],'limit'=>$row['limit'],'source_ref'=>$source,
            'observed_at'=>$row['observed_at'],'freshness'=>$fresh,'age_seconds'=>$row['age_seconds'],
        ];
    }

    private static function incidentList(mixed $items,string $label): string
    {
        if(!is_array($items)||!array_is_list($items))throw new InvalidArgumentException('Learning incidents invalid.');
        $body='';foreach($items as $item){if(!is_array($item)||!isset($item['title']))throw new InvalidArgumentException('Learning incident invalid.');
            $body.='<li>'.self::e(self::text($item['title'])).self::learningSignal(array_diff_key($item,['title'=>true])).'</li>';}
        if($body==='')$body='<li>UNKNOWN</li>';
        return '<section class="incident-list"><h3>'.self::e($label).'</h3><ul>'.$body.'</ul></section>';
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
*{box-sizing:border-box}html{background:var(--bg)}body{margin:0;color:var(--text);background:var(--bg);font-family:Inter,system-ui,sans-serif}.shell{width:min(100%,1180px);margin:auto;padding:24px 16px 48px}.top{padding:8px 0 20px;border-bottom:1px solid var(--line)}.top p{color:var(--muted);line-height:1.5}.eyebrow{color:var(--muted);font:700 .72rem/1.2 "JetBrains Mono",monospace;letter-spacing:.08em}.eyebrow span{color:var(--cyan)}h1{font-size:clamp(2rem,10vw,3.4rem);margin:.25rem 0}h2{margin:.4rem 0 1rem}.section,.matrix-section,.learning-section{margin-top:16px}.panel{border:1px solid var(--line);border-radius:10px;background:var(--panel);padding:18px}.signal-grid,.ops-header,.learning-grid,.incident-split{display:grid;grid-template-columns:1fr;gap:12px}.signal,.matrix-summary,.learning-card{min-width:0;border:1px solid var(--line);border-left:4px solid var(--muted);padding:14px;background:var(--panel-raised);overflow-wrap:anywhere}.matrix-summary,.learning-card{display:grid;gap:5px}.learning-signal{margin-top:8px;padding-top:8px;border-top:1px solid var(--line)}.incident-list ul{padding-left:20px}.matrix-meta{display:block;color:var(--muted);margin-top:6px;overflow-wrap:anywhere}.matrix-scroll{margin-top:14px;overflow-x:auto;border:1px solid var(--line);border-radius:8px}.matrix-scroll:focus-visible,.issue-link:focus-visible{outline:3px solid var(--amber);outline-offset:3px}table{width:100%;min-width:900px;border-collapse:collapse}th,td{padding:12px;border:1px solid var(--line);text-align:left;vertical-align:top}th{background:var(--panel-raised)}.matrix-cell{min-width:115px}.status-green{border-left-color:var(--green)}.status-red{border-left-color:var(--red)}.status-amber,.status-stale{border-left-color:var(--amber)}.status-unknown{border-left-color:var(--muted)}.signal-head{display:flex;justify-content:space-between;gap:10px;font:700 .72rem/1.2 "JetBrains Mono",monospace;text-transform:uppercase}.state-healthy.fresh-current{border-left-color:var(--green)}.state-critical{border-left-color:var(--red)}.state-degraded,.state-blocked,.fresh-stale{border-left-color:var(--amber)}.fresh-unknown,.state-unknown{border-left-color:var(--muted)}dl{margin:12px 0 0}dl div{display:grid;grid-template-columns:70px minmax(0,1fr);gap:8px;border-top:1px solid var(--line);padding:8px 0}dt{color:var(--muted)}dd{margin:0;overflow-wrap:anywhere}.issue-link{display:inline-block;min-height:44px;margin-top:8px;color:var(--cyan);padding:10px 0}@media(min-width:760px){.shell{padding:36px 28px 64px}.signal-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.ops-header{grid-template-columns:repeat(5,minmax(0,1fr))}.learning-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.incident-split{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation:none!important;transition:none!important;scroll-behavior:auto!important}}
CSS;
    }
}
