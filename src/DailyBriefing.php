<?php
declare(strict_types=1);

namespace ControlBot\Briefing;

use InvalidArgumentException;

final class DailyBriefing
{
    private const SECTIONS = ['delivered', 'today', 'broken', 'decisions', 'spend'];
    private const MAX_ITEMS_PER_SECTION = 4;
    private const MAX_ITEMS_TOTAL = 12;
    private const MAX_TEXT_LENGTH = 160;

    /** @var array<string, true> */
    private array $allowedHosts = [];

    public function __construct(array $allowedHosts)
    {
        if ($allowedHosts === [] || count($allowedHosts) > 20) {
            throw new InvalidArgumentException('Allowlist de evidencia inválida.');
        }

        foreach ($allowedHosts as $host) {
            if (
                !is_string($host)
                || $host === ''
                || strlen($host) > 253
                || preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/', $host) !== 1
            ) {
                throw new InvalidArgumentException('Host de evidencia inválido.');
            }
            $this->allowedHosts[strtolower($host)] = true;
        }
    }

    public function build(array $facts): array
    {
        $unknown = array_diff(array_keys($facts), self::SECTIONS);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Sección de briefing no permitida.');
        }

        $sections = [];
        $attentionRequired = false;
        $total = 0;

        foreach (self::SECTIONS as $section) {
            $rawItems = $facts[$section] ?? [];
            if (!is_array($rawItems) || !array_is_list($rawItems)) {
                throw new InvalidArgumentException("Sección {$section} inválida.");
            }
            if (count($rawItems) > self::MAX_ITEMS_PER_SECTION) {
                throw new InvalidArgumentException("Sección {$section} excede el límite.");
            }

            $items = [];
            foreach ($rawItems as $rawItem) {
                $item = $this->normalizeItem($rawItem);
                $items[] = $item;
                $attentionRequired = $attentionRequired || $item['requires_attention'];
                $total++;
                if ($total > self::MAX_ITEMS_TOTAL) {
                    throw new InvalidArgumentException('El briefing excede el límite de lectura.');
                }
            }

            $sections[$section] = [
                'empty' => $items === [],
                'items' => $items,
            ];
        }

        return [
            'attention_required' => $attentionRequired,
            'owner_action' => $attentionRequired ? 'Necesitas entrar' : 'No necesitas entrar',
            'sections' => $sections,
        ];
    }

    public function renderHtml(array $briefing): string
    {
        $this->assertBuiltBriefing($briefing);

        $labels = [
            'delivered' => 'Entregado ayer',
            'today' => 'Hoy',
            'broken' => 'Roto',
            'decisions' => 'Decisiones',
            'spend' => 'Gasto IA / CI',
        ];

        $sectionsHtml = '';
        foreach (self::SECTIONS as $section) {
            $payload = $briefing['sections'][$section];
            $content = '<p class="empty">Sin novedades con evidencia.</p>';

            if (!$payload['empty']) {
                $rows = '';
                foreach ($payload['items'] as $item) {
                    $rows .= '<li>'
                        . '<span>' . self::e($item['text']) . '</span>'
                        . '<a href="' . self::e($item['evidence']) . '" rel="noopener noreferrer">Ver evidencia</a>'
                        . '</li>';
                }
                $content = '<ul>' . $rows . '</ul>';
            }

            $sectionsHtml .= '<section data-section="' . $section . '">'
                . '<h2>' . self::e($labels[$section]) . '</h2>'
                . $content
                . '</section>';
        }

        return '<article class="daily-briefing" aria-labelledby="briefing-title">'
            . '<header><p>Resumen diario</p><h1 id="briefing-title">Tu minuto de control</h1>'
            . '<strong class="owner-action">' . self::e($briefing['owner_action']) . '</strong></header>'
            . $sectionsHtml
            . '</article>';
    }

    private function normalizeItem(mixed $rawItem): array
    {
        if (!is_array($rawItem) || array_is_list($rawItem)) {
            throw new InvalidArgumentException('Item de briefing inválido.');
        }
        $allowedKeys = ['text', 'evidence', 'requires_attention'];
        if (array_diff(array_keys($rawItem), $allowedKeys) !== []) {
            throw new InvalidArgumentException('Campo de briefing no permitido.');
        }

        $text = $rawItem['text'] ?? null;
        $evidence = $rawItem['evidence'] ?? null;
        $requiresAttention = $rawItem['requires_attention'] ?? false;

        if (!is_string($text) || trim($text) === '' || strlen($text) > self::MAX_TEXT_LENGTH) {
            throw new InvalidArgumentException('Texto de briefing inválido.');
        }
        if (!is_bool($requiresAttention)) {
            throw new InvalidArgumentException('Flag de atención inválido.');
        }
        if (!is_string($evidence)) {
            throw new InvalidArgumentException('Evidencia de briefing inválida.');
        }

        $parts = parse_url($evidence);
        $host = is_array($parts) && is_string($parts['host'] ?? null)
            ? strtolower($parts['host'])
            : '';
        if (
            !is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || $host === ''
            || !isset($this->allowedHosts[$host])
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new InvalidArgumentException('Evidencia fuera de allowlist.');
        }

        return [
            'text' => trim($text),
            'evidence' => $evidence,
            'requires_attention' => $requiresAttention,
        ];
    }

    private function assertBuiltBriefing(array $briefing): void
    {
        if (
            !isset($briefing['attention_required'], $briefing['owner_action'], $briefing['sections'])
            || !is_bool($briefing['attention_required'])
            || !is_string($briefing['owner_action'])
            || !is_array($briefing['sections'])
            || array_keys($briefing['sections']) !== self::SECTIONS
        ) {
            throw new InvalidArgumentException('Briefing estructurado inválido.');
        }

        $expectedAction = $briefing['attention_required'] ? 'Necesitas entrar' : 'No necesitas entrar';
        if (!hash_equals($expectedAction, $briefing['owner_action'])) {
            throw new InvalidArgumentException('Acción del dueño inconsistente.');
        }
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
