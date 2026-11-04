<?php
declare(strict_types=1);

namespace ControlBot\Business;

require_once __DIR__ . '/UiTheme.php';
require_once __DIR__ . '/FactoryAgentActivityUi.php';
require_once __DIR__ . '/FactoryPublicHealthUi.php';

use ControlBot\Ui\UiTheme;
use InvalidArgumentException;

final class FactoryOrchestratorLiveUi
{
    private const CENTRAL = ['ACTIVE','READY','BLOCKED','IDLE','STALE','UNKNOWN'];
    private const FRONT = ['available','reserved','in_review','blocked','merged','unknown','STALE'];
    private const FRESH = ['current','stale','unknown'];
    private const FRONT_LABELS = [
        'available' => 'Disponible', 'reserved' => 'Reservado', 'in_review' => 'En revisión', 'blocked' => 'Bloqueado',
        'merged' => 'Fusionado', 'unknown' => 'Desconocido', 'STALE' => 'Desactualizado',
    ];
    private const CENTRAL_LABELS = [
        'ACTIVE' => 'Activo', 'READY' => 'Listo', 'BLOCKED' => 'Bloqueado', 'IDLE' => 'Inactivo',
        'STALE' => 'Desactualizado', 'UNKNOWN' => 'Desconocido',
    ];
    private const FRESH_LABELS = ['current' => 'Vigente', 'stale' => 'Desactualizado', 'unknown' => 'Sin evidencia'];

    public static function render(array $view): string
    {
        $expected = ['version','observed_at','source_snapshot','read_only','central','fronts','owner_decisions','fingerprint'];
        if (array_key_exists('agent_activity', $view)) $expected[] = 'agent_activity';
        if (array_key_exists('signal_summary', $view)) $expected[] = 'signal_summary';
        if (array_key_exists('collector_failure', $view)) $expected[] = 'collector_failure';
        if (array_key_exists('blocker_explanations', $view)) $expected[] = 'blocker_explanations';
        if (array_key_exists('public_health', $view)) $expected[] = 'public_health';
        self::fields($view, $expected, 'view');
        if ($view['version'] !== 1 || $view['read_only'] !== true || ! is_int($view['observed_at'])) {
            throw new InvalidArgumentException('Orchestrator UI view invalid.');
        }
        if (! is_array($view['central']) || ! is_array($view['fronts']) || ! array_is_list($view['fronts'])) {
            throw new InvalidArgumentException('Orchestrator UI projection invalid.');
        }
        if (! is_array($view['owner_decisions']) || ! array_is_list($view['owner_decisions'])) {
            throw new InvalidArgumentException('Orchestrator UI decisions invalid.');
        }

        $central = self::central($view['central']);
        $edges = '';
        $events = '';
        $groups = [];
        $repoOrder = [];
        $statusCount = [];
        foreach ($view['fronts'] as $front) {
            [$card, $edge, $event, $repo, $status] = self::front($front);
            if (! isset($groups[$repo])) {
                $groups[$repo] = '';
                $repoOrder[] = $repo;
            }
            $groups[$repo] .= $card;
            $edges .= $edge;
            $events .= $event;
            $statusCount[$status] = ($statusCount[$status] ?? 0) + 1;
        }
        $fronts = '';
        foreach ($repoOrder as $ix => $repo) {
            $total = substr_count($groups[$repo], 'class="front ');
            $fronts .= '<section class="repo-group" data-repo-ix="' . ($ix + 1) . '" aria-label="' . self::e($repo) . '">'
                . '<h3 class="repo-name">' . self::e($repo) . '<span class="count">' . $total . '</span></h3>'
                . '<div class="front-list">' . $groups[$repo] . '</div></section>';
        }
        if ($fronts === '') {
            $fronts = '<p class="empty" role="status">Sin frentes activos.</p>';
        }

        $decisions = '';
        $pending = 0;
        foreach ($view['owner_decisions'] as $decision) {
            $decisions .= self::decision($decision);
            $pending++;
        }
        if ($decisions === '') {
            $decisions = '<p class="empty" role="status">Sin decisiones humanas pendientes.</p>';
        }

        $activity = FactoryAgentActivityUi::renderSection($view['agent_activity'] ?? null);
        $signalWarning = self::signalWarning($view['signal_summary'] ?? null);
        $collectorWarning = self::collectorWarning($view['collector_failure'] ?? null);
        $blockerPanel = self::blockerPanel($view['blocker_explanations'] ?? null);
        $healthPanel = FactoryPublicHealthUi::renderSection($view['public_health'] ?? null);
        $motion = self::motionSeconds($view['central']);
        $filters = self::filters($repoOrder, $statusCount, $pending);
        $humanCount = $pending > 0 ? '<span class="count attention">' . $pending . '</span>' : '<span class="count">0</span>';
        return '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
            . '<title>ControlBot · Orquestador en vivo</title><style>' . self::styles(count($repoOrder), array_keys(self::FRONT_LABELS)) . '</style></head>'
            . '<body><main class="shell" aria-labelledby="orchestrator-title" style="--flow:' . $motion . 's">'
            . '<header class="top"><p class="eyebrow">CONTROLBOT / ORQUESTADOR</p>'
            . '<h1 id="orchestrator-title">Orquestador en vivo</h1>'
            . '<p class="lede">Solo lectura · la fuente de verdad permanece en GitHub.</p>'
            . '<div class="safety"><span class="chip ok" data-read-only="true"><i aria-hidden="true">●</i>solo lectura</span>'
            . '<span class="chip warn" data-kill-switch="UNKNOWN"><i aria-hidden="true">?</i>Kill switch: UNKNOWN</span></div></header>'
            . $filters
            . $collectorWarning . $signalWarning
            . '<section class="panel human" id="humano" data-section="human"><p class="eyebrow">HUMANO</p>'
            . '<h2>Decisiones tuyas ' . $humanCount . '</h2><div class="human-grid">' . $decisions . '</div></section>'
            . '<section class="stage" id="frentes" aria-label="Topología del orquestador">' . $central
            . '<div class="edges" aria-hidden="true">' . $edges . '</div>'
            . '<div class="front-grid">' . $fronts . '</div></section>'
            . $blockerPanel . $activity . $healthPanel
            . '<section class="panel bus" data-section="event_bus"><p class="eyebrow">BUS VISUAL</p>'
            . '<h2>Eventos observados</h2><ol>' . $events . '</ol></section>'
            . '</main></body></html>';
    }

