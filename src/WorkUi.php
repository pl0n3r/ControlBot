<?php
declare(strict_types=1);

namespace ControlBot\Scheduler;

require_once __DIR__ . '/SchedulerCore.php';
require_once __DIR__ . '/UiTheme.php';

use ControlBot\Ui\UiTheme;
use InvalidArgumentException;

final class WorkUi
{
    public static function render(array $input): string
    {
        try {
            $view = self::model($input);
        } catch (InvalidArgumentException) {
            return self::page(self::notice('error', 'ERROR · Cola incoherente o inválida.', null));
        }

        if ($view['state'] !== 'ready') {
            $copy = [
                'loading' => 'LOADING · Cargando trabajo.',
                'empty' => 'EMPTY · Sin trabajo observado.',
                'error' => 'ERROR · No fue posible cargar trabajo.',
            ][$view['state']];
            return self::page(self::notice($view['state'], $copy, $view['message']));
        }

        usort($view['rows'], static function (array $left, array $right): int {
            $score = static function (array $row): array {
                $work = $row['work'];
                $priority = ['critical'=>1, 'high'=>2, 'medium'=>3, 'low'=>4][$work['priority']] ?? 9;
                return [$work['type'] === 'incident' ? 0 : $priority, $work['work_item_id']];
            };
            return $score($left) <=> $score($right);
        });

        $items = '';
        foreach ($view['rows'] as $row) $items .= self::workCard($row);
        return self::page(
            '<header class="work-header"><p class="kicker">CONTROLBOT / TRABAJO</p><h1>Trabajo</h1>'
            . '<p class="secondary">Readiness canónico del Scheduler · solo lectura</p></header>'
            . '<section class="work-list" aria-label="Trabajo">' . $items . '</section>'
        );
    }

    private static function model(array $input): array
    {
        $keys = array_keys($input);
        sort($keys);
        if (array_is_list($input) || $keys !== ['message','rows','state']) throw new InvalidArgumentException('WorkView invalid.');

        $state = $input['state'];
        if (!is_string($state) || !in_array($state, ['loading','empty','error','ready'], true)) {
            throw new InvalidArgumentException('state invalid.');
        }
        $message = self::safeMessage($input['message']);
        if (!is_array($input['rows']) || !array_is_list($input['rows'])) throw new InvalidArgumentException('rows invalid.');
        if ($state !== 'ready') {
            if ($input['rows'] !== []) throw new InvalidArgumentException('Non-ready view must not expose work.');
            return ['state'=>$state, 'message'=>$message, 'rows'=>[]];
        }
        if ($input['rows'] === []) return ['state'=>'empty', 'message'=>$message, 'rows'=>[]];

        $rows = [];
        $seen = [];
        foreach ($input['rows'] as $raw) {
            if (!is_array($raw) || array_is_list($raw)) throw new InvalidArgumentException('WorkRow invalid.');
            $rowKeys = array_keys($raw);
            sort($rowKeys);
            if ($rowKeys !== ['context','work_item'] || !is_array($raw['work_item']) || !is_array($raw['context'])) {
                throw new InvalidArgumentException('WorkRow fields invalid.');
            }

            $work = SchedulerCore::workItem($raw['work_item']);
            if (isset($seen[$work['work_item_id']])) throw new InvalidArgumentException('WorkItem duplicated.');
            $seen[$work['work_item_id']] = true;

            $readiness = SchedulerCore::readiness($raw['work_item'], $raw['context']);
            if ($readiness['work_item_id'] !== $work['work_item_id'] || $readiness['generation'] !== $work['generation']) {
                throw new InvalidArgumentException('Readiness identity mismatch.');
            }
            $rows[] = ['work'=>$work, 'readiness'=>$readiness];
        }
        return ['state'=>'ready', 'message'=>$message, 'rows'=>$rows];
    }

    private static function safeMessage(mixed $value): ?string
    {
        if ($value === null) return null;
        if (!is_string($value) || strlen($value) > 240 || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new InvalidArgumentException('message invalid.');
        }
        $secret = preg_match('/-----BEGIN [^-]*PRIVATE KEY-----/i', $value) === 1
            || preg_match('/\b(?:ghp_|gho_|github_pat_)[A-Za-z0-9_]{20,}/i', $value) === 1
            || preg_match('/\bbearer\s+[A-Za-z0-9._~+\/-]{8,}/i', $value) === 1
            || preg_match('/\b(?:password|passwd|token|secret|cookie|authorization|private[_ -]?key|api[_ -]?key|dsn|session[_ -]?token)\s*[:=]\s*\S+/i', $value) === 1
            || preg_match('/\b(?:sk|rk|pk)-[A-Za-z0-9_-]{12,}/i', $value) === 1;
        if ($secret) throw new InvalidArgumentException('message invalid.');
        return $value;
    }

