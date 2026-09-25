<?php
declare(strict_types=1);

namespace ControlBot\Decisions;

use InvalidArgumentException;

final class DecisionUi
{
    public static function render(array $decisions, bool $reauthenticated): string
    {
        $cards = $decisions === []
            ? self::emptyState()
            : implode('', array_map(
                static fn (array $decision): string => self::decisionCard($decision, $reauthenticated),
                $decisions
            ));

        return '<!doctype html><html lang="es"><head>'
            . '<meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
            . '<title>ControlBot · Te toca decidir</title>'
            . '<style>' . self::styles() . '</style>'
            . '</head><body><main class="shell" aria-labelledby="page-title">'
            . '<header class="topbar"><p class="eyebrow">CONTROLBOT / DECISIONES</p>'
            . '<h1 id="page-title">Te toca decidir</h1>'
            . '<p class="lede">Aprueba solo lo que requiere tu decisión. ControlBot no inventa decisiones ni ejecuta sin reautenticación reciente.</p>'
            . '</header>'
            . (!$reauthenticated && $decisions !== [] ? self::reauthBanner() : '')
            . '<section class="decision-grid" aria-live="polite">' . $cards . '</section>'
            . '</main></body></html>';
    }

    private static function decisionCard(array $decision, bool $reauthenticated): string
    {
        $required = ['repository', 'issue', 'title', 'context', 'options', 'recommendation'];
        foreach ($required as $key) {
            if (!array_key_exists($key, $decision)) {
                throw new InvalidArgumentException("Falta campo de decisión: {$key}");
            }
        }
        if (!is_string($decision['repository']) || !is_int($decision['issue']) || $decision['issue'] < 1
            || !is_string($decision['title']) || !is_string($decision['context'])
            || !is_array($decision['options']) || !is_string($decision['recommendation'])) {
            throw new InvalidArgumentException('Decisión inválida.');
        }

        $repository = self::e($decision['repository']);
        $issue = $decision['issue'];
        $simpleTitle = self::optionalText($decision, 'title_simple', 120);
        $simpleSummary = self::optionalText($decision, 'summary_simple', 400);
        $title = $simpleTitle !== '' ? $simpleTitle : self::e($decision['title']);
        $context = $simpleSummary !== '' ? $simpleSummary : self::e($decision['context']);
        $why = self::optionalText($decision, 'why_recommended', 180);
        $blocks = self::optionalText($decision, 'blocks', 180);
        $safeDefault = self::optionalText($decision, 'safe_default', 40);
        // Factory gates store safe_default as an option ID, not a user-facing sentence.
        if ($safeDefault !== '' && isset($decision['safe_default'])) {
            foreach ($decision['options'] as $choice) {
                if (is_array($choice) && ($choice['id'] ?? null) === $decision['safe_default']
                    && is_string($choice['label'] ?? null)) {
                    $safeDefault = self::e($choice['label']);
                    break;
                }
            }
        }
        $sha = isset($decision['sha']) && is_string($decision['sha']) ? $decision['sha'] : '';
        $shaEscaped = self::e($sha);
        $options = '';

        foreach ($decision['options'] as $option) {
            if (!is_array($option) || !isset($option['id'], $option['label'])
                || !is_string($option['id']) || !is_string($option['label'])) {
                throw new InvalidArgumentException('Opción de decisión inválida.');
            }
            $id = self::e($option['id']);
            $label = self::e($option['label']);
            $recommended = hash_equals($decision['recommendation'], $option['id']);
            $disabled = $reauthenticated ? '' : ' disabled aria-disabled="true"';
            $badge = $recommended ? '<span class="badge">RECOMENDADA</span>' : '';
            $effect = self::optionalText($option, 'effect', 240);
            $risk = self::riskLabel($option);
            $cost = self::optionalText($option, 'cost', 80);
            $pros = self::optionList($option, 'pros');
            $cons = self::optionList($option, 'cons');
            $reversible = self::reversibility($option);
            $details = $effect === '' ? '' : '<span class="effect">' . $effect . '</span>';
            $impact = array_filter([$risk, $cost === '' ? '' : 'Costo: ' . $cost, $reversible], static fn (string $v): bool => $v !== '');
            if ($impact !== []) {
                $details .= '<span class="impact">' . implode(' · ', $impact) . '</span>';
            }
            if ($pros !== '' || $cons !== '') {
                $details .= '<span class="tradeoffs">' . $pros . $cons . '</span>';
            }
            $options .= '<div class="decision-option">'
                . '<button class="decision-action' . ($recommended ? ' recommended' : '') . '"'
                . ' type="submit" name="option" value="' . $id . '"' . $disabled . '>'
                . '<span class="option-label">' . $label . '</span>'
                . $badge . '</button>'
                . ($details !== '' ? '<div class="option-copy">' . $details . '</div>' : '')
                . '</div>';
        }

        return '<article class="panel decision-card" data-state="' . ($reauthenticated ? 'ready' : 'reauth-required') . '">'
            . '<div class="panel-line" aria-hidden="true"></div>'
            . '<div class="meta"><span>' . $repository . '</span><span>#' . $issue . '</span></div>'
            . '<h2>' . $title . '</h2>'
            . '<p class="context">' . $context . '</p>'
            . ($why !== '' ? '<p class="explain"><strong>Por qué se recomienda:</strong> ' . $why . '</p>' : '')
            . ($safeDefault !== '' ? '<p class="safe-default"><strong>Si no decides:</strong> ' . $safeDefault . '</p>' : '')
            . ($blocks !== '' ? '<p class="blocker"><strong>Trabajo en espera:</strong> ' . $blocks . '</p>' : '')
            . ($shaEscaped !== '' ? '<details class="technical"><summary>Ver detalles técnicos</summary><p class="sha"><span>SHA</span><code>' . $shaEscaped . '</code></p></details>' : '')
            . '<form method="post" action="/approvals/execute" class="actions">'
            . '<input type="hidden" name="repository" value="' . $repository . '">'
            . '<input type="hidden" name="issue" value="' . $issue . '">'
            . '<input type="hidden" name="displayed_sha" value="' . $shaEscaped . '">'
            . $options
            . '</form>'
            . '</article>';
    }