    /** CSS-only view filters (radio + :has): no script, no network, nothing written. */
    private static function filters(array $repos, array $statusCount, int $pending): string
    {
        $repoControls = '<input type="radio" name="f-repo" id="fr-0" checked><label for="fr-0">Todos</label>';
        foreach ($repos as $ix => $repo) {
            $short = str_starts_with($repo, 'pl0n3r/') ? substr($repo, 7) : $repo;
            $repoControls .= '<input type="radio" name="f-repo" id="fr-' . ($ix + 1) . '"><label for="fr-' . ($ix + 1) . '">'
                . self::e($short) . '</label>';
        }
        $typeControls = '<input type="radio" name="f-type" id="ft-all" checked><label for="ft-all">Todos</label>';
        foreach (self::FRONT_LABELS as $status => $label) {
            if (! isset($statusCount[$status])) {
                continue;
            }
            $typeControls .= '<input type="radio" name="f-type" id="ft-' . self::e($status) . '"><label for="ft-'
                . self::e($status) . '">' . self::e($label) . ' <b>' . $statusCount[$status] . '</b></label>';
        }
        return '<nav class="filters" aria-label="Filtros de vista">'
            . '<div class="filter-group" role="radiogroup" aria-labelledby="flt-repo"><span id="flt-repo" data-filter="repository">Repositorio</span>' . $repoControls . '</div>'
            . '<div class="filter-group" role="radiogroup" aria-labelledby="flt-type"><span id="flt-type" data-filter="type">Tipo / estado</span>' . $typeControls . '</div>'
            . '<a class="filter-human' . ($pending > 0 ? ' attention' : '') . '" data-filter="human" href="#humano">Humano'
            . ($pending > 0 ? ' <b>' . $pending . '</b>' : '') . '</a></nav>';
    }

    /** Grounded read-only Issue declarations; UNKNOWN never becomes an action. */
    private static function blockerPanel(mixed $rows): string
    {
        if($rows===null)return '';
        if(!is_array($rows)||!array_is_list($rows)||count($rows)>50)
            throw new InvalidArgumentException('blocker panel invalid.');
        $html='';
        foreach($rows as $row){
            self::fields($row,['issue_ref','source_ref','observed_at','freshness','cause',
                'actor','next_action','blocked_at','age_seconds','mvp_impact','evidence_status'],'blocker row');
            $ref=$row['issue_ref'];
            if(!is_string($ref)||preg_match('~^github:pl0n3r/(?:Factory|Condor|GrindFlow|brvtal|ControlBot|AutoFactory|FactoryRunner)#[1-9][0-9]*$~D',$ref)!==1
                ||$row['source_ref']!==$ref||$row['freshness']!=='current'
                ||!is_int($row['observed_at'])||$row['observed_at']<1
                ||!in_array($row['actor'],['owner','agent','external','UNKNOWN'],true)
                ||!in_array($row['evidence_status'],['DECLARED','UNKNOWN'],true))
                throw new InvalidArgumentException('blocker identity invalid.');
            $unknown=$row['evidence_status']==='UNKNOWN';
            if(!is_string($row['cause'])||!is_string($row['next_action'])
                ||($unknown&&($row['cause']!=='UNKNOWN'||$row['next_action']!=='UNKNOWN'
                    ||$row['actor']!=='UNKNOWN'||$row['blocked_at']!==null
                    ||$row['age_seconds']!==null||$row['mvp_impact']!==null))
                ||(!$unknown&&(!is_int($row['blocked_at'])||!is_int($row['age_seconds'])
                    ||$row['blocked_at']<1||$row['age_seconds']<0||!is_bool($row['mvp_impact']))))
                throw new InvalidArgumentException('blocker evidence inconsistent.');
            $age=$unknown?'UNKNOWN':(string)$row['age_seconds'].'s';
            $impact=($row['mvp_impact']===true)?'MVP declarado':'impacto MVP: UNKNOWN';
            $html.='<article class="front blocker-explanation">'
                .'<h3>'.self::e($ref).'</h3><p>Causa: '.self::e($row['cause']).'</p>'
                .'<p>Responsable declarado: '.self::e($row['actor']).'</p>'
                .'<p>Bloqueado desde: '.self::e($unknown?'UNKNOWN':gmdate('Y-m-d H:i:s',$row['blocked_at']).' UTC').'</p>'
                .'<p>Antigüedad: '.self::e($age).'</p>'
                .'<p>Siguiente acción declarada: '.self::e($row['next_action']).'</p>'
                .'<small>Fuente: '.self::e($ref).' · '.self::e($row['evidence_status'])
                .' · '.self::e($impact).' · read-only</small></article>';
        }
        return '<section class="panel" data-section="blockers_explained">'
            .'<h2>Bloqueos explicados</h2><p>Evidencia declarada en el Issue; no equivale a aprobación ni identidad del último editor.</p>'
            .($html===''?'<p role="status">UNKNOWN — sin inventario confirmado de bloqueos.</p>':'<div class="front-grid">'.$html.'</div>')
            .'</section>';
    }

