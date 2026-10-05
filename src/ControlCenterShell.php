<?php
declare(strict_types=1);

namespace ControlBot\Ui;

require_once __DIR__ . '/UiTheme.php';

use InvalidArgumentException;

final class ControlCenterShell
{
    private const SECTIONS = [
        'overview' => 'Resumen',
        'projects' => 'Proyectos',
        'accounts' => 'Cuentas',
        'agents' => 'Agentes',
        'work' => 'Trabajo',
        'factory-live' => 'Fábrica viva',
        'github' => 'GitHub',
        'decisions' => 'Decisiones',
    ];

    public static function render(
        string $title,
        string $active,
        array $routes,
        string $content,
        string $extraCss = ''
    ): string {
        if (!array_key_exists($active, self::SECTIONS)) {
            throw new InvalidArgumentException('Active section invalid.');
        }

        $navigation = '';
        foreach (self::SECTIONS as $key => $label) {
            $route = self::route($routes[$key] ?? null);
            if ($route === null) {
                $navigation .= '<span class="nav-item nav-disabled" data-nav="' . $key
                    . '" aria-disabled="true">' . self::e($label) . '</span>';
                continue;
            }

            $navigation .= '<a class="nav-item' . ($key === $active ? ' nav-active' : '')
                . '" data-nav="' . $key . '" href="' . self::e($route) . '"'
                . ($key === $active ? ' aria-current="page"' : '')
                . '>' . self::e($label) . '</a>';
        }

        return '<!doctype html><html lang="es"><head>'
            . '<meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
            . '<title>ControlBot · ' . self::e($title) . '</title>'
            . '<style>' . UiTheme::tokensCss() . self::styles() . $extraCss . '</style>'
            . '</head><body data-section="' . self::e($active) . '">'
            . '<header class="control-shell-header">'
            . '<a class="control-brand" href="#control-content">CONTROLBOT</a>'
            . '<nav class="control-nav" aria-label="Navegación principal">' . $navigation . '</nav>'
            . '</header>'
            . '<div id="control-content" class="control-content">' . $content . '</div>'
            . '</body></html>';
    }

    private static function route(mixed $value): ?string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 160) return null;
        if (preg_match('/[\x00-\x20\x7f]/', $value) === 1) return null;
        if (str_contains($value, '//') || str_contains($value, '..')) return null;
        if (preg_match('#^/[a-z0-9](?:[a-z0-9/_-]*[a-z0-9_-])?$#D', $value) !== 1) return null;
        return $value;
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function styles(): string
    {
        return <<<'CSS'
* { box-sizing: border-box; }
html { background: var(--bg); }
body {
  margin: 0;
  min-height: 100vh;
  color: var(--text);
  background: var(--bg);
  font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
}
.control-shell-header {
  position: sticky;
  top: 0;
  z-index: 10;
  display: grid;
  gap: 12px;
  padding: 14px 16px;
  border-bottom: 1px solid var(--line);
  background: var(--panel);
}
.control-brand {
  width: fit-content;
  color: var(--cyan);
  font: 700 .78rem/1.2 Orbitron, Inter, system-ui, sans-serif;
  letter-spacing: .09em;
  text-decoration: none;
}
.control-nav { display: flex; flex-wrap: wrap; gap: 6px; }
.nav-item {
  min-height: 40px;
  display: inline-flex;
  align-items: center;
  padding: 8px 10px;
  border: 1px solid var(--line);
  border-radius: 7px;
  color: var(--text);
  font: 700 .72rem/1.2 "JetBrains Mono", ui-monospace, monospace;
  text-decoration: none;
}
.nav-active { border-color: var(--cyan); color: var(--cyan); }
.nav-disabled { color: var(--muted); opacity: .62; cursor: not-allowed; }
.control-content { min-width: 0; }
a:focus-visible, [tabindex]:focus-visible { outline: 3px solid var(--amber); outline-offset: 3px; }
@media (min-width: 860px) {
  .control-shell-header {
    grid-template-columns: auto minmax(0, 1fr);
    align-items: center;
    padding-inline: 28px;
  }
  .control-nav { justify-content: flex-end; }
}
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after { animation: none !important; transition: none !important; scroll-behavior: auto !important; }
}
CSS;
    }
}
