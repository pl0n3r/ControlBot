<?php
declare(strict_types=1);

namespace ControlBot\GitHub;

require_once __DIR__ . '/UiTheme.php';

use ControlBot\Ui\UiTheme;
use InvalidArgumentException;

final class GitHubGlobalUi
{
    private const PROJECT_STATES = ['current', 'unknown'];
    private const FRESHNESS = ['current', 'stale', 'unknown'];

    public static function render(array $projects): string
    {
        if (!array_is_list($projects)) {
            throw new InvalidArgumentException('Projects must be a list.');
        }

        $cards = '';
        foreach ($projects as $project) {
            $cards .= self::projectCard($project);
        }

        if ($cards === '') {
            $cards = '<p class="empty" role="status">No hay proyectos GitHub disponibles.</p>';
        }

        return '<!doctype html><html lang="es"><head>'
            . '<meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
            . '<title>ControlBot · GitHub global</title>'
            . '<style>' . self::styles() . '</style>'
            . '</head><body><main class="shell" data-surface="owner-only-read-only" aria-labelledby="github-global-title">'
            . '<header class="topbar"><p class="eyebrow">CONTROLBOT / GITHUB / GLOBAL</p>'
            . '<h1 id="github-global-title">Evidencia GitHub por proyecto</h1>'
            . '<p class="lede">Vista de solo lectura. Conserva estado, frescura y truncación de la proyección canónica sin reinterpretarlos.</p>'
            . '</header><section class="project-grid" aria-label="Proyectos GitHub">' . $cards . '</section>'
            . '</main></body></html>';
    }

    private static function projectCard(mixed $project): string
    {
        if (!is_array($project) || array_is_list($project)) {
            throw new InvalidArgumentException('Project projection invalid.');
        }

        $state = self::choice($project['state'] ?? null, self::PROJECT_STATES, 'project.state');
        $freshness = self::choice($project['freshness'] ?? null, self::FRESHNESS, 'project.freshness');
        $repositories = $project['repositories'] ?? null;
        if (!is_array($repositories) || !array_is_list($repositories)) {
            throw new InvalidArgumentException('project.repositories invalid.');
        }

        $projectId = $project['project_id'] ?? null;
        if ($projectId !== null && (!is_string($projectId) || trim($projectId) === '')) {
            throw new InvalidArgumentException('project.project_id invalid.');
        }
        $label = $projectId === null ? 'proyección desconocida' : $projectId;

        $repos = '';
        foreach ($repositories as $repository) {
            $repos .= self::repositoryCard($repository);
        }
        if ($repos === '') {
            $reason = $project['reason'] ?? null;
            $suffix = is_string($reason) && trim($reason) !== ''
                ? ' Motivo: ' . self::e($reason) . '.'
                : '';
            $repos = '<p class="empty">Sin evidencia de repositorios.' . $suffix . '</p>';
        }

        return '<article class="project-card" data-project-state="' . self::e($state)
            . '" data-project-freshness="' . self::e($freshness) . '">'
            . '<div class="project-heading"><div><p class="eyebrow">PROJECT</p><h2>'
            . self::e($label) . '</h2></div>'
            . '<div class="badges" aria-label="Estado de proyecto"><span>state=' . self::e($state)
            . '</span><span>freshness=' . self::e($freshness) . '</span></div></div>'
            . '<div class="repo-list">' . $repos . '</div></article>';
    }

    private static function repositoryCard(mixed $repository): string
    {
        if (!is_array($repository) || array_is_list($repository)) {
            throw new InvalidArgumentException('Repository projection invalid.');
        }

        $name = self::text($repository['repository'] ?? null, 'repository.repository');
        $source = self::source($repository['source_ref'] ?? null, $name);
        $state = self::choice($repository['state'] ?? null, self::PROJECT_STATES, 'repository.state');
        $freshness = self::choice($repository['freshness'] ?? null, self::FRESHNESS, 'repository.freshness');
        $sha = self::sha($repository['main_sha'] ?? null);

        $checks = self::collectionMeta($repository['checks'] ?? null, 'checks');
        $prs = self::collectionMeta($repository['pull_requests'] ?? null, 'pull_requests');
        $issues = self::collectionMeta($repository['issues'] ?? null, 'issues');

        return '<section class="repo-card" data-repository-state="' . self::e($state)
            . '" data-repository-freshness="' . self::e($freshness) . '">'
            . '<div class="repo-heading"><div><p class="eyebrow">REPOSITORY</p><h3>' . self::e($name) . '</h3></div>'
            . '<a class="evidence-link" href="' . self::e($source)
            . '" rel="noreferrer noopener">abrir evidencia</a></div>'
            . '<dl class="facts"><div><dt>state</dt><dd>' . self::e($state)
            . '</dd></div><div><dt>freshness</dt><dd>' . self::e($freshness)
            . '</dd></div><div><dt>main</dt><dd><code>' . self::e(substr($sha, 0, 12))
            . '</code></dd></div></dl>'
            . '<ul class="collections" aria-label="Cobertura de evidencia">'
            . self::collectionRow('checks', $checks)
            . self::collectionRow('pull_requests', $prs)
            . self::collectionRow('issues', $issues)
            . '</ul></section>';
    }