    private static function collectorWarning(mixed $raw): string
    {
        if($raw===null)return '';
        self::fields($raw,['freshness','reason','last_success_at','detected_at'],'collector_failure');
        $reasons=['evidence_invalid','snapshot_build_failed','snapshot_size_invalid',
            'temp_write_failed','atomic_rename_failed','snapshot_directory_unwritable',
            'snapshot_target_invalid','internal_error','collector_failed'];
        if(($raw['freshness']??null)!=='stale'||!in_array($raw['reason']??null,$reasons,true)
            ||!is_int($raw['detected_at'])||$raw['detected_at']<1)
            throw new InvalidArgumentException('collector_failure invalid.');
        $last=$raw['last_success_at'];
        if($last!==null&&(!is_int($last)||$last<1||$last>$raw['detected_at']))
            throw new InvalidArgumentException('collector_failure last success invalid.');
        $label=$last===null?'UNKNOWN':gmdate('Y-m-d H:i:s', $last).' UTC';
        return '<section class="panel" role="status" data-collector-stale="true">'
            . '<h2>STALE — colecta fallida</h2><p>Motivo: ' . self::e($raw['reason'])
            . ' · Último éxito: ' . self::e($label)
            . '. El estado anterior no se considera actividad actual.</p></section>';
    }

    private static function signalWarning(mixed $raw): string
    {
        if($raw===null)return '';
        self::fields($raw,['truncated','omitted','reason'],'signal_summary');
        self::fields($raw['omitted']??null,['blockers','owner_decisions','work'],'signal_summary.omitted');
        if(!is_bool($raw['truncated']))throw new InvalidArgumentException('signal_summary.invalid.');
        $sum=0;
        foreach($raw['omitted'] as $value){
            if(!is_int($value)||$value<0||$value>10000)
                throw new InvalidArgumentException('signal_summary count invalid.');
            $sum+=$value;
        }
        if($raw['truncated']!==($sum>0)
            ||$raw['reason']!==($sum>0?'bounded_signal_budget':'none'))
            throw new InvalidArgumentException('signal_summary inconsistent.');
        if(!$raw['truncated'])return '';
        return '<section class="panel" role="status" data-signal-truncated="true">'
            . '<h2>Evidencia parcial: señales truncadas</h2><p>'
            . 'Omitidas: bloqueos=' . $raw['omitted']['blockers']
            . ', decisiones=' . $raw['omitted']['owner_decisions']
            . ', trabajo=' . $raw['omitted']['work']
            . '. Los totales y la cobertura completa no están verificados.</p></section>';
    }

    private static function central(array $central): string
    {
        self::fields(
            $central,
            ['activity_state','available','reserved','blocked','source_ref','observed_at','freshness','age_seconds'],
            'central',
        );
        $state = self::one($central['activity_state'], self::CENTRAL, 'central.state');
        $fresh = self::one($central['freshness'], self::FRESH, 'central.freshness');
        $counts = [];
        foreach (['available','reserved','blocked'] as $key) {
            $value = $central[$key];
            if ($value !== null && (! is_int($value) || $value < 0)) {
                throw new InvalidArgumentException('central count invalid.');
            }
            $counts[$key] = $value === null ? 'UNKNOWN' : (string) $value;
        }
        $class = self::stateClass($state, $fresh);
        $label = self::CENTRAL_LABELS[$state];
        return '<article class="central ' . $class . '" data-node="central" data-state="' . self::e($state) . '">'
            . '<p class="eyebrow">NODO CENTRAL</p>'
            . '<strong class="state"><i class="dot" aria-hidden="true"></i><span>' . self::e($label) . '</span></strong>'
            . '<dl><div><dt>disponible</dt><dd>' . $counts['available'] . '</dd></div>'
            . '<div><dt>reservado</dt><dd>' . $counts['reserved'] . '</dd></div>'
            . '<div><dt>bloqueado</dt><dd>' . $counts['blocked'] . '</dd></div></dl>'
            . self::centralAge($central['age_seconds'], $fresh) . '</article>';
    }

    private static function centralAge(mixed $age, string $fresh): string
    {
        $freshLabel = self::e(self::FRESH_LABELS[$fresh]);
        if ($age === null) {
            return '<small title="freshness=' . self::e($fresh) . '">' . $freshLabel . '</small>';
        }
        $seconds = self::age($age);
        return '<small title="freshness=' . self::e($fresh) . ' · antigüedad=' . $seconds . 's">'
            . $freshLabel . ' · ' . self::e(self::ageLabel($seconds)) . '</small>';
    }

