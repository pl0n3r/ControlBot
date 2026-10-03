<?php
declare(strict_types=1);

namespace ControlBot\Runtime;

require_once __DIR__ . '/AgentRuntime.php';
require_once __DIR__ . '/UiTheme.php';

use ControlBot\Ui\UiTheme;
use InvalidArgumentException;

final class AgentUi
{
    public static function render(array $view): string
    {
        try {
            [$state, $message, $agents, $sessions, $assignments, $handoffs] = self::normalize($view);
        } catch (InvalidArgumentException) {
            return self::shell('<section class="surface-state state-error" data-state="error" role="status"><h1>Agentes</h1><p>ERROR · Runtime incoherente o inválido.</p></section>');
        }

        if ($state !== 'ready') {
            $label = match ($state) {
                'loading' => 'LOADING · Cargando runtime.',
                'empty' => 'EMPTY · Sin agentes observados.',
                'error' => 'ERROR · No fue posible cargar runtime.',
            };
            return self::shell('<section class="surface-state state-' . $state . '" data-state="' . $state . '" role="status"><h1>Agentes</h1><p>'
                . self::e($label) . '</p>' . ($message === null ? '' : '<p class="meta">' . self::e($message) . '</p>') . '</section>');
        }

        $cards = '';
        foreach ($agents as $agent) {
            $agentSessions = array_values(array_filter($sessions, static fn(array $session): bool => $session['agent_id'] === $agent['agent_id']));
            $cards .= self::agentCard($agent, $agentSessions, $assignments);
        }
        if ($cards === '') $cards = '<p class="surface-state state-empty">EMPTY · Sin agentes observados.</p>';

        $handoffRows = '';
        foreach ($handoffs as $handoff) {
            $handoffRows .= '<li><strong>' . self::e($handoff['handoff_id']) . '</strong>'
                . '<span>' . self::e($handoff['objective']) . '</span>'
                . '<span>' . self::e($handoff['issue_ref']) . ($handoff['pr_ref'] === null ? '' : ' · ' . self::e($handoff['pr_ref'])) . '</span>'
                . '<code>' . self::e($handoff['sha']) . '</code>'
                . ($handoff['blocker'] === null ? '' : '<span class="warning">Bloqueo: ' . self::e($handoff['blocker']) . '</span>')
                . '<span>Siguiente: ' . self::e($handoff['next_action']) . '</span></li>';
        }
        if ($handoffRows === '') $handoffRows = '<li>Sin handoffs.</li>';

        return self::shell('<header><p class="eyebrow">CONTROLBOT / AGENTES</p><h1>Agentes</h1>'
            . '<p class="meta">Runtime canónico · solo lectura</p></header>'
            . '<section class="agent-grid" aria-label="Agentes">' . $cards . '</section>'
            . '<section class="panel handoffs" aria-label="Handoffs"><h2>Handoffs</h2><ul>' . $handoffRows . '</ul></section>');
    }

    private static function normalize(array $view): array
    {
        self::fields($view, ['state','agents','sessions','assignments','handoffs','message'], 'AgentView');
        $state = self::enum($view['state'], ['loading','empty','error','ready'], 'state');
        $message = self::text($view['message'], 240, true);
        foreach (['agents','sessions','assignments','handoffs'] as $key) {
            if (!is_array($view[$key]) || !array_is_list($view[$key])) throw new InvalidArgumentException($key . ' invalid.');
        }
        if ($state !== 'ready') {
            foreach (['agents','sessions','assignments','handoffs'] as $key) {
                if ($view[$key] !== []) throw new InvalidArgumentException('Non-ready view must not expose runtime.');
            }
            return [$state, $message, [], [], [], []];
        }

        $agents = self::index($view['agents'], AgentRuntime::agent(...), 'agent_id');
        $sessions = self::index($view['sessions'], AgentRuntime::session(...), 'session_id');
        $assignments = self::index($view['assignments'], AgentRuntime::assignment(...), 'assignment_id');
        $handoffs = self::index($view['handoffs'], AgentRuntime::handoff(...), 'handoff_id');

        foreach ($sessions as $session) {
            if (!isset($agents[$session['agent_id']])) throw new InvalidArgumentException('Session agent missing.');
            $assignmentId = $session['assignment_id'];
            if ($assignmentId !== null && (!isset($assignments[$assignmentId]) || $assignments[$assignmentId]['session_id'] !== $session['session_id'])) {
                throw new InvalidArgumentException('Session assignment mismatch.');
            }
        }
        foreach ($assignments as $assignment) {
            $session = $sessions[$assignment['session_id']] ?? null;
            if ($session === null || $session['assignment_id'] !== $assignment['assignment_id']) throw new InvalidArgumentException('Assignment session mismatch.');
        }
        foreach ($handoffs as $handoff) {
            $assignment = $assignments[$handoff['assignment_id']] ?? null;
            if ($assignment === null || !isset($sessions[$handoff['from_session_id']])
                || $assignment['session_id'] !== $handoff['from_session_id']
                || ($handoff['to_session_id'] !== null && !isset($sessions[$handoff['to_session_id']]))) {
                throw new InvalidArgumentException('Handoff relation invalid.');
            }
        }

        ksort($agents); ksort($sessions); ksort($assignments); ksort($handoffs);
        return [$state, $message, array_values($agents), array_values($sessions), $assignments, array_values($handoffs)];
    }

