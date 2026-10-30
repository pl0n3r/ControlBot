<?php
declare(strict_types=1);

namespace ControlBot\Dashboard;

require_once __DIR__ . '/UiTheme.php';
require_once __DIR__ . '/ControlCenterShell.php';

use ControlBot\Ui\ControlCenterShell;
use ControlBot\Ui\UiTheme;
use InvalidArgumentException;

final class DashboardUi
{
    private const DOMAINS = [
        'production' => 'Producción',
        'work' => 'Trabajo',
        'security' => 'Seguridad',
        'costs' => 'Costos',
    ];

    private const HEALTH = ['healthy', 'warning', 'critical', 'unknown'];

    public static function render(array $state, array $routes = []): string
    {
        $health = self::health($state['health'] ?? null);
        $domains = is_array($state['domains'] ?? null) ? $state['domains'] : [];

        $cards = '';
        foreach (self::DOMAINS as $key => $label) {
            $cards .= self::domainCard($key, $label, $domains[$key] ?? null);
        }

        $content = '<main class="shell" aria-labelledby="dashboard-title">'
            . '<header class="topbar"><p class="eyebrow"><span class="brand-mark">CONTROLBOT</span> / OVERVIEW</p>'
            . '<h1 id="dashboard-title">Centro de control</h1>'
            . '<p class="lede">Estado operativo resumido. Sin evidencia suficiente, ControlBot muestra estado desconocido en lugar de inventar datos.</p>'
            . '</header>'
            . '<section class="health-core health-' . $health . '" aria-label="Salud de la fábrica">'
            . '<div class="core-rings" aria-hidden="true"><span></span><span></span><span></span></div>'
            . '<div class="health-copy"><span>salud de fábrica</span><strong>' . self::healthLabel($health) . '</strong></div>'
            . '</section>'
            . '<section class="dashboard-grid" aria-label="Dominios operativos">' . $cards . '</section>'
            . '</main>';

        return ControlCenterShell::render('Centro de control', 'overview', $routes, $content, self::styles());
    }

    private static function health(mixed $value): string
    {
        return is_string($value) && in_array($value, self::HEALTH, true) ? $value : 'unknown';
    }

    private static function healthLabel(string $health): string
    {
        return match ($health) {
            'healthy' => 'estable',
            'warning' => 'atención',
            'critical' => 'crítico',
            default => 'desconocido',
        };
    }

    private static function domainCard(string $key, string $label, mixed $domain): string
    {
        $content = '<p class="empty">Sin evidencia disponible.</p>';
        if ($domain !== null) {
            if (!is_array($domain) || ($domain !== [] && array_is_list($domain))) {
                throw new InvalidArgumentException("Dominio {$key} inválido.");
            }
            $rows = '';
            foreach ($domain as $name => $value) {
                if (!is_string($name) || trim($name) === '') {
                    throw new InvalidArgumentException("Clave de dominio {$key} inválida.");
                }
                if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
                    throw new InvalidArgumentException("Valor de dominio {$key} inválido.");
                }
                $display = is_bool($value) ? ($value ? 'sí' : 'no') : (string) $value;
                $rows .= '<li><span>' . self::e($name) . '</span><strong>' . self::e($display) . '</strong></li>';
            }
            $content = $rows === '' ? '<p class="empty">Sin evidencia disponible.</p>' : '<ul class="metric-list">' . $rows . '</ul>';
        }