    private static function front(mixed $front): array
    {
        self::fields(
            $front,
            ['id','repository_ref','issue_ref','status','progress_percent','progress_state',
             'source_ref','observed_at','freshness','age_seconds'],
            'front',
        );
        $id = self::text($front['id'], 'front.id');
        $repo = self::text($front['repository_ref'], 'front.repository');
        $status = self::one($front['status'], self::FRONT, 'front.status');
        $fresh = self::one($front['freshness'], self::FRESH, 'front.freshness');
        $issue = self::issueHref($front['issue_ref']);
        $age = self::age($front['age_seconds']);
        $progress = $front['progress_percent'];
        if ($progress !== null && (! is_int($progress) || $progress < 0 || $progress > 100)) {
            throw new InvalidArgumentException('front.progress invalid.');
        }
        $progressText = $progress === null ? 'UNKNOWN' : (string) $progress . '%';
        $class = self::stateClass($status, $fresh);
        $label = self::FRONT_LABELS[$status];
        $number = preg_match('~#([1-9][0-9]*)$~', $issue) === 1 ? '#' . substr($issue, (int) strrpos($issue, '/') + 1) : 'Issue';
        $meter = $progress === null ? '' : '<span class="meter" aria-hidden="true"><i></i></span>';
        $card = '<article class="front ' . $class . '" data-front="' . self::e($id)
            . '" data-repository="' . self::e($repo) . '" data-type="' . self::e($status)
            . '" style="--p:' . ($progress ?? 0) . '">'
            . '<p class="eyebrow">' . self::e($repo) . '</p>'
            . '<h3 class="status"><i class="glyph" aria-hidden="true">' . self::glyph($class) . '</i>' . self::e($label) . '</h3>'
            . '<p class="progress"><span>Progreso: <strong>' . self::e($progressText) . '</strong></span>' . $meter . '</p>'
            . '<small title="freshness=' . self::e($fresh) . ' · antigüedad=' . $age . 's">'
            . self::e(self::FRESH_LABELS[$fresh]) . ' · ' . self::e(self::ageLabel($age)) . '</small>'
            . '<a class="open" href="' . self::e($issue) . '" rel="noreferrer noopener" aria-label="Abrir evidencia de '
            . self::e($repo) . ' ' . self::e($number) . '">' . self::e($number) . '</a></article>';
        $edge = '<i class="edge ' . $class . '" data-from="central" data-to="' . self::e($id) . '"></i>';
        $event = '<li><b>' . self::e($repo) . '</b> · ' . self::e($label)
            . ' · ' . self::e(self::ageLabel($age)) . ' · ' . self::e(self::FRESH_LABELS[$fresh]) . '</li>';
        return [$card, $edge, $event, $repo, $status];
    }

    private static function decision(mixed $d): string
    {
        $legacy=['id','issue_ref','source_ref','observed_at','freshness','age_seconds'];
        $actual=array_keys(is_array($d)?$d:[]);sort($legacy);sort($actual);
        if($actual===$legacy)return self::legacyDecision($d);
        self::fields($d,['id','issue_ref','repository_ref','issue_number','format','title','title_simple',
            'summary_simple','explain_simple','why_recommended','blocks','options','recommendation',
            'safe_default','expires_at','seconds_left','expired','source_ref','observed_at','freshness',
            'age_seconds'],'decision');
        $id=self::text($d['id'],'decision.id');$fresh=self::one($d['freshness'],self::FRESH,'decision.freshness');
        $age=self::age($d['age_seconds']);$href=self::issueHref($d['issue_ref']);
        $repo=self::decisionRepository($d['repository_ref']);$number=self::decisionNumber($d['issue_number']);
        if($href!=='https://github.com/'.$repo.'/issues/'.$number)throw new InvalidArgumentException('decision identity mismatch.');
        $title=self::decisionText($d['title'],'decision.title');
        if(self::one($d['format'],['legacy','structured'],'decision.format')==='legacy')
            return self::legacyRichDecision($id,$fresh,$age,$href,$title);
        return self::structuredDecision($d,$id,$fresh,$age,$href,$repo,$number);
    }

    private static function structuredDecision(array $d,string $id,string $fresh,int $age,string $href,string $repo,int $number): string
    {
        $recommended=self::decisionOptionId($d['recommendation']);$safe=self::decisionOptionId($d['safe_default']);
        if(!is_array($d['options'])||!array_is_list($d['options'])||$d['options']===[])throw new InvalidArgumentException('decision options invalid.');
        $cards='';$seen=[];
        foreach($d['options'] as $option){
            [$optionId,$card]=self::decisionOptionCard($option,$repo,$number,$recommended,$safe);
            if(isset($seen[$optionId]))throw new InvalidArgumentException('decision option duplicate.');
            $seen[$optionId]=true;$cards.=$card;
        }
        if(!isset($seen[$recommended])||!isset($seen[$safe]))throw new InvalidArgumentException('decision recommendation invalid.');
        $explain=$d['explain_simple']===null?'':'<p>'.self::e(self::decisionText($d['explain_simple'],'decision.explain_simple')).'</p>';
        return '<article class="decision decision-rich '.self::stateClass('pending',$fresh).'" data-decision="'.self::e($id).'" data-format="structured">'
            .'<p class="eyebrow">'.self::e($repo).' #'.$number.'</p><h3>'.self::e(self::decisionText($d['title_simple'],'decision.title_simple')).'</h3>'
            .'<p>'.self::e(self::decisionText($d['summary_simple'],'decision.summary_simple')).'</p>'.$explain
            .'<p><strong>Bloquea:</strong> '.self::e(self::decisionText($d['blocks'],'decision.blocks')).'</p>'
            .'<p><strong>Por qué se recomienda:</strong> '.self::e(self::decisionText($d['why_recommended'],'decision.why_recommended')).'</p>'
            .self::decisionExpiry($d).'<div class="choices">'.$cards.'</div><small>freshness='.self::e($fresh).' · antigüedad='.$age.'s</small>'
            .'<a href="'.self::e($href).'" rel="noreferrer noopener">Abrir Issue</a></article>';
    }

