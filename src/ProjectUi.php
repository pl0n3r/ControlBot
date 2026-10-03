<?php
declare(strict_types=1);

namespace ControlBot\Project;

require_once __DIR__ . '/ProjectModel.php';
require_once __DIR__ . '/UiTheme.php';

use ControlBot\Ui\UiTheme;
use InvalidArgumentException;

final class ProjectUi
{
    private const AGGREGATES = [
        'roadmap' => 'Roadmap',
        'agents' => 'Agentes',
        'decisions' => 'Decisiones',
        'health' => 'Salud',
        'incidents' => 'Incidentes',
        'costs' => 'Costos',
    ];

    public static function renderList(array $projects): string
    {
        if (!array_is_list($projects)) throw new InvalidArgumentException('Projects list invalid.');
        $normalized = [];
        foreach ($projects as $project) {
            if (!is_array($project)) throw new InvalidArgumentException('Project invalid.');
            $normalized[] = ProjectModel::normalize($project);
        }
        usort($normalized, static fn(array $a, array $b): int => $a['title'] <=> $b['title']);
        $cards = $normalized === [] ? '<p class="empty">EMPTY · Sin proyectos.</p>' : '';
        foreach ($normalized as $project) $cards .= self::projectCard($project);

        return self::shell('Proyectos',
            '<header><p class="eyebrow">CONTROLBOT / PROYECTOS</p><h1>Proyectos</h1>'
            . '<p class="lede">Vista canónica y solo lectura del modelo Project.</p></header>'
            . '<section class="project-grid" aria-label="Proyectos">' . $cards . '</section>');
    }

    public static function renderDetail(array $project): string
    {
        $project = ProjectModel::normalize($project);
        $repos = $project['repositories'] === [] ? '<li>Sin repositorios.</li>' : '';
        foreach ($project['repositories'] as $repo) {
            $repos .= '<li><strong>' . self::e($repo['repository']) . '</strong>'
                . self::reference($repo['source_ref'])
                . '<span>observed_at ' . $repo['observed_at'] . '</span></li>';
        }
        $envs = $project['environments'] === [] ? '<li>Sin entornos.</li>' : '';
        foreach ($project['environments'] as $env) {
            $envs .= '<li><strong>' . self::e(strtoupper($env['kind'])) . '</strong>'
                . self::reference($env['source_ref'])
                . '<span>observed_at ' . $env['observed_at'] . '</span></li>';
        }
        $aggregates = '';
        foreach (self::AGGREGATES as $key => $label) {
            $ref = $project['aggregate_refs'][$key];
            $aggregates .= '<li data-aggregate="' . $key . '"><strong>' . self::e($label) . '</strong>'
                . ($ref === null
                    ? '<span class="unknown">UNKNOWN · Sin referencia.</span>'
                    : self::reference($ref['ref']) . '<span>observed_at ' . $ref['observed_at'] . '</span>')
                . '</li>';
        }

        return self::shell($project['title'],
            '<header><p class="eyebrow">CONTROLBOT / PROYECTO</p><h1>' . self::e($project['title']) . '</h1>'
            . '<p class="meta"><strong>' . self::e($project['project_id']) . '</strong> · '
            . self::e(strtoupper($project['phase'])) . ' · ' . self::e(strtoupper($project['priority'])) . '</p></header>'
            . '<div class="detail-grid">'
            . self::panel('Repositorios', '<ul>' . $repos . '</ul>')
            . self::panel('Entornos', '<ul>' . $envs . '</ul>')
            . self::panel('Referencias agregadas', '<ul class="aggregates">' . $aggregates . '</ul>', 'wide')
            . '</div>');
    }

    private static function projectCard(array $project): string
    {
        return '<article class="panel project-card" data-project="' . self::e($project['project_id']) . '">'
            . '<p class="eyebrow">' . self::e(strtoupper($project['phase'])) . '</p>'
            . '<h2>' . self::e($project['title']) . '</h2>'
            . '<p class="meta">' . self::e(strtoupper($project['priority'])) . ' · '
            . count($project['repositories']) . ' repos · ' . count($project['environments']) . ' entornos</p>'
            . '</article>';
    }

    private static function panel(string $title, string $body, string $class = ''): string
    {
        return '<section class="panel ' . self::e($class) . '"><h2>' . self::e($title) . '</h2>' . $body . '</section>';
    }

    private static function reference(string $ref): string
    {
        $label = self::e($ref);
        if (str_starts_with($ref, 'https://github.com/')) {
            return '<a class="ref" href="' . $label . '" rel="noreferrer">' . $label . '</a>';
        }
        return '<span class="ref">' . $label . '</span>';
    }

    private static function shell(string $title, string $body): string
    {
        return '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
            . '<title>ControlBot · ' . self::e($title) . '</title><style>' . self::styles() . '</style></head>'
            . '<body><main class="shell">' . $body . '</main></body></html>';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function styles(): string
    {
        return UiTheme::tokensCss() . <<<'CSS'
*{box-sizing:border-box}html{background:var(--bg)}body{margin:0;color:var(--text);font-family:Inter,system-ui,sans-serif;background:var(--bg)}
.shell{width:min(100%,1180px);margin:auto;padding:24px 16px 48px}.eyebrow{color:var(--cyan);letter-spacing:.07em;font:700 .72rem/1.3 ui-monospace,monospace}
h1,h2{overflow-wrap:anywhere}h1{font-size:clamp(1.8rem,8vw,3rem)}h2{font-size:1.05rem}.lede,.meta,.ref,.empty{color:var(--muted);overflow-wrap:anywhere}
.project-grid,.detail-grid{display:grid;grid-template-columns:1fr;gap:16px;margin-top:18px}.panel{min-width:0;padding:18px;border:1px solid var(--line);border-radius:10px;background:var(--panel)}
.panel ul{display:grid;gap:12px;margin:12px 0 0;padding:0;list-style:none}.panel li{display:grid;gap:4px;border-top:1px solid var(--line);padding-top:10px;overflow-wrap:anywhere}
.ref{display:block}.unknown{color:var(--amber);font-family:"JetBrains Mono",ui-monospace,monospace}a{color:var(--cyan);outline-offset:3px}a:focus-visible{outline:3px solid var(--amber)}
@media(min-width:760px){.shell{padding:36px 28px 60px}.project-grid,.detail-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.wide{grid-column:1/-1}}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation:none!important;transition:none!important;scroll-behavior:auto!important}}
CSS;
    }
}
