<?php
declare(strict_types=1);

namespace ControlBot\GitHub;

require_once __DIR__ . '/UiTheme.php';

use ControlBot\Ui\UiTheme;
use InvalidArgumentException;

final class GitHubProjectUi
{
    private const EVIDENCE = ['current', 'stale', 'unknown'];
    private const REVIEW_STATES = ['approved', 'changes_requested', 'commented', 'dismissed', 'pending', 'unknown'];
    private const MERGEABILITY = ['mergeable', 'conflicting', 'unknown'];

    public static function render(array $view, string $surfaceState = 'ready'): string
    {
        if (!in_array($surfaceState, ['ready', 'loading', 'error', 'permission_denied'], true)) {
            $surfaceState = 'error';
        }
        try {
            [$project, $repos] = self::normalize($view);
        } catch (InvalidArgumentException) {
            $project = ['project_id' => 'unknown', 'state' => 'unknown', 'freshness' => 'unknown', 'observed_at' => null, 'age_seconds' => null];
            $repos = [];
        }

        usort($repos, static fn(array $a, array $b): int => ($a['state'] === 'unknown' ? 0 : 1) <=> ($b['state'] === 'unknown' ? 0 : 1));
        $attention = self::attention($project, $repos);
        $cards = self::surface($surfaceState, $repos);

        return '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
            . '<title>ControlBot · GitHub del proyecto</title><style>' . self::styles() . '</style></head>'
            . '<body><main class="shell" aria-labelledby="github-project-title">'
            . '<header><p class="eyebrow">CONTROLBOT / GITHUB / PROJECT</p><h1 id="github-project-title">'
            . self::e($project['project_id']) . '</h1>'
            . '<p class="meta">Estado <strong>' . self::e(strtoupper($project['state'])) . '</strong> · Freshness <strong>'
            . self::e(strtoupper($project['freshness'])) . '</strong> · observado ' . self::e(self::time($project['observed_at']))
            . ' · antigüedad ' . self::e(self::age($project['age_seconds'])) . '</p></header>'
            . $attention
            . '<section class="repo-grid" aria-label="Repositorios GitHub">' . $cards . '</section>'
            . '</main></body></html>';
    }

    private static function normalize(array $view): array
    {
        if (($view['version'] ?? null) !== 1
            || !is_string($view['project_id'] ?? null)
            || !in_array($view['state'] ?? null, ['current', 'unknown'], true)
            || !in_array($view['freshness'] ?? null, self::EVIDENCE, true)
            || !is_array($view['repositories'] ?? null)
        ) {
            throw new InvalidArgumentException('Project view invalid.');
        }

        $project = [
            'project_id' => $view['project_id'],
            'state' => $view['state'],
            'freshness' => $view['freshness'],
            'observed_at' => is_int($view['observed_at'] ?? null) ? $view['observed_at'] : null,
            'age_seconds' => is_int($view['age_seconds'] ?? null) ? $view['age_seconds'] : null,
        ];
        $repos = [];
        foreach ($view['repositories'] as $repo) {
            $repos[] = self::normalizeRepo($repo);
        }
        return [$project, $repos];
    }

    private static function normalizeRepo(mixed $repo): array
    {
        if (!is_array($repo)
            || !is_string($repo['repository'] ?? null)
            || !is_string($repo['source_ref'] ?? null)
            || !in_array($repo['state'] ?? null, ['current', 'unknown'], true)
            || !in_array($repo['freshness'] ?? null, self::EVIDENCE, true)
            || !is_string($repo['main_sha'] ?? null)
        ) {
            throw new InvalidArgumentException('Repository view invalid.');
        }
        foreach (['checks', 'pull_requests', 'issues'] as $key) {
            if (!is_array($repo[$key] ?? null) || !is_array($repo[$key]['items'] ?? null) || !is_bool($repo[$key]['truncated'] ?? null)) {
                throw new InvalidArgumentException("Repository {$key} invalid.");
            }
        }
        foreach ($repo['pull_requests']['items'] as $pullRequest) {
            if (
                !is_array($pullRequest)
                || !in_array($pullRequest['review_state'] ?? null, self::REVIEW_STATES, true)
                || !in_array($pullRequest['mergeability'] ?? null, self::MERGEABILITY, true)
            ) {
                throw new InvalidArgumentException('Pull request evidence invalid.');
            }
        }
        return $repo;
    }