    private static function decisionOptionCard(mixed $o,string $repo,int $number,string $recommended,string $safe): array
    {
        self::fields($o,['id','label','effect','pros','cons','risk','cost','reversible','explain_simple'],'decision.option');
        $id=self::decisionOptionId($o['id']);
        if(!is_bool($o['reversible']))throw new InvalidArgumentException('decision reversible invalid.');
        $command=self::decisionCommand($repo,$number,$id);
        $classes='choice'.($id===$recommended?' recommended':'').($id===$safe?' safe-default':'');
        $badges=($id===$recommended?'<span class="badge">RECOMENDADA</span>':'').($id===$safe?'<span class="badge safe">DEFAULT SEGURO</span>':'');
        $cost=$o['cost']===null?'':'<span>Coste: '.self::e(self::decisionText($o['cost'],'decision.option.cost')).'</span>';
        $explain=$o['explain_simple']===null?'':'<p>'.self::e(self::decisionText($o['explain_simple'],'decision.option.explain')).'</p>';
        $card='<article class="'.$classes.'" data-option="'.self::e($id).'"><header><strong>'.self::e($id).' · '
            .self::e(self::decisionText($o['label'],'decision.option.label')).'</strong>'.$badges.'</header><p>'
            .self::e(self::decisionText($o['effect'],'decision.option.effect')).'</p>'.$explain.'<div class="choice-meta"><span>Riesgo: '
            .self::e(self::decisionText($o['risk'],'decision.option.risk')).'</span>'.$cost.'<span>Reversible: '.($o['reversible']?'sí':'no').'</span></div>'
            .self::decisionList('Pros',$o['pros']).self::decisionList('Contras',$o['cons']).'<div class="copy-row"><code>'.self::e($command)
            .'</code><button type="button" data-copy-command="'.self::e($command).'" aria-label="Copiar comando para opción '.self::e($id).'">Copiar</button></div></article>';
        return [$id,$card];
    }

    private static function decisionExpiry(array $d): string
    {
        if($d['expires_at']===null){if($d['seconds_left']!==null||$d['expired']!==null)throw new InvalidArgumentException('decision expiry invalid.');return '';}
        if(!is_string($d['expires_at'])||!is_int($d['seconds_left'])||!is_bool($d['expired'])||$d['seconds_left']<0)
            throw new InvalidArgumentException('decision expiry invalid.');
        $class=$d['expired']?'expired':'active';$text=$d['expired']?'Ventana caducada':'Ventana: '.$d['seconds_left'].'s restantes';
        return '<p class="expiry '.$class.'" data-seconds-left="'.$d['seconds_left'].'">'.$text.'</p>';
    }

    private static function legacyRichDecision(string $id,string $fresh,int $age,string $href,string $title): string
    {
        return '<article class="decision '.self::stateClass('pending',$fresh).'" data-decision="'.self::e($id).'" data-format="legacy"><strong>'
            .self::e($title).'</strong><span>Formato legacy · abre el Issue para revisar opciones.</span><span>Antigüedad: '.$age.'s · freshness='
            .self::e($fresh).'</span><a href="'.self::e($href).'" rel="noreferrer noopener">Abrir Issue</a></article>';
    }

    private static function legacyDecision(array $d): string
    {
        self::fields($d,['id','issue_ref','source_ref','observed_at','freshness','age_seconds'],'decision');
        $id=self::text($d['id'],'decision.id');$fresh=self::one($d['freshness'],self::FRESH,'decision.freshness');
        $age=self::age($d['age_seconds']);$href=self::issueHref($d['issue_ref']);
        return '<article class="decision '.self::stateClass('pending',$fresh).'" data-decision="'.self::e($id).'"><strong>Decisión pendiente</strong>'
            .'<span>Antigüedad: '.$age.'s</span><span>freshness='.self::e($fresh).'</span><a href="'.self::e($href).'" rel="noreferrer noopener">Abrir Issue</a></article>';
    }