        return '<article class="panel domain-card" data-domain="' . $key . '">'
            . '<div class="panel-line" aria-hidden="true"></div>'
            . '<p class="eyebrow">' . self::e(strtoupper($label)) . '</p>'
            . '<h2>' . self::e($label) . '</h2>'
            . $content
            . '</article>';
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
  font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
  background:
    linear-gradient(rgba(124,201,221,.018) 1px, transparent 1px),
    linear-gradient(90deg, rgba(124,201,221,.018) 1px, transparent 1px),
    var(--bg);
  background-size: 40px 40px, 40px 40px, auto;
}
.shell { width: min(100%, 1180px); margin: 0 auto; padding: 24px 16px 48px; }
.topbar { padding: 12px 2px 22px; border-bottom: 1px solid var(--line); margin-bottom: 18px; }
.eyebrow { color: var(--muted); letter-spacing: .08em; font: 700 .72rem/1.2 "JetBrains Mono", ui-monospace, monospace; }
.brand-mark { color: var(--cyan); font-family: Orbitron, Inter, system-ui, sans-serif; letter-spacing: .08em; }
h1, h2 { margin: 0; font-family: Inter, system-ui, sans-serif; }
h1 { font-size: clamp(1.9rem, 9vw, 3.2rem); letter-spacing: -.035em; }
.lede { max-width: 66ch; color: var(--muted); line-height: 1.6; }
.health-core {
  --state: var(--muted);
  min-height: 200px;
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  align-items: center;
  gap: clamp(20px, 4vw, 48px);
  margin: 18px 0;
  padding: clamp(20px, 4vw, 40px);
  border: 1px solid var(--line);
  border-top: 6px solid var(--state);
  border-radius: 10px;
  background: var(--panel);
}
.health-healthy { --state: var(--green); }
.health-warning { --state: var(--amber); }
.health-critical { --state: var(--red); }
.core-rings { width: clamp(92px, 16vw, 148px); aspect-ratio: 1; border: 2px solid var(--state); border-radius: 50%; position: relative; }
.core-rings span { position: absolute; inset: 14%; border: 1px solid var(--state); border-radius: 50%; opacity: .7; }
.core-rings span:nth-child(2) { inset: 28%; opacity: .5; }
.core-rings span:nth-child(3) { inset: 42%; opacity: 1; background: var(--state); }
.health-copy { display: grid; gap: 8px; }
.health-copy span { color: var(--muted); font: 700 .78rem/1.2 "JetBrains Mono", ui-monospace, monospace; letter-spacing: .12em; text-transform: uppercase; }
.health-copy strong { color: var(--state); font-size: clamp(2.4rem, 8vw, 5rem); line-height: .95; letter-spacing: -.03em; text-transform: uppercase; }
@media (max-width: 520px) {
  .health-core { grid-template-columns: 1fr; justify-items: start; }
}
@media (prefers-reduced-motion: no-preference) {
  .core-rings span:nth-child(3) { animation: core-pulse 2.4s ease-in-out infinite; }
  @keyframes core-pulse { 50% { transform: scale(.7); opacity: .55; } }
}
.dashboard-grid { display: grid; gap: 16px; grid-template-columns: 1fr; }
.panel { position: relative; overflow: hidden; border: 1px solid var(--line); border-radius: 10px; background: var(--panel); padding: 20px; }
.panel-line { position: absolute; inset: 0 auto auto 0; width: 38%; height: 3px; background: var(--cyan); }
.domain-card h2 { margin-top: 8px; font-size: 1.25rem; }
.metric-list { display: grid; gap: 10px; margin: 18px 0 0; padding: 0; list-style: none; }
.metric-list li { display: flex; justify-content: space-between; gap: 16px; border-top: 1px solid var(--line); padding-top: 10px; }
.metric-list span { min-width: 0; color: var(--muted); overflow-wrap: anywhere; }
.metric-list strong { min-width: 0; text-align: right; font-family: "JetBrains Mono", ui-monospace, monospace; overflow-wrap: anywhere; }
.metric-list li strong { font-size: 1.1rem; }
.empty { color: var(--muted); margin: 18px 0 0; }
a, button, summary, [tabindex]:not([tabindex="-1"]) { outline-offset: 3px; }
a:focus-visible, button:focus-visible, summary:focus-visible, [tabindex]:focus-visible { outline: 3px solid var(--amber); }
@media (min-width: 760px) {
  .shell { padding: 40px 28px 64px; }
  .dashboard-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (min-width: 1040px) {
  .dashboard-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
}
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after { animation: none !important; scroll-behavior: auto !important; transition: none !important; }
}
CSS;
    }
}
