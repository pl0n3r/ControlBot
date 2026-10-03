<?php
declare(strict_types=1);

namespace ControlBot\Scheduler;

require_once __DIR__ . '/SchedulerCore.php';
require_once __DIR__ . '/UiTheme.php';

use ControlBot\Ui\UiTheme;
use InvalidArgumentException;

final class WorkUi
{
    public static function render(array $view): string
    {
        try {
            [$state, $message, $rows] = self::normalize($view);
        } catch (InvalidArgumentException) {
            return self::shell('<section class="surface-state state-error" data-state="error" role="status"><h1>Trabajo</h1><p>ERROR · Cola incoherente o inválida.</p></section>');
        }

        if ($state !== 'ready') {
            $label = match ($state) {
                'loading' => 'LOADING · Cargando trabajo.',
                'empty' => 'EMPTY · Sin trabajo observado.',
                'error' => 'ERROR · No fue posible cargar trabajo.',
            };
            return self::shell('<section class="surface-state state-' . $state . '" data-state="' . $state . '" role="status"><h1>Trabajo</h1><p>'
                . self::e($label) . '</p>' . ($message === null ? '' : '<p class="meta">' . self::e($message) . '</p>') . '</section>');
        }

        usort($rows, self::order(...));
        $cards = '';
        foreach ($rows as $row) $cards .= self::card($row);

        return self::shell('<header><p class="eyebrow">CONTROLBOT / TRABAJO</p><h1>Trabajo</h1>'
            . '<p class="meta">Readiness canónico del Scheduler · solo lectura</p></header>'
            . '<section class="work-grid" aria-label="Trabajo">' . $cards . '</section>');
    }

    private static function normalize(array $view): array
    {
        self::fields($view, ['state','rows','message'], 'WorkView');
        $state = self::enum($view['state'], ['loading','empty','error','ready'], 'state');
        $message = self::text($view['message'], 240, true);
        if (!is_array($view['rows']) || !array_is_list($view['rows'])) throw new InvalidArgumentException('rows invalid.');
        if ($state !== 'ready') {
            if ($view['rows'] !== []) throw new InvalidArgumentException('Non-ready view must not expose work.');
            return [$state, $message, []];
        }

        $out = [];
        $seen = [];
        if ($view['rows'] === []) return ['empty', $message, []];

        foreach ($view['rows'] as $row) {
            self::fields($row, ['work_item','context'], 'WorkRow');
            if (!is_array($row['work_item']) || !is_array($row['context'])) throw new InvalidArgumentException('WorkRow invalid.');
            $work = SchedulerCore::workItem($row['work_item']);
            if (isset($seen[$work['work_item_id']])) throw new InvalidArgumentException('WorkItem duplicated.');
            $seen[$work['work_item_id']] = true;
            $ready = SchedulerCore::readiness($row['work_item'], $row['context']);
            if ($ready['work_item_id'] !== $work['work_item_id'] || $ready['generation'] !== $work['generation']) {
                throw new InvalidArgumentException('Readiness identity mismatch.');
            }
            $out[] = ['work'=>$work, 'readiness'=>$ready];
        }
        return [$state, $message, $out];
    }

    private static function card(array $row): string
    {
        $work = $row['work'];
        $ready = $row['readiness'];
        $state = $ready['ready'] ? 'ready' : 'blocked';
        $reasons = $ready['reasons'] === [] ? '<li>Sin bloqueos.</li>' : '';
        foreach ($ready['reasons'] as $reason) $reasons .= '<li>' . self::e($reason) . '</li>';

        $deps = $work['dependency_ids'] === [] ? 'ninguna' : implode(' · ', array_map(self::e(...), $work['dependency_ids']));
        $caps = $work['required_capabilities'] === [] ? 'ninguna' : implode(' · ', array_map(self::e(...), $work['required_capabilities']));
        $open = $ready['open_dependencies'] === [] ? 'ninguna' : implode(' · ', array_map(self::e(...), $ready['open_dependencies']));
        $unknown = $ready['unknown_dependencies'] === [] ? 'ninguna' : implode(' · ', array_map(self::e(...), $ready['unknown_dependencies']));

        return '<article class="panel work work-' . $state . '" data-work="' . self::e($work['work_item_id']) . '" data-ready="' . ($ready['ready'] ? 'true' : 'false') . '">'
            . '<header><p class="eyebrow">' . self::e(strtoupper($work['priority'])) . ' / ' . self::e(strtoupper($work['type'])) . '</p>'
            . '<h2>' . self::e($work['work_item_id']) . '</h2><p class="readiness">' . ($ready['ready'] ? 'READY' : 'BLOCKED') . '</p></header>'
            . '<dl>'
            . self::metric('Proyecto', $work['project_id'])
            . self::metric('Fuente', $work['source_ref'])
            . self::metric('Estado', $work['state'])
            . self::metric('Generation / attempt', $work['generation'] . ' / ' . $work['attempt'])
            . self::metric('Dependencias', $deps)
            . self::metric('Capabilities', $caps)
            . self::metric('Reserva', $work['reservation_id'] ?? 'NONE')
            . self::metric('Sesión asignada', $work['assigned_session_id'] ?? 'NONE')
            . self::metric('Owner reserva', $ready['reservation_owner'] ?? 'NONE')
            . self::metric('Cuenta evaluada', $ready['account_id'])
            . self::metric('Deps abiertas', $open)
            . self::metric('Deps UNKNOWN', $unknown)
            . '</dl><section class="reasons"><h3>Razones</h3><ul>' . $reasons . '</ul></section></article>';
    }

