<?php
declare(strict_types=1);

namespace ControlBot\Business;

require_once __DIR__ . '/UiTheme.php';

use ControlBot\Ui\UiTheme;
use InvalidArgumentException;

final class FactoryOrchestratorLiveUi
{
    private const CENTRAL = ['ACTIVE','READY','BLOCKED','IDLE','STALE','UNKNOWN'];
    private const FRONT = ['available','reserved','in_review','blocked','merged','unknown','STALE'];
    private const FRESH = ['current','stale','unknown'];

    public static function render(array $view): string
    {
        self::fields(
            $view,
            ['version','observed_at','source_snapshot','read_only','central','fronts','owner_decisions','fingerprint'],
            'view',
        );
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
        $fronts = '';
        $edges = '';
        $events = '';
        foreach ($view['fronts'] as $front) {
            [$card, $edge, $event] = self::front($front);
            $fronts .= $card;
            $edges .= $edge;
            $events .= $event;
        }
        if ($fronts === '') {
            $fronts = '<p class="empty" role="status">Sin frentes activos.</p>';
        }

        $decisions = '';
        foreach ($view['owner_decisions'] as $decision) {
            $decisions .= self::decision($decision);
        }
        if ($decisions === '') {
            $decisions = '<p class="empty" role="status">Sin decisiones humanas pendientes.</p>';
        }

        $motion = self::motionSeconds($view['central']);
        return '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
            . '<title>ControlBot · Orquestador en vivo</title><style>' . self::styles() . '</style></head>'
            . '<body><main class="shell" aria-labelledby="orchestrator-title" style="--flow:' . $motion . 's">'
            . '<header class="top"><p class="eyebrow">CONTROLBOT / ORQUESTADOR</p>'
            . '<h1 id="orchestrator-title">Orquestador en vivo</h1>'
            . '<p>Solo lectura · la fuente de verdad permanece en GitHub.</p>'
            . '<div class="safety"><span data-read-only="true">solo lectura</span>'
            . '<span data-kill-switch="UNKNOWN">Kill switch: UNKNOWN</span></div></header>'
            . '<nav class="filters" aria-label="Filtros de vista">'
            . '<span data-filter="repository">Repositorio</span><span data-filter="type">Tipo / estado</span>'
            . '<span data-filter="human">Humano</span></nav>'
            . '<section class="stage" aria-label="Topología del orquestador">' . $central
            . '<div class="edges" aria-hidden="true">' . $edges . '</div>'
            . '<div class="front-grid">' . $fronts . '</div></section>'
            . '<section class="panel bus" data-section="event_bus"><p class="eyebrow">BUS VISUAL</p>'
            . '<h2>Eventos observados</h2><ol>' . $events . '</ol></section>'
            . '<section class="panel human" data-section="human"><p class="eyebrow">HUMANO</p>'
            . '<h2>Decisiones del dueño</h2><div class="human-grid">' . $decisions . '</div></section>'
            . '</main></body></html>';
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
        return '<article class="central ' . $class . '" data-node="central">'
            . '<p class="eyebrow">NODO CENTRAL</p><strong>' . self::e($state) . '</strong>'
            . '<dl><div><dt>disponible</dt><dd>' . $counts['available'] . '</dd></div>'
            . '<div><dt>reservado</dt><dd>' . $counts['reserved'] . '</dd></div>'
            . '<div><dt>bloqueado</dt><dd>' . $counts['blocked'] . '</dd></div></dl>'
            . '<small>freshness=' . self::e($fresh) . '</small></article>';
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
        $card = '<article class="front ' . $class . '" data-front="' . self::e($id)
            . '" data-repository="' . self::e($repo) . '" data-type="' . self::e($status) . '">'
            . '<p class="eyebrow">' . self::e($repo) . '</p><h3>' . self::e($status) . '</h3>'
            . '<p>Progreso: <strong>' . self::e($progressText) . '</strong></p>'
            . '<small>freshness=' . self::e($fresh) . ' · antigüedad=' . $age . 's</small>'
            . '<a href="' . self::e($issue) . '" rel="noreferrer noopener">Abrir evidencia</a></article>';
        $edge = '<i class="edge ' . $class . '" data-from="central" data-to="' . self::e($id) . '"></i>';
        $event = '<li><b>' . self::e($repo) . '</b> · ' . self::e($status)
            . ' · ' . $age . 's · ' . self::e($fresh) . '</li>';
        return [$card, $edge, $event];
    }

    private static function decision(mixed $decision): string
    {
        self::fields(
            $decision,
            ['id','issue_ref','source_ref','observed_at','freshness','age_seconds'],
            'decision',
        );
        $id = self::text($decision['id'], 'decision.id');
        $fresh = self::one($decision['freshness'], self::FRESH, 'decision.freshness');
        $age = self::age($decision['age_seconds']);
        $href = self::issueHref($decision['issue_ref']);
        return '<article class="decision ' . self::stateClass('pending', $fresh) . '" data-decision="' . self::e($id) . '">'
            . '<strong>Decisión pendiente</strong><span>Antigüedad: ' . $age . 's</span>'
            . '<span>freshness=' . self::e($fresh) . '</span>'
            . '<a href="' . self::e($href) . '" rel="noreferrer noopener">Abrir Issue</a></article>';
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

    private static function styles(): string
    {
        return UiTheme::tokensCss() . <<<'CSS'
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:system-ui,sans-serif}
.shell{max-width:1180px;margin:auto;padding:20px}.top,.panel,.central,.front,.decision{background:var(--panel);border:1px solid var(--line);border-radius:16px}
.top,.panel{padding:20px;margin-bottom:16px}.eyebrow{color:var(--cyan);font-size:.75rem;letter-spacing:.12em}.safety,.filters{display:flex;gap:10px;flex-wrap:wrap}
.safety span,.filters span{border:1px solid var(--line-strong);border-radius:999px;padding:7px 10px}.stage{display:grid;gap:14px}.central{padding:18px;animation:pulse var(--flow) ease-in-out infinite}
.central dl{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}.central dl div{background:var(--panel-raised);padding:8px;border-radius:10px}.central dt,.central dd{margin:0}
.front-grid,.human-grid{display:grid;grid-template-columns:1fr;gap:12px}.front,.decision{padding:14px}.front a,.decision a{color:var(--cyan)}.front small,.decision span{display:block;color:var(--muted);margin:6px 0}
.edges{display:flex;gap:6px}.edge{display:block;height:3px;flex:1;border-radius:3px;background:var(--line);animation:flow var(--flow) linear infinite}
.bus ol{padding-left:22px}.bus li{margin:8px 0}.state-current{border-color:var(--green)}.state-current.edge{background:var(--green)}
.state-stale{border-color:var(--amber)}.state-stale.edge{background:var(--amber)}.state-unknown{border-color:var(--muted)}.state-unknown.edge{background:var(--muted)}
.state-blocked{border-color:var(--red)}.state-blocked.edge{background:var(--red)}.empty{color:var(--muted)}
:focus-visible{outline:3px solid var(--cyan);outline-offset:3px}@keyframes pulse{50%{transform:scale(1.01)}}@keyframes flow{50%{opacity:.35}}
@media(min-width:760px){.stage{grid-template-columns:220px 1fr}.central{grid-row:1/3}.front-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.human-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(prefers-reduced-motion:reduce){.central,.edge{animation:none}}
CSS;
    }
}
