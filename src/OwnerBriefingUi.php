<?php
declare(strict_types=1);

namespace ControlBot\Briefing;

require_once __DIR__ . '/OwnerBriefing.php';
require_once __DIR__ . '/UiTheme.php';

use ControlBot\Ui\UiTheme;

final class OwnerBriefingUi
{
    private const LABELS = [
        'delivered' => 'Entregado ayer',
        'today' => 'Hoy',
        'broken' => 'Roto / atención',
        'decisions' => 'Decisiones',
        'costs' => 'Costos',
    ];

    public static function render(array $snapshot): string
    {
        $briefing = OwnerBriefing::build($snapshot);
        $sections = '';

        foreach (self::LABELS as $key => $label) {
            $sections .= self::section($label, $briefing['sections'][$key]);
        }

        $attentionClass = $briefing['needs_owner_attention'] ? 'attention-needed' : 'attention-clear';

        return '<!doctype html><html lang="es"><head>'
            . '<meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
            . '<title>ControlBot · Briefing del dueño</title>'
            . '<style>' . self::styles() . '</style>'
            . '</head><body><main class="shell" aria-labelledby="briefing-title">'
            . '<header class="topbar">'
            . '<p class="eyebrow"><span class="brand-mark">CONTROLBOT</span> / DAILY BRIEF</p>'
            . '<h1 id="briefing-title">Briefing del dueño</h1>'
            . '<p class="lede">Lo esencial de la fábrica en menos de un minuto, con evidencia y sin inventar estados.</p>'
            . '<p class="attention ' . $attentionClass . '" role="status">'
            . self::e($briefing['attention_label'])
            . '</p></header>'
            . '<section class="briefing-grid" aria-label="Resumen diario">'
            . $sections
            . '</section></main></body></html>';
    }

    private static function section(string $label, array $section): string
    {
        $body = match ($section['status']) {
            'unknown' => '<p class="empty unknown">Fuente no disponible.</p>',
            'empty' => '<p class="empty">Sin novedades.</p>',
            default => self::items($section['items']),
        };

        $overflow = $section['overflow_count'] > 0
            ? '<p class="overflow">+' . self::e((string) $section['overflow_count']) . ' más</p>'
            : '';

        return '<article class="panel section-card">'
            . '<div class="section-head"><h2>' . self::e($label) . '</h2>'
            . '<span class="status">' . self::e($section['status']) . '</span></div>'
            . $body . $overflow
            . '</article>';
    }

    private static function items(array $items): string
    {
        $rows = '';
        foreach ($items as $item) {
            $rows .= '<li><span class="summary">' . self::e($item['summary']) . '</span>'
                . self::evidence($item['evidence'])
                . '</li>';
        }
        return '<ul>' . $rows . '</ul>';
    }

    private static function evidence(string $evidence): string
    {
        if (str_starts_with($evidence, 'https://github.com/')) {
            return '<a href="' . self::e($evidence) . '">Ver evidencia</a>';
        }

        return '<code class="evidence-ref">' . self::e($evidence) . '</code>';
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
.shell { width: min(100%, 1120px); margin: 0 auto; padding: 24px 16px 48px; }
.topbar { display: grid; gap: 10px; padding: 12px 2px 22px; border-bottom: 1px solid var(--line); }
.eyebrow { margin: 0; color: var(--muted); letter-spacing: .08em; font: 700 .72rem/1.2 "JetBrains Mono", ui-monospace, monospace; }
.brand-mark { color: var(--cyan); font-family: Orbitron, Inter, system-ui, sans-serif; }
h1, h2 { margin: 0; }
h1 { font-size: clamp(1.9rem, 9vw, 3rem); letter-spacing: -.035em; }
.lede { max-width: 68ch; margin: 0; color: var(--muted); line-height: 1.55; }
.attention { width: fit-content; max-width: 100%; margin: 4px 0 0; border: 1px solid var(--line-strong); border-radius: 999px; padding: 7px 10px; font: 700 .78rem/1.2 "JetBrains Mono", ui-monospace, monospace; overflow-wrap: anywhere; }
.attention-needed { color: var(--amber); }
.attention-clear { color: var(--green); }
.briefing-grid { display: grid; grid-template-columns: 1fr; gap: 12px; margin-top: 18px; }
.panel { min-width: 0; border: 1px solid var(--line); border-radius: 10px; background: var(--panel); }
.section-card { min-width: 0; padding: 16px; }
.section-head { min-width: 0; display: flex; align-items: baseline; justify-content: space-between; gap: 12px; padding-bottom: 11px; border-bottom: 1px solid var(--line); }
.section-head h2 { min-width: 0; font-size: 1rem; overflow-wrap: anywhere; }
.status { flex: 0 0 auto; color: var(--muted); font: 700 .68rem/1.2 "JetBrains Mono", ui-monospace, monospace; }
ul { display: grid; gap: 12px; margin: 14px 0 0; padding: 0; list-style: none; }
li { min-width: 0; display: grid; gap: 7px; }
.summary, .evidence-ref, a { min-width: 0; overflow-wrap: anywhere; }
.summary { line-height: 1.45; }
a { width: fit-content; max-width: 100%; color: var(--cyan); font: 700 .76rem/1.2 "JetBrains Mono", ui-monospace, monospace; text-underline-offset: 3px; }
a:focus-visible { outline: 2px solid var(--amber); outline-offset: 3px; border-radius: 3px; }
.evidence-ref { color: var(--muted); font: 600 .72rem/1.35 "JetBrains Mono", ui-monospace, monospace; }
.empty, .overflow { margin: 14px 0 0; color: var(--muted); }
.unknown { color: var(--amber); }
.overflow { font: 700 .72rem/1.2 "JetBrains Mono", ui-monospace, monospace; }
@media (min-width: 760px) {
  .shell { padding: 40px 28px 64px; }
  .briefing-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .section-card:first-child { grid-column: span 2; }
}
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after { animation: none !important; scroll-behavior: auto !important; transition: none !important; }
}
CSS;
    }
}