    private static function workCard(array $row): string
    {
        $work = $row['work'];
        $ready = $row['readiness'];
        $isReady = $ready['ready'];
        $reasons = $ready['reasons'] === [] ? '<li>Sin bloqueos.</li>' : '';
        foreach ($ready['reasons'] as $reason) $reasons .= '<li>' . self::h($reason) . '</li>';

        $facts = [
            'Proyecto' => $work['project_id'],
            'Fuente' => $work['source_ref'],
            'Estado' => $work['state'],
            'Generation / attempt' => $work['generation'] . ' / ' . $work['attempt'],
            'Dependencias' => self::join($work['dependency_ids']),
            'Capabilities' => self::join($work['required_capabilities']),
            'Reserva' => $work['reservation_id'] ?? 'NONE',
            'Sesión asignada' => $work['assigned_session_id'] ?? 'NONE',
            'Owner reserva' => $ready['reservation_owner'] ?? 'NONE',
            'Cuenta evaluada' => $ready['account_id'],
            'Deps abiertas' => self::join($ready['open_dependencies']),
            'Deps UNKNOWN' => self::join($ready['unknown_dependencies']),
        ];
        $details = '';
        foreach ($facts as $label => $value) {
            $details .= '<div><dt>' . self::h($label) . '</dt><dd>' . self::h((string)$value) . '</dd></div>';
        }

        return '<article class="work-card ' . ($isReady ? 'is-ready' : 'is-blocked') . '" data-work="' . self::h($work['work_item_id'])
            . '" data-ready="' . ($isReady ? 'true' : 'false') . '"><header><p class="kicker">'
            . self::h(strtoupper($work['priority']) . ' / ' . strtoupper($work['type'])) . '</p><h2>'
            . self::h($work['work_item_id']) . '</h2><p class="readiness">' . ($isReady ? 'READY' : 'BLOCKED')
            . '</p></header><dl>' . $details . '</dl><section class="reasons"><h3>Razones</h3><ul>'
            . $reasons . '</ul></section></article>';
    }

    private static function join(array $values): string
    {
        return $values === [] ? 'ninguna' : implode(' · ', $values);
    }

    private static function notice(string $state, string $copy, ?string $message): string
    {
        return '<section class="work-notice" data-state="' . self::h($state) . '" role="status"><h1>Trabajo</h1><p>'
            . self::h($copy) . '</p>' . ($message === null ? '' : '<p class="secondary">' . self::h($message) . '</p>') . '</section>';
    }

    private static function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function page(string $content): string
    {
        return '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
            . '<title>ControlBot · Trabajo</title><style>' . UiTheme::tokensCss() . self::css()
            . '</style></head><body><main class="work-page">' . $content . '</main></body></html>';
    }

    private static function css(): string
    {
        return <<<'CSS'
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,system-ui,sans-serif}.work-page{width:min(100%,1180px);margin:auto;padding:24px 16px 48px}.work-header{margin-bottom:18px}.kicker{color:var(--cyan);font:700 .72rem/1.3 ui-monospace,monospace;letter-spacing:.07em}.secondary,dt{color:var(--muted)}h1,h2,h3,dt,dd{overflow-wrap:anywhere}.work-list{display:grid;grid-template-columns:1fr;gap:16px}.work-card,.work-notice{padding:18px;border:1px solid var(--line);border-radius:10px;background:var(--panel);min-width:0}.work-card.is-ready{border-color:var(--green)}.work-card.is-blocked{border-color:var(--amber)}.readiness{font:700 .8rem/1.2 "JetBrains Mono",ui-monospace,monospace}.is-ready .readiness{color:var(--green)}.is-blocked .readiness{color:var(--amber)}dl{display:grid;gap:8px;margin:14px 0}dl div{display:grid;grid-template-columns:minmax(110px,.7fr) minmax(0,1.3fr);gap:10px;padding-top:8px;border-top:1px solid var(--line)}dt,dd{margin:0}.reasons ul{padding-left:20px}.reasons li{margin:5px 0;overflow-wrap:anywhere}a,button,[tabindex]:not([tabindex="-1"]){outline-offset:3px}a:focus-visible,button:focus-visible,[tabindex]:focus-visible{outline:3px solid var(--amber)}@media(min-width:760px){.work-page{padding:36px 28px 60px}.work-list{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation:none!important;transition:none!important;scroll-behavior:auto!important}}
CSS;
    }
}