    private static function optionalText(array $source, string $key, int $limit): string
    {
        if (!array_key_exists($key, $source)) {
            return '';
        }
        if (!is_string($source[$key]) || strlen($source[$key]) > $limit) {
            throw new InvalidArgumentException('Campo descriptivo inválido.');
        }
        return self::e(trim($source[$key]));
    }

    private static function riskLabel(array $option): string
    {
        if (!array_key_exists('risk', $option)) {
            return '';
        }
        return match ($option['risk']) {
            'low' => 'Riesgo bajo',
            'medium' => 'Riesgo medio',
            'high' => 'Riesgo alto',
            default => throw new InvalidArgumentException('Riesgo inválido.'),
        };
    }

    private static function reversibility(array $option): string
    {
        if (!array_key_exists('reversible', $option)) {
            return '';
        }
        if (!is_bool($option['reversible'])) {
            throw new InvalidArgumentException('Reversibilidad inválida.');
        }
        return $option['reversible'] ? 'Reversible' : 'No reversible';
    }

    private static function optionList(array $option, string $key): string
    {
        if (!array_key_exists($key, $option)) {
            return '';
        }
        $values = $option[$key];
        if (!is_array($values) || !array_is_list($values) || count($values) > 3) {
            throw new InvalidArgumentException('Lista de impacto inválida.');
        }
        $items = '';
        foreach ($values as $value) {
            if (!is_string($value) || trim($value) === '' || strlen($value) > 120) {
                throw new InvalidArgumentException('Elemento de impacto inválido.');
            }
            $items .= '<span>' . self::e($value) . '</span>';
        }
        if ($items === '') {
            return '';
        }
        return '<span class="' . $key . '"><strong>'
            . ($key === 'pros' ? '✓ Ventajas' : '✗ Desventajas')
            . '</strong>' . $items . '</span>';
    }

    private static function emptyState(): string
    {
        return '<article class="panel empty-state" data-state="empty">'
            . '<div class="status-orb" aria-hidden="true"></div>'
            . '<h2>Sin decisiones pendientes</h2>'
            . '<p>Cuando una puerta humana real requiera tu atención, aparecerá aquí.</p>'
            . '</article>';
    }

