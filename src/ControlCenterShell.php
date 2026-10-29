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
            . '<span class="control-brand-caption">Centro de operación</span>'
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
  font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
}
.control-shell-header {
  position: relative;
  z-index: 10;
  display: grid;
  gap: 8px;
  padding: 14px 16px 12px;
  border-bottom: 1px solid var(--line);
  background: var(--panel);
}
.control-brand {
  width: fit-content;
  color: var(--cyan);
  font: 800 .91rem/1.2 system-ui, sans-serif;
  letter-spacing: .05em;
  text-decoration: none;
}
.control-brand-caption {
  color: var(--muted);
  font-size: .76rem;
  line-height: 1.3;
}
.control-nav { display: flex; flex-wrap: wrap; gap: 6px; min-width: 0; }
.nav-item {
  min-height: 44px; display: inline-flex; align-items: center; justify-content: flex-start;
  padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px;
  color: var(--text); font: 650 .83rem/1.25 system-ui, -apple-system, sans-serif;
  text-decoration: none; white-space: nowrap;
}
.nav-item:hover:not(.nav-disabled) { background: var(--control-hover); }
.nav-active {
  background: var(--accent-surface);
  border-color: var(--cyan);
  color: var(--cyan);
  font-weight: 800;
  text-decoration: underline;
  text-underline-offset: 4px;
}
.nav-active::before {
  content: '';
  display: inline-block;
  width: 5px;
  height: 20px;
  border-radius: 2px;
  background: var(--cyan);
  margin-right: 9px;
}
.nav-disabled {
  color: var(--muted);
  border-style: dashed;
  cursor: not-allowed;
}
.control-content { min-width: 0; overflow-wrap: anywhere; }
a:focus-visible, [tabindex]:focus-visible {
  outline: 3px solid var(--amber);
  outline-offset: 3px;
}
@media (max-width: 859px) {
  .control-shell-header { gap: 4px; }
  .control-nav {
    flex-wrap: nowrap;
    overflow-x: auto;
    overscroll-behavior-inline: contain;
    padding-block: 8px;
    scrollbar-width: thin;
  }
  .nav-item { flex: 0 0 auto; }
}
@media (min-width: 860px) {
  body { display: grid; grid-template-columns: var(--rail-width) minmax(0, 1fr); }
  .control-shell-header {
    position: sticky;
    top: 0;
    height: 100vh;
    height: 100dvh;
    align-self: start;
    align-content: start;
    gap: 12px;
    padding: 28px 16px;
    border-bottom: 0;
    border-right: 1px solid var(--line);
  }
  .control-nav { flex-direction: column; flex-wrap: nowrap; gap: 7px; }
  .nav-item { width: 100%; }
  .control-content { width: 100%; max-width: 100%; }
}
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    animation: none !important;
    transition: none !important;
    scroll-behavior: auto !important;
  }
}

CSS;
    }
}