    private static function order(array $a, array $b): int
    {
        $rank = static function (array $row): array {
            $work = $row['work'];
            $priority = ['critical'=>1,'high'=>2,'medium'=>3,'low'=>4][$work['priority']] ?? 9;
            return [$work['type'] === 'incident' ? 0 : $priority, $work['work_item_id']];
        };
        return $rank($a) <=> $rank($b);
    }

    private static function metric(string $label, string|int $value): string
    {
        return '<div><dt>' . self::e($label) . '</dt><dd>' . self::e((string)$value) . '</dd></div>';
    }

    private static function fields(mixed $row, array $expected, string $label): void
    {
        if (!is_array($row) || array_is_list($row)) throw new InvalidArgumentException($label . ' invalid.');
        $actual = array_keys($row); sort($actual); sort($expected);
        if ($actual !== $expected) throw new InvalidArgumentException($label . ' fields invalid.');
    }

    private static function enum(mixed $value, array $allowed, string $label): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) throw new InvalidArgumentException($label . ' invalid.');
        return $value;
    }

    private static function text(mixed $value, int $max, bool $nullable = false): ?string
    {
        if ($nullable && $value === null) return null;
        if (!is_string($value) || strlen($value) > $max || preg_match('/[\x00-\x1f\x7f]/', $value) === 1
            || preg_match('/(?:-----BEGIN [^-]*PRIVATE KEY-----|\\b(?:bearer\\s+[A-Za-z0-9._~+\\/-]{8,}|(?:password|passwd|token|secret|cookie|authorization|private[_ -]?key|api[_ -]?key|dsn|session[_ -]?token)\\s*[:=]\\s*\\S+|(?:ghp_|gho_|github_pat_)[A-Za-z0-9_]{20,}|(?:sk|rk|pk)-[A-Za-z0-9_-]{12,}))/i', $value) === 1) {
            throw new InvalidArgumentException('message invalid.');
        }
        return $value;
    }

    private static function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

    private static function shell(string $body): string
    {
        return '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
            . '<title>ControlBot · Trabajo</title><style>' . self::styles() . '</style></head><body><main class="shell">' . $body . '</main></body></html>';
    }

    private static function styles(): string
    {
        return UiTheme::tokensCss() . <<<'CSS'
*{box-sizing:border-box}html{background:var(--bg)}body{margin:0;color:var(--text);font-family:Inter,system-ui,sans-serif;background:var(--bg)}
.shell{width:min(100%,1180px);margin:auto;padding:24px 16px 48px}.eyebrow{color:var(--cyan);letter-spacing:.07em;font:700 .72rem/1.3 ui-monospace,monospace}
h1,h2,h3{overflow-wrap:anywhere}.meta,dt{color:var(--muted);overflow-wrap:anywhere}.work-grid{display:grid;grid-template-columns:1fr;gap:16px;margin-top:18px}
.panel,.surface-state{min-width:0;padding:18px;border:1px solid var(--line);border-radius:10px;background:var(--panel)}.work-blocked{border-color:var(--amber)}.work-ready{border-color:var(--green)}
.readiness{font:700 .8rem/1.2 "JetBrains Mono",ui-monospace,monospace}.work-ready .readiness{color:var(--green)}.work-blocked .readiness{color:var(--amber)}
dl{display:grid;gap:8px;margin:14px 0}dl div{display:grid;grid-template-columns:minmax(110px,.7fr) minmax(0,1.3fr);gap:10px;border-top:1px solid var(--line);padding-top:8px}
dt,dd{margin:0;overflow-wrap:anywhere}.reasons ul{padding-left:20px}.reasons li{margin:5px 0;overflow-wrap:anywhere}
a,button,[tabindex]:not([tabindex="-1"]){outline-offset:3px}a:focus-visible,button:focus-visible,[tabindex]:focus-visible{outline:3px solid var(--amber)}
@media(min-width:760px){.shell{padding:36px 28px 60px}.work-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation:none!important;transition:none!important;scroll-behavior:auto!important}}
CSS;
    }
}