    private static function surface(string $state, array $repos): string
    {
        if ($state !== 'ready') {
            $messages = [
                'loading' => 'LOADING · Cargando evidencia GitHub.',
                'error' => 'ERROR · No fue posible proyectar la evidencia GitHub.',
                'permission_denied' => 'SIN PERMISO · La evidencia GitHub no está disponible para esta sesión.',
            ];
            return '<p class="surface-state surface-' . $state . '">' . $messages[$state] . '</p>';
        }
        return $repos === []
            ? '<p class="surface-state surface-empty">EMPTY · Sin repositorios en esta proyección.</p>'
            : implode('', array_map(self::repo(...), $repos));
    }

    private static function attention(array $project, array $repos): string
    {
        $unknown = $project['state'] === 'unknown' || $project['freshness'] !== 'current';
        foreach ($repos as $repo) {
            $unknown = $unknown || $repo['state'] === 'unknown' || $repo['freshness'] !== 'current'
                || $repo['checks']['truncated'] || $repo['pull_requests']['truncated'] || $repo['issues']['truncated'];
            foreach ($repo['pull_requests']['items'] as $pullRequest) {
                $unknown = $unknown
                    || ($pullRequest['review_state'] ?? 'unknown') === 'unknown'
                    || ($pullRequest['mergeability'] ?? 'unknown') === 'unknown';
            }
        }
        return '<section class="attention ' . ($unknown ? 'attention-unknown' : 'attention-current') . '" aria-label="Atención del dueño">'
            . '<strong>' . ($unknown ? 'UNKNOWN / STALE' : 'EVIDENCIA ACTUAL') . '</strong>'
            . '<span>' . ($unknown ? 'Hay evidencia incompleta, antigua o ambigua. No se interpreta como éxito.' : 'Las fuentes proyectadas están actuales y completas.') . '</span>'
            . '</section>';
    }

    private static function repo(array $repo): string
    {
        $state = $repo['state'] === 'unknown' || $repo['freshness'] !== 'current' ? 'unknown' : 'current';
        return '<article class="repo repo-' . $state . '"><header><p class="eyebrow">'
            . self::e($repo['repository']) . '</p><h2>' . self::e($repo['repository']) . '</h2>'
            . '<p class="meta">Fuente ' . self::e($repo['source_ref']) . ' · ' . self::e(strtoupper($repo['freshness']))
            . ' · ' . self::e(self::age($repo['age_seconds'] ?? null)) . '</p></header>'
            . self::section('Main', '<code>' . self::e($repo['main_sha']) . '</code>')
            . self::section('Checks', self::rows($repo['checks'], static fn(array $row): string =>
                self::e((string)($row['name'] ?? 'UNKNOWN')) . ' · ' . self::e((string)($row['status'] ?? 'unknown'))
                . ' · ' . self::e((string)($row['conclusion'] ?? 'pending'))))
            . self::section(
                'Pull requests',
                self::rows(
                    $repo['pull_requests'],
                    static fn(array $row): string =>
                        '#' . self::e((string) ($row['number'] ?? '?'))
                        . ' ' . self::e((string) ($row['title'] ?? 'UNKNOWN'))
                        . ' · ' . self::e((string) ($row['base_ref'] ?? '?'))
                        . ' ← ' . self::e((string) ($row['head_sha'] ?? '?'))
                        . ' · review ' . self::e((string) ($row['review_state'] ?? 'unknown'))
                        . ' · merge ' . self::e((string) ($row['mergeability'] ?? 'unknown'))
                )
            )
            . self::section('Issues', self::rows($repo['issues'], static fn(array $row): string =>
                '#' . self::e((string)($row['number'] ?? '?')) . ' ' . self::e((string)($row['title'] ?? 'UNKNOWN'))))
            . self::section('Release', self::release($repo['latest_release'] ?? null))
            . self::section('Último workflow', self::workflow($repo['latest_workflow'] ?? null))
            . '</article>';
    }