    private static function decisionCommand(string $repo,int $number,string $id): string
    {
        self::decisionRepository($repo);self::decisionNumber($number);self::decisionOptionId($id);
        return 'gh issue comment '.$number.' -R '.$repo.' --body "'.'/'.'decidir '.$id.'"';
    }
    private static function decisionRepository(mixed $repo): string
    {
        $allowed=['pl0n3r/Factory','pl0n3r/Condor','pl0n3r/GrindFlow','pl0n3r/brvtal','pl0n3r/ControlBot','pl0n3r/AutoFactory','pl0n3r/FactoryRunner'];
        if(!is_string($repo)||!in_array($repo,$allowed,true))throw new InvalidArgumentException('decision repository invalid.');
        return $repo;
    }
    private static function decisionNumber(mixed $number): int
    {if(!is_int($number)||$number<1)throw new InvalidArgumentException('decision number invalid.');return $number;}
    private static function decisionOptionId(mixed $id): string
    {if(!is_string($id)||preg_match('/^[A-D]$/D',$id)!==1)throw new InvalidArgumentException('decision option id invalid.');return $id;}
    private static function decisionText(mixed $text,string $label): string
    {
        if(!is_string($text))throw new InvalidArgumentException($label.' invalid.');$text=trim($text);
        if($text===''||strlen($text)>600||preg_match('/[\\x00-\\x1f\\x7f]/',$text)===1)throw new InvalidArgumentException($label.' invalid.');
        return $text;
    }
    private static function decisionList(string $label,mixed $items): string
    {
        if(!is_array($items)||!array_is_list($items)||count($items)>6)throw new InvalidArgumentException('decision list invalid.');
        if($items===[])return '';$html='<div class="choice-list"><b>'.self::e($label).'</b><ul>';
        foreach($items as $item)$html.='<li>'.self::e(self::decisionText($item,'decision list item')).'</li>';
        return $html.'</ul></div>';
    }

    /** Human age: exact seconds stay in title/data attributes, the label is for reading. */
    private static function ageLabel(int $seconds): string
    {
        if ($seconds < 60) {
            return 'hace ' . $seconds . ' s';
        }
        if ($seconds < 3600) {
            return 'hace ' . intdiv($seconds, 60) . ' min';
        }
        if ($seconds < 86400) {
            return 'hace ' . intdiv($seconds, 3600) . ' h';
        }
        return 'hace ' . intdiv($seconds, 86400) . ' d';
    }

    /** Status glyph so state never depends on color alone. */
    private static function glyph(string $class): string
    {
        return match ($class) {
            'state-current' => '●',
            'state-stale' => '◐',
            'state-blocked' => '■',
            default => '?',
        };
    }

    private static function motionSeconds(array $central): int
    {
        $reserved = $central['reserved'] ?? 0;
        if (! is_int($reserved) || $reserved < 0) {
            return 6;
        }
        return max(1, 6 - min(5, $reserved));
    }

    private static function stateClass(string $state, string $fresh): string
    {
        if ($fresh === 'unknown' || strtolower($state) === 'unknown') {
            return 'state-unknown';
        }
        if ($fresh === 'stale' || $state === 'STALE') {
            return 'state-stale';
        }
        if (in_array(strtolower($state), ['blocked'], true)) {
            return 'state-blocked';
        }
        return 'state-current';
    }