    private static function agentCard(array $agent, array $sessions, array $assignments): string
    {
        $caps = implode(' · ', array_map(self::e(...), $agent['capabilities']));
        $rows = '';
        foreach ($sessions as $session) {
            $assignment = $session['assignment_id'] === null ? null : $assignments[$session['assignment_id']];
            $work = $assignment === null ? '<span class="unknown">Assignment UNKNOWN</span>' :
                '<span>' . self::e($assignment['assignment_id']) . ' · ' . self::e($assignment['project_id']) . ' · ' . self::e($assignment['source_ref']) . ' · ' . self::e($assignment['status']) . '</span>';
            $rows .= '<li><strong>' . self::e($session['session_id']) . '</strong>'
                . '<span>' . self::e($session['status']) . ' · account ' . self::e($session['account_id']) . ' · profile ' . self::e($session['profile_alias']) . '</span>'
                . '<span>heartbeat ' . ($session['last_heartbeat_at'] === null ? 'UNKNOWN' : (string)$session['last_heartbeat_at']) . '</span>'
                . $work . '</li>';
        }
        if ($rows === '') $rows = '<li>Sin sesiones.</li>';

        return '<article class="panel agent" data-agent="' . self::e($agent['agent_id']) . '"><p class="eyebrow">' . self::e($agent['role']) . '</p>'
            . '<h2>' . self::e($agent['agent_id']) . '</h2><p class="meta">Capabilities: ' . $caps . '</p><ul>' . $rows . '</ul></article>';
    }

    private static function index(array $rows, callable $normalizer, string $key): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) throw new InvalidArgumentException('Runtime row invalid.');
            $normalized = $normalizer($row);
            $id = $normalized[$key];
            if (isset($out[$id])) throw new InvalidArgumentException('Runtime identity duplicated.');
            $out[$id] = $normalized;
        }
        return $out;
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
        if (!is_string($value) || strlen($value) > $max || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) throw new InvalidArgumentException('message invalid.');
        return $value;
    }

    private static function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

    private static function shell(string $body): string
    {
        return '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
            . '<title>ControlBot · Agentes</title><style>' . self::styles() . '</style></head><body><main class="shell">' . $body . '</main></body></html>';
    }

    private static function styles(): string
    {
        return UiTheme::tokensCss() . <<<'CSS'
*{box-sizing:border-box}html{background:var(--bg)}body{margin:0;color:var(--text);font-family:Inter,system-ui,sans-serif;background:var(--bg)}
.shell{width:min(100%,1180px);margin:auto;padding:24px 16px 48px}.eyebrow{color:var(--cyan);letter-spacing:.07em;font:700 .72rem/1.3 ui-monospace,monospace}
h1,h2{overflow-wrap:anywhere}.meta{color:var(--muted);overflow-wrap:anywhere}.agent-grid{display:grid;grid-template-columns:1fr;gap:16px;margin-top:18px}
.panel,.surface-state{min-width:0;padding:18px;border:1px solid var(--line);border-radius:10px;background:var(--panel)}.panel ul{display:grid;gap:12px;margin:12px 0 0;padding:0;list-style:none}
.panel li{display:grid;gap:4px;border-top:1px solid var(--line);padding-top:10px;overflow-wrap:anywhere}.handoffs{margin-top:16px}.unknown,.warning{color:var(--amber)}
code{font-family:"JetBrains Mono",ui-monospace,monospace;overflow-wrap:anywhere}a,button,[tabindex]:not([tabindex="-1"]){outline-offset:3px}a:focus-visible,button:focus-visible,[tabindex]:focus-visible{outline:3px solid var(--amber)}
@media(min-width:760px){.shell{padding:36px 28px 60px}.agent-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation:none!important;transition:none!important;scroll-behavior:auto!important}}
CSS;
    }
}