    private static function rows(array $bucket, callable $render): string
    {
        $items = $bucket['items'];
        $rows = $items === [] ? '<li>Sin evidencia.</li>' : implode('', array_map(static fn($row): string => '<li>' . $render(is_array($row) ? $row : []) . '</li>', $items));
        return '<ul>' . $rows . '</ul><p class="truncation">Truncado: ' . ($bucket['truncated'] ? 'sí · UNKNOWN' : 'no') . '</p>';
    }

    private static function release(mixed $release): string
    {
        return is_array($release) && is_string($release['tag_name'] ?? null)
            ? '<code>' . self::e($release['tag_name']) . '</code>'
            : '<span>Sin evidencia.</span>';
    }

    private static function workflow(mixed $workflow): string
    {
        return is_array($workflow)
            ? self::e((string)($workflow['name'] ?? 'UNKNOWN')) . ' · ' . self::e((string)($workflow['status'] ?? 'unknown'))
                . ' · ' . self::e((string)($workflow['conclusion'] ?? 'pending'))
            : '<span>Sin evidencia.</span>';
    }

    private static function section(string $title, string $body): string
    {
        return '<section class="block"><h3>' . self::e($title) . '</h3>' . $body . '</section>';
    }

    private static function time(mixed $value): string { return is_int($value) ? (string)$value : 'UNKNOWN'; }
    private static function age(mixed $value): string { return is_int($value) ? $value . ' s' : 'UNKNOWN'; }
    private static function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

    private static function styles(): string
    {
        return UiTheme::tokensCss() . <<<'CSS'
*{box-sizing:border-box}html{background:var(--bg)}body{margin:0;color:var(--text);font-family:Inter,system-ui,sans-serif;background:var(--bg)}
.shell{width:min(100%,1180px);margin:auto;padding:24px 16px 48px}header{min-width:0}.eyebrow{color:var(--cyan);letter-spacing:.07em;font:700 .72rem/1.3 ui-monospace,monospace}
h1,h2,h3{overflow-wrap:anywhere}h1{font-size:clamp(1.8rem,8vw,3rem)}.meta,.truncation,.empty{color:var(--muted);overflow-wrap:anywhere}
.attention{display:grid;gap:6px;margin:18px 0;padding:16px;border:1px solid var(--line);border-radius:10px;background:var(--panel)}
.attention-unknown{border-color:var(--amber)}.surface-state{padding:18px;border:1px solid var(--line);border-radius:10px;background:var(--panel);color:var(--muted)}.attention strong{font-family:ui-monospace,monospace}.repo-grid{display:grid;grid-template-columns:1fr;gap:16px}
.repo{min-width:0;padding:18px;border:1px solid var(--line);border-radius:10px;background:var(--panel)}.repo-unknown{border-color:var(--amber)}
.block{border-top:1px solid var(--line);padding-top:12px;margin-top:12px}.block h3{font-size:.95rem}.block ul{padding-left:20px}.block li{margin:6px 0;overflow-wrap:anywhere}
code{font-family:"JetBrains Mono",ui-monospace,monospace;overflow-wrap:anywhere}a,button,[tabindex]:not([tabindex="-1"]){outline-offset:3px}
a:focus-visible,button:focus-visible,[tabindex]:focus-visible{outline:3px solid var(--amber)}
@media(min-width:760px){.shell{padding:36px 28px 60px}.repo-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation:none!important;transition:none!important;scroll-behavior:auto!important}}
CSS;
    }
}