    private static function reauthBanner(): string
    {
        return '<aside class="reauth" role="status" aria-label="Reautenticación requerida">'
            . '<strong>Reautenticación requerida</strong>'
            . '<span>Confirma tu identidad para habilitar las acciones.</span>'
            . '</aside>';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function styles(): string
    {
        return <<<'CSS'
:root {
  color-scheme: dark;
  --bg: #05090d;
  --panel: #0a1218;
  --line: #1a6372;
  --cyan: #58e6ff;
  --cyan-soft: #b6f5ff;
  --green: #73f5ab;
  --amber: #ffd36b;
  --muted: #91a9b2;
  --text: #edfaff;
}
* { box-sizing: border-box; }
html { background: var(--bg); }
body {
  margin: 0;
  min-height: 100vh;
  color: var(--text);
  font-family: Rajdhani, Inter, system-ui, sans-serif;
  background:
    linear-gradient(rgba(88,230,255,.035) 1px, transparent 1px),
    linear-gradient(90deg, rgba(88,230,255,.035) 1px, transparent 1px),
    radial-gradient(circle at 50% -20%, rgba(30,184,220,.2), transparent 45%),
    var(--bg);
  background-size: 32px 32px, 32px 32px, auto, auto;
}
.shell { width: min(100%, 1120px); margin: 0 auto; padding: 24px 16px 48px; }
.topbar { padding: 12px 2px 20px; }
.eyebrow, .meta, .sha, code {
  font-family: "JetBrains Mono", ui-monospace, SFMono-Regular, monospace;
}
.eyebrow { color: var(--cyan); letter-spacing: .16em; font-size: .75rem; }
h1, h2 { font-family: Orbitron, Rajdhani, system-ui, sans-serif; margin: 0; }
h1 { font-size: clamp(1.9rem, 10vw, 3.4rem); letter-spacing: .03em; }
.lede { color: var(--muted); max-width: 64ch; line-height: 1.55; }
.decision-grid { display: grid; gap: 16px; }
.panel {
  position: relative;
  overflow: hidden;
  border: 1px solid var(--line);
  border-radius: 14px;
  background: linear-gradient(145deg, rgba(14,31,40,.96), rgba(7,15,21,.98));
  box-shadow: inset 0 0 26px rgba(88,230,255,.035), 0 14px 40px rgba(0,0,0,.28);
  padding: 20px;
}
.panel-line {
  position: absolute;
  inset: 0 auto auto 0;
  width: 42%;
  height: 2px;
  background: linear-gradient(90deg, var(--cyan), transparent);
  box-shadow: 0 0 14px rgba(88,230,255,.7);
}
.meta { display: flex; justify-content: space-between; gap: 12px; color: var(--cyan); font-size: .78rem; }
.decision-card h2 { margin-top: 14px; font-size: 1.35rem; }
.context { color: var(--cyan-soft); line-height: 1.5; }
.sha { display: grid; gap: 5px; color: var(--muted); font-size: .72rem; }
.sha code { overflow-wrap: anywhere; color: var(--text); }
.explain, .safe-default, .blocker { line-height: 1.45; color: var(--cyan-soft); }
.safe-default { border-left: 3px solid var(--amber); padding-left: 12px; }
.technical { margin-top: 10px; color: var(--muted); }
.technical summary { cursor: pointer; min-height: 44px; display: flex; align-items: center; }
.technical summary:focus-visible { outline: 3px solid var(--amber); outline-offset: 2px; }
.actions { display: grid; gap: 12px; margin-top: 18px; }
.decision-option { display: grid; gap: 8px; }
.option-copy { display: grid; gap: 7px; min-width: 0; overflow-wrap: anywhere; padding: 0 14px 4px; }
.option-label { font-weight: 800; }
.effect { color: var(--cyan-soft); font-size: .93rem; line-height: 1.4; }
.impact { font-size: .83rem; color: var(--amber); line-height: 1.4; }
.tradeoffs { display: grid; gap: 8px; font-size: .82rem; }
.tradeoffs .pros, .tradeoffs .cons { display: grid; gap: 3px; }
.tradeoffs strong { color: var(--cyan); }
.decision-action {
  min-height: 52px;
  width: 100%;
  border: 1px solid #357d8d;
  border-radius: 10px;
  background: rgba(8,28,35,.92);
  color: var(--text);
  font: 700 1rem/1.2 Rajdhani, system-ui, sans-serif;
  padding: 12px 14px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  text-align: left;
  cursor: pointer;
}
.decision-action.recommended { border-color: var(--cyan); box-shadow: 0 0 0 1px rgba(88,230,255,.18) inset; }
.decision-action:hover:not(:disabled) { background: rgba(14,51,62,.96); }
.decision-action:focus-visible { outline: 3px solid var(--amber); outline-offset: 3px; }
.decision-action:disabled { opacity: .5; cursor: not-allowed; }
.badge {
  flex: none;
  color: #031014;
  background: var(--green);
  border-radius: 999px;
  padding: 4px 8px;
  font: 800 .65rem/1 "JetBrains Mono", ui-monospace, monospace;
}
.reauth {
  margin: 0 0 16px;
  border: 1px solid #9e7524;
  background: rgba(92,61,12,.28);
  color: #ffe8ad;
  border-radius: 12px;
  padding: 14px 16px;
  display: grid;
  gap: 4px;
}
.empty-state { min-height: 260px; display: grid; place-items: center; text-align: center; align-content: center; }
.empty-state p { color: var(--muted); max-width: 34ch; }
.status-orb {
  width: 72px;
  height: 72px;
  border: 2px solid var(--cyan);
  border-radius: 50%;
  box-shadow: 0 0 25px rgba(88,230,255,.35), inset 0 0 18px rgba(88,230,255,.2);
  animation: pulse 3s ease-in-out infinite;
}
@keyframes pulse { 50% { transform: scale(1.05); opacity: .75; } }
@media (min-width: 760px) {
  .shell { padding: 40px 28px 64px; }
  .decision-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after { animation: none !important; scroll-behavior: auto !important; transition: none !important; }
}
CSS;
    }
}
