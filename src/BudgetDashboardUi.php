<?php
declare(strict_types=1);

namespace ControlBot\Budget;

require_once __DIR__ . '/BudgetGuard.php';
require_once __DIR__ . '/UiTheme.php';

use ControlBot\Ui\UiTheme;

final class BudgetDashboardUi
{
    public static function render(array $account, array $projects): string
    {
        $summary = (new BudgetGuard())->summarize($account, $projects);
        $projectCards = self::projectCards($summary['projects']);

        return '<!doctype html><html lang="es"><head>'
            . '<meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
            . '<title>ControlBot · Presupuesto operativo</title>'
            . '<style>' . self::styles() . '</style>'
            . '</head><body><main class="shell" aria-labelledby="budget-title">'
            . '<header class="topbar"><p class="eyebrow"><span class="brand-mark">CONTROLBOT</span> / BUDGET</p>'
            . '<h1 id="budget-title">Presupuesto operativo</h1>'
            . '<p class="lede">Lectura de capacidad observada. Los valores no demostrados se muestran como unknown y esta vista no permite mutaciones financieras.</p>'
            . '</header>'
            . '<section class="identity panel" aria-label="Cuenta observada">'
            . '<div><span>Proveedor</span><strong>' . self::e($summary['provider']) . '</strong></div>'
            . '<div><span>Cuenta</span><strong>' . self::e($summary['account_scope']) . '</strong></div>'
            . '<div><span>Recurso</span><strong>' . self::e($summary['resource']) . '</strong></div>'
            . '<div><span>Estado</span><strong class="state state-' . self::e($summary['state']) . '">' . self::e(self::stateLabel($summary['state'])) . '</strong></div>'
            . '</section>'
            . '<section class="summary-grid" aria-label="Resumen presupuestario">'
            . self::metric('Consumo actual', self::pair($summary['used'], $summary['included']))
            . self::metric('Utilización', self::percent($summary['utilization_percent']))
            . self::metric('Baseline mensual', self::value($summary['projected_baseline_monthly']))
            . self::metric('Headroom', self::value($summary['headroom_for_change']))
            . self::metric('Uso variable', self::value($summary['variable_usage']))
            . self::metric('Reset', self::value($summary['reset_at']))
            . '</section>'
            . '<section class="projects" aria-labelledby="projects-title"><div class="section-head">'
            . '<p class="eyebrow">ATRIBUCIÓN</p><h2 id="projects-title">Proyectos y repositorios</h2></div>'
            . $projectCards
            . '</section>'
            . '</main></body></html>';
    }

    private static function projectCards(array $projects): string
    {
        if ($projects === []) {
            return '<p class="empty panel">Sin atribuciones disponibles.</p>';
        }

        $cards = '';
        foreach ($projects as $row) {
            $scope = $row['counts_toward_scope'] ? 'cuenta hacia el scope' : 'fuera del scope';
            $cards .= '<article class="panel project-card">'
                . '<div class="project-head"><div><p class="eyebrow">' . self::e($row['repository_visibility']) . '</p>'
                . '<h3>' . self::e($row['project']) . '</h3>'
                . '<code>' . self::e($row['repository']) . '</code></div>'
                . '<span class="scope">' . self::e($scope) . '</span></div>'
                . '<dl>'
                . '<div><dt>Uso atribuido</dt><dd>' . self::e(self::value($row['attributed_used'])) . '</dd></div>'
                . '<div><dt>Baseline mensual</dt><dd>' . self::e(self::value($row['projected_baseline_monthly'])) . '</dd></div>'
                . '</dl>'
                . '</article>';
        }
        return '<div class="project-grid">' . $cards . '</div>';
    }

    private static function metric(string $label, string $value): string
    {
        return '<article class="panel metric"><span>' . self::e($label) . '</span><strong>' . self::e($value) . '</strong></article>';
    }

    private static function pair(mixed $used, mixed $included): string
    {
        return self::value($used) . ' / ' . self::value($included);
    }

    private static function percent(mixed $value): string
    {
        return $value === null ? 'unknown' : self::value($value) . '%';
    }

    private static function value(mixed $value): string
    {
        if ($value === null) {
            return 'unknown';
        }
        if (is_float($value) || is_int($value)) {
            return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
        }
        return (string) $value;
    }

    private static function stateLabel(string $state): string
    {
        return match ($state) {
            'normal' => 'normal',
            'warning' => 'advertencia',
            'critical' => 'crítico',
            'exhausted' => 'agotado',
            'blocked' => 'bloqueado',
            default => 'unknown',
        };
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
.topbar { padding: 12px 2px 22px; border-bottom: 1px solid var(--line); margin-bottom: 18px; }
.eyebrow { margin: 0; color: var(--muted); letter-spacing: .08em; font: 700 .72rem/1.2 "JetBrains Mono", ui-monospace, monospace; }
.brand-mark { color: var(--cyan); font-family: Orbitron, Inter, system-ui, sans-serif; }
h1, h2, h3 { margin: 0; }
h1 { margin-top: 8px; font-size: clamp(1.9rem, 9vw, 3.2rem); letter-spacing: -.035em; }
.lede { max-width: 68ch; color: var(--muted); line-height: 1.6; }
.panel { border: 1px solid var(--line); border-radius: 10px; background: var(--panel); }
.identity { display: grid; grid-template-columns: 1fr; gap: 14px; padding: 18px; }
.identity div, .metric { min-width: 0; display: grid; gap: 6px; }
.identity span, .metric span, dt { color: var(--muted); font-size: .78rem; }
.identity strong, .metric strong, dd, code { min-width: 0; overflow-wrap: anywhere; font-family: "JetBrains Mono", ui-monospace, monospace; }
.state-normal { color: var(--green); }
.state-warning { color: var(--amber); }
.state-critical, .state-exhausted, .state-blocked { color: var(--red); }
.summary-grid { display: grid; grid-template-columns: 1fr; gap: 12px; margin-top: 12px; }
.metric { padding: 16px; }
.metric strong { font-size: 1.08rem; }
.projects { margin-top: 28px; }
.section-head { display: grid; gap: 7px; margin-bottom: 12px; }
.project-grid { display: grid; grid-template-columns: 1fr; gap: 12px; }
.project-card { min-width: 0; padding: 18px; }
.project-head { min-width: 0; display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
.project-head > div { min-width: 0; }
.project-card h3 { margin: 7px 0 5px; }
.project-card code { display: block; color: var(--muted); }
.scope { flex: 0 0 auto; max-width: 46%; border: 1px solid var(--line-strong); border-radius: 999px; padding: 5px 8px; color: var(--cyan); font: 700 .68rem/1.2 "JetBrains Mono", ui-monospace, monospace; text-align: center; }
dl { display: grid; gap: 10px; margin: 18px 0 0; }
dl div { display: flex; justify-content: space-between; gap: 12px; border-top: 1px solid var(--line); padding-top: 10px; }
dd { margin: 0; text-align: right; }
.empty { padding: 18px; color: var(--muted); }
@media (min-width: 760px) {
  .shell { padding: 40px 28px 64px; }
  .identity { grid-template-columns: repeat(4, minmax(0, 1fr)); }
  .summary-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
  .project-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after { animation: none !important; scroll-behavior: auto !important; transition: none !important; }
}
CSS;
    }
}