    private static function issueHref(mixed $value): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('issue_ref invalid.');
        }
        if (preg_match('~^https://github\.com/pl0n3r/[A-Za-z0-9_.-]+/issues/[1-9][0-9]*$~D', $value) === 1) {
            return $value;
        }
        if (preg_match('~^github:(pl0n3r/[A-Za-z0-9_.-]+)#([1-9][0-9]*)$~D', $value, $match) === 1) {
            return 'https://github.com/' . $match[1] . '/issues/' . $match[2];
        }
        throw new InvalidArgumentException('issue_ref invalid.');
    }

    private static function age(mixed $value): int
    {
        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException('age invalid.');
        }
        return $value;
    }

    private static function one(mixed $value, array $allowed, string $label): string
    {
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function text(mixed $value, string $label): string
    {
        if (! is_string($value) || trim($value) === '' || strlen($value) > 240) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return trim($value);
    }

    private static function fields(mixed $row, array $expected, string $label): void
    {
        if (! is_array($row) || array_is_list($row)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        $actual = array_keys($row);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new InvalidArgumentException($label . ' fields invalid.');
        }
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function styles(int $repoCount = 0, array $statuses = []): string
    {
        $dynamic = '';
        for ($ix = 1; $ix <= min($repoCount, 24); $ix++) {
            $dynamic .= '.shell:has(#fr-' . $ix . ':checked) .repo-group:not([data-repo-ix="' . $ix . '"]){display:none}';
        }
        foreach ($statuses as $status) {
            if (! is_string($status) || preg_match('/^[A-Za-z_]{1,16}$/D', $status) !== 1) {
                continue;
            }
            $dynamic .= '.shell:has(#ft-' . $status . ':checked) .front-list .front:not([data-type="' . $status . '"]){display:none}'
                . '.shell:has(#ft-' . $status . ':checked) .repo-group:not(:has(.front[data-type="' . $status . '"])){display:none}';
        }
        return UiTheme::tokensCss() . <<<'CSS'
:root{--duration-quick:150ms;--duration-fast:250ms;--ease-in-out:ease-in-out;--ease-smooth-out:cubic-bezier(.22,1,.36,1)}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:400 1rem/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;font-variant-numeric:tabular-nums;-webkit-font-smoothing:antialiased}
h1,h2,h3{margin:0;text-wrap:balance;line-height:1.2}
.shell{max-width:1240px;margin:0 auto;padding:20px 16px 64px;display:grid;gap:16px}
.eyebrow{display:none}
.top{display:grid;gap:4px 16px;padding:4px 0 0}
.top h1{font-size:1.5rem;font-weight:650;letter-spacing:-.01em}
.lede{margin:0;color:var(--muted);font-size:.875rem}
.safety{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
.chip{display:inline-flex;align-items:center;gap:7px;min-height:28px;padding:0 11px;border:1px solid var(--line-strong);border-radius:999px;background:var(--panel);font-size:.8125rem;font-weight:600}
.chip i{font-style:normal}.chip.ok i{color:var(--green)}.chip.warn i{color:var(--amber)}
.filters{display:flex;flex-wrap:wrap;align-items:center;gap:10px 22px;padding:10px 0;border-bottom:1px solid var(--line);background:var(--bg)}
.filter-group{display:flex;flex-wrap:wrap;align-items:center;gap:6px;position:relative}
.filter-group>span{margin-right:4px;color:var(--muted);font-size:.8125rem;font-weight:600}
.filter-group input{position:absolute;opacity:0;width:1px;height:1px;pointer-events:none}
.filter-group label,.filter-human{display:inline-flex;align-items:center;gap:7px;min-height:36px;padding:0 12px;border:1px solid var(--line);border-radius:8px;background:var(--panel);color:var(--text);font-size:.875rem;font-weight:600;text-decoration:none;cursor:pointer;
transition:background-color var(--duration-quick) var(--ease-in-out),border-color var(--duration-quick) var(--ease-in-out),color var(--duration-quick) var(--ease-in-out)}
.filter-group label b,.filter-human b{min-width:20px;padding:0 6px;border-radius:999px;background:var(--panel-raised);font-size:.75rem;text-align:center}
.filter-group label:hover,.filter-human:hover{background:var(--control-hover)}
.filter-group input:checked+label{background:var(--accent-surface);border-color:var(--cyan);color:var(--cyan)}
.filter-group input:focus-visible+label{outline:3px solid var(--cyan);outline-offset:2px}
.filter-human{margin-left:auto}.filter-human.attention{border-color:var(--warning-border);background:var(--warning-bg);color:var(--warning-text)}
.filter-human.attention b{background:var(--warning-text);color:var(--warning-bg)}
.panel{background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:16px 18px}
.panel>h2{font-size:1.125rem;font-weight:650;display:flex;align-items:center;gap:10px;margin-bottom:12px}
.panel>p{margin:0 0 12px;color:var(--body-soft);font-size:.9375rem}
.count{display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:22px;padding:0 7px;border-radius:999px;background:var(--panel-raised);color:var(--text);font-size:.75rem;font-weight:650}
.count.attention{background:var(--warning-bg);color:var(--warning-text);border:1px solid var(--warning-border)}
.panel[data-collector-stale],.panel[data-signal-truncated]{background:var(--warning-bg);border-color:var(--warning-border);color:var(--warning-text)}
.panel[data-collector-stale]>p,.panel[data-signal-truncated]>p{color:inherit}
.human{border-color:var(--cyan);background:var(--accent-surface)}
.human-grid{display:grid;grid-template-columns:1fr;gap:12px}
.decision{display:grid;gap:6px;padding:14px 16px;background:var(--panel);border:1px solid var(--line);border-radius:10px}
.decision>strong{font-size:1rem}.decision>span{color:var(--muted);font-size:.875rem;margin:0}
.decision a,.front .open{display:inline-flex;align-items:center;justify-self:start;min-height:36px;padding:0 12px;border:1px solid var(--line-strong);border-radius:8px;color:var(--cyan);font-size:.875rem;font-weight:650;text-decoration:none;
transition:background-color var(--duration-quick) var(--ease-in-out),border-color var(--duration-quick) var(--ease-in-out)}
.decision a:hover,.front .open:hover{background:var(--control-hover);border-color:var(--cyan)}
.decision-rich h3{font-size:1.0625rem;font-weight:650}.decision-rich p{margin:0;color:var(--body-soft);font-size:.9375rem}
.choices{display:grid;gap:10px;margin:8px 0}.choice{display:grid;gap:6px;padding:12px;border:1px solid var(--line);border-radius:8px;background:var(--panel-raised)}
.choice header{display:flex;flex-wrap:wrap;align-items:center;gap:8px}.choice.recommended{border-color:var(--green)}.choice.safe-default{box-shadow:inset 0 0 0 1px var(--info-border)}
.choice p{margin:0;font-size:.9375rem}.choice-meta{display:flex;flex-wrap:wrap;gap:6px 14px;color:var(--muted);font-size:.8125rem}.choice-list ul{margin:4px 0 0;padding-left:18px;font-size:.875rem}
.badge{padding:2px 9px;border-radius:999px;background:var(--green);color:var(--on-green);font-size:.75rem;font-weight:650}
.badge.safe{background:var(--accent-surface);color:var(--cyan);border:1px solid var(--cyan)}
.copy-row{display:flex;gap:8px;align-items:center}.copy-row code{flex:1;min-width:0;overflow-x:auto;padding:7px 9px;border-radius:6px;background:var(--field-bg);font:.8125rem ui-monospace,Menlo,Consolas,monospace}
.copy-row button{min-height:36px;padding:0 12px;border:1px solid var(--line-strong);border-radius:8px;background:var(--panel);color:var(--text);font:inherit;font-weight:650;cursor:pointer}
.expiry{margin:0;font-size:.8125rem;font-weight:650}.expiry.active{color:var(--amber)}.expiry.expired{color:var(--red)}
.stage{display:grid;gap:14px;scroll-margin-top:72px}
.edges{display:none}.edge{display:none}
.central{display:flex;flex-wrap:wrap;align-items:center;gap:10px 24px;padding:14px 18px;background:var(--panel);border:1px solid var(--line);border-radius:10px}
.central .state{display:inline-flex;align-items:center;gap:10px;font-size:1.25rem;font-weight:650}
.central .dot{width:10px;height:10px;border-radius:50%;background:var(--muted)}
.central.state-current .dot{background:var(--green);animation:pulse var(--flow,6s) ease-in-out infinite}
.central.state-stale .dot{background:var(--amber)}.central.state-blocked .dot{background:var(--red)}
.central dl{display:flex;flex-wrap:wrap;gap:8px;margin:0}
.central dl div{display:grid;gap:0;min-width:96px;padding:6px 14px;background:var(--panel-raised);border-radius:8px}
.central dt{order:2;color:var(--muted);font-size:.75rem}.central dd{order:1;margin:0;font-size:1.375rem;font-weight:650;line-height:1.2}
.central small{color:var(--muted);font-size:.8125rem}
.front-grid{display:grid;gap:18px}
.repo-group{display:grid;gap:8px}
.repo-name{display:flex;align-items:center;gap:8px;font-size:.9375rem;font-weight:650}
.front-list{display:grid;border:1px solid var(--line);border-radius:10px;overflow:hidden;background:var(--panel)}
.front-list .front{display:grid;grid-template-columns:1fr auto;align-items:center;gap:8px 12px;padding:10px 14px;min-height:52px;border:0;border-top:1px solid var(--line);border-radius:0;background:transparent}
.front-list .front:first-child{border-top:0}
.front .eyebrow{display:block;position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
.status{display:inline-flex;align-items:center;gap:8px;width:fit-content;margin:0;padding:3px 11px;border:1px solid currentColor;border-radius:999px;font-size:.8125rem;font-weight:650;color:var(--muted)}
.status .glyph{font-style:normal;font-size:.75rem}
.state-current .status{color:var(--green)}.state-stale .status{color:var(--amber)}.state-blocked .status{color:var(--red)}.state-unknown .status{color:var(--muted)}
.progress{grid-column:1/-1;display:flex;align-items:center;gap:12px;margin:0;color:var(--body-soft);font-size:.875rem}
.progress .meter{flex:1;min-width:80px;height:6px;border-radius:3px;background:var(--panel-raised);overflow:hidden}
.progress .meter i{display:block;height:100%;width:calc(var(--p,0)*1%);background:var(--cyan);border-radius:inherit}
.state-blocked .progress .meter i{background:var(--red)}.state-stale .progress .meter i{background:var(--amber)}
.front small{margin:0;color:var(--muted);font-size:.8125rem}
.panel .front-grid{grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px}
.panel .front{display:grid;gap:4px;padding:12px 14px;border:1px solid var(--line);border-radius:8px;background:var(--panel-raised)}
.panel .front h3{font-size:1rem;font-weight:650}.panel .front p{margin:0;font-size:.9375rem;color:var(--body-soft)}.panel .front a{color:var(--cyan)}
.bus ol{margin:0;padding:0;list-style:none;border:1px solid var(--line);border-radius:8px;overflow:hidden}
.bus li{padding:8px 12px;border-top:1px solid var(--line);font-size:.875rem;color:var(--body-soft)}.bus li:first-child{border-top:0}
.state-current{border-color:var(--green)}.state-current.edge{background:var(--green)}
.state-stale{border-color:var(--amber)}.state-stale.edge{background:var(--amber)}
.state-unknown{border-color:var(--muted)}.state-unknown.edge{background:var(--muted)}
.state-blocked{border-color:var(--red)}.state-blocked.edge{background:var(--red)}
.front-list .front.state-current,.front-list .front.state-stale,.front-list .front.state-blocked,.front-list .front.state-unknown{border-color:var(--line)}
.empty{color:var(--muted);margin:0}
:focus-visible{outline:3px solid var(--cyan);outline-offset:3px}
@keyframes pulse{50%{opacity:.35}}
@media(min-width:760px){
.shell{padding:28px 24px 72px}
.top{grid-template-columns:1fr auto;align-items:end}.top h1{grid-column:1}.lede{grid-column:1}.safety{grid-column:2;grid-row:1/3;margin:0;justify-content:flex-end}
.filters{position:sticky;top:0;z-index:5}
.front-list .front{grid-template-columns:150px minmax(160px,1fr) 190px 72px;gap:14px}
.front-list .front .progress{grid-column:auto}.front-list .front .open{justify-self:end}
.human-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media(pointer:coarse){.filter-group label,.filter-human,.decision a,.front .open{min-height:44px}}
@media(prefers-reduced-motion:reduce){.central .dot{animation:none}.filter-group label,.filter-human,.decision a,.front .open{transition:none}}
CSS
. $dynamic;
    }
}