    private static function collectionMeta(mixed $collection, string $label): array
    {
        if (!is_array($collection) || array_is_list($collection)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        $items = $collection['items'] ?? null;
        $truncated = $collection['truncated'] ?? null;
        if (!is_array($items) || !array_is_list($items) || !is_bool($truncated)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return ['count' => count($items), 'truncated' => $truncated];
    }

    private static function collectionRow(string $label, array $meta): string
    {
        return '<li><span>' . self::e($label) . '</span><strong>'
            . (string) $meta['count'] . '</strong><em>truncated='
            . ($meta['truncated'] ? 'true' : 'false') . '</em></li>';
    }

    private static function source(mixed $value, string $repository): string
    {
        $expected = 'https://github.com/' . $repository;
        if (!is_string($value) || $value !== $expected) {
            throw new InvalidArgumentException('repository.source_ref invalid.');
        }
        return $value;
    }

    private static function text(mixed $value, string $label): string
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > 200) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return trim($value);
    }

    private static function sha(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[0-9a-f]{40}$/D', $value) !== 1) {
            throw new InvalidArgumentException('repository.main_sha invalid.');
        }
        return $value;
    }

    private static function choice(mixed $value, array $allowed, string $label): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function styles(): string
    {
        return UiTheme::tokensCss() . <<<'CSS'
* { box-sizing: border-box; }
html { background: var(--bg); }
body {
  margin: 0;
  min-height: 100vh;
  color: var(--text);
  background: var(--bg);
  font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
}
.shell { width: min(100%, 1180px); margin: 0 auto; padding: 24px 16px 48px; }
.topbar { padding-bottom: 20px; border-bottom: 1px solid var(--line); }
.eyebrow { margin: 0 0 8px; color: var(--cyan); font: 700 .72rem/1.2 ui-monospace, monospace; letter-spacing: .08em; }
h1, h2, h3 { margin: 0; }
h1 { font-size: clamp(1.8rem, 8vw, 3rem); }
.lede, .empty { color: var(--muted); line-height: 1.6; }
.project-grid, .repo-list { display: grid; gap: 16px; }
.project-grid { margin-top: 18px; }
.project-card, .repo-card { border: 1px solid var(--line); border-radius: 10px; background: var(--panel); padding: 18px; }
.project-heading, .repo-heading { display: flex; flex-wrap: wrap; gap: 12px; justify-content: space-between; align-items: flex-start; }
.badges { display: flex; flex-wrap: wrap; gap: 6px; }
.badges span { border: 1px solid var(--line); border-radius: 999px; padding: 5px 8px; color: var(--muted); font: 700 .72rem/1 ui-monospace, monospace; }
.repo-list { margin-top: 16px; }
.repo-card { background: var(--panel-raised); }
.evidence-link { color: var(--cyan); min-height: 44px; display: inline-flex; align-items: center; }
.facts { display: grid; grid-template-columns: 1fr; gap: 8px; margin: 16px 0; }
.facts div { min-width: 0; }
.facts dt { color: var(--muted); font: 700 .7rem/1.2 ui-monospace, monospace; }
.facts dd { margin: 4px 0 0; overflow-wrap: anywhere; }
.collections { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; }
.collections li { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 4px 12px; padding-top: 8px; border-top: 1px solid var(--line); }
.collections em { grid-column: 1 / -1; color: var(--muted); font: normal .72rem/1.3 ui-monospace, monospace; }
a:focus-visible { outline: 3px solid var(--amber); outline-offset: 3px; }
@media (min-width: 760px) {
  .shell { padding: 36px 28px 64px; }
  .facts { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
@media (min-width: 1040px) {
  .project-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
CSS;
    }
}
