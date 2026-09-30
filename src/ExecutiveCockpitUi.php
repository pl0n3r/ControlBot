<?php
declare(strict_types=1);

namespace ControlBot\Business;

require_once __DIR__.'/UiTheme.php';

use ControlBot\Ui\UiTheme;
use InvalidArgumentException;

final class ExecutiveCockpitUi
{
    private const HEALTH=['healthy','degraded','critical','unknown'];
    private const FRESH=['current','stale','unknown'];
    private const CLASSES=['fyi','watch','decision','critical'];
    private const FIELD_FRESHNESS='freshness';
    private const FIELD_SOURCE_REF='source_ref';
    private const FIELD_OBSERVED_AT='observed_at';
    private const OPTIONAL_PROJECTIONS = [
        'finance' => [
            'finance',
            [
                'period', 'currency', 'net_revenue', 'gross_profit', 'operating_result',
                'cash_in', 'cash_out', 'customers', 'transactions', self::FIELD_FRESHNESS,
                'confidence', self::FIELD_SOURCE_REF, self::FIELD_OBSERVED_AT,
            ],
        ],
        'product_health' => [
            'product health',
            ['product_id', 'surface', 'period', self::FIELD_FRESHNESS, 'reasons', 'dimension_count'],
        ],
        'infrastructure' => [
            'infrastructure',
            [
                'resource_id', 'kind', 'state', self::FIELD_FRESHNESS,
                self::FIELD_SOURCE_REF, self::FIELD_OBSERVED_AT, 'incident_count',
            ],
        ],
        'runtime' => [
            'runtime',
            [
                'source', 'provider_id', 'state', self::FIELD_OBSERVED_AT, 'heartbeat_at',
                'total_capacity', 'occupied_capacity', 'assignment_ref',
            ],
        ],
    ];
    private const SENSITIVE='/(?:password|passwd|secret|token|cookie|authorization|bearer|private[_ -]?key|api[_ -]?key|dsn)/i';
    private const PII='/(?:[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}|\+?(?=(?:[0-9(). -]*[0-9]){10})[0-9][0-9(). -]{7,}[0-9])/i';

    public static function render(array $cockpit, array $inbox): string
    {
        self::fields($cockpit, ['version', 'group_id', 'ventures'], 'cockpit');
        self::fields($inbox, ['version', 'entries'], 'inbox');
        if (
            $cockpit['version'] !== 1
            || $inbox['version'] !== 1
            || !array_is_list($cockpit['ventures'])
            || !array_is_list($inbox['entries'])
        ) {
            throw new InvalidArgumentException('Executive Cockpit UI input invalid.');
        }
        self::safe($cockpit);
        self::safe($inbox);

        $ventures = [];
        $seen = [];
        foreach ($cockpit['ventures'] as $row) {
            self::fields(
                $row,
                [
                    'venture', 'business_health', 'technical_health', 'finance',
                    'product_health', 'infrastructure', 'runtime', 'owner_inbox_counts',
                ],
                'venture row',
            );
            self::fields(
                $row['venture'],
                ['venture_id', 'group_id', 'title', 'state', 'strategy_role', 'responsible'],
                'venture',
            );
            self::projectionFields($row);
            $id = self::ventureId($row['venture']['venture_id']);
            if (isset($seen[$id]) || $row['venture']['group_id'] !== $cockpit['group_id']) {
                throw new InvalidArgumentException('Venture scope invalid.');
            }
            $seen[$id] = true;
            self::health($row['business_health']);
            self::health($row['technical_health']);
            $ventures[] = $row;
        }
        usort(
            $ventures,
            static fn (array $a, array $b): int => $a['venture']['venture_id'] <=> $b['venture']['venture_id'],
        );
        $entries = self::entries($inbox['entries']);

        return '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
            .'<title>ControlBot · Executive Cockpit</title><style>'.self::styles().'</style></head><body>'
            .'<header class="top"><p class="eyebrow"><span>CONTROLBOT</span> / EXECUTIVE COCKPIT</p>'
            .'<h1>Dirección por excepción</h1><p>Salud de negocio y técnica separadas. UNKNOWN y stale permanecen explícitos.</p></header>'
            .self::nav($ventures).'<main>'.self::inboxHtml($entries).self::venturesHtml($ventures,$entries).'</main>'
            .'</body></html>';
    }

    private static function nav(array $rows): string
    {
        $links = '';
        foreach ($rows as $row) {
            $id = $row['venture']['venture_id'];
            $links .= '<a href="#'
                . self::e(self::anchor($id))
                . '">'
                . self::e($row['venture']['title'])
                . '</a>';
        }
        return '<nav aria-label="Ventures">' . $links . '</nav>';
    }

    private static function inboxHtml(array $entries): string
    {
        $html='<section aria-labelledby="inbox-title"><p class="eyebrow">OWNER INBOX</p><h2 id="inbox-title">Excepciones materiales</h2><div class="inbox-grid">';
        foreach (self::CLASSES as $class) {
            $cards = '';
            foreach ($entries as $entry) {
                if ($entry['class'] === $class) {
                    $cards .= self::entryHtml($entry);
                }
            }
            $html .= '<section class="inbox-class" data-inbox-class="'
                . $class . '" aria-label="' . strtoupper($class) . '"><h3>'
                . strtoupper($class) . '</h3>'
                . ($cards ?: '<p class="empty">Sin entradas.</p>') . '</section>';
        }
        return $html . '</div></section>';
    }

    private static function entryHtml(array $e): string
    {
        $decision = '';
        if (in_array($e['class'], ['decision', 'critical'], true)) {
            $decision = '<dl><div><dt>Authority</dt><dd>'
                . self::e($e['required_authority_level'] ?? 'unknown') . '</dd></div>'
                . '<div><dt>Decision</dt><dd>'
                . self::e($e['decision_ref'] ?? 'none') . '</dd></div>'
                . '<div><dt>Options</dt><dd>'
                . self::e($e['options_ref'] ?? 'none') . '</dd></div>'
                . '<div><dt>Deadline</dt><dd>'
                . self::e(self::value($e['deadline_at'])) . '</dd></div></dl>';
        }
        return '<article class="entry class-' . $e['class'] . '"><h4>'
            . self::e($e['title']) . '</h4><p>' . self::e($e['summary']) . '</p>'
            . '<p class="meta">Scope ' . self::e($e['scope']['ref'])
            . ' · freshness ' . self::e($e['freshness']) . '</p>' . $decision . '</article>';
    }

    private static function venturesHtml(array $rows, array $entries): string
    {
        $html = '<section aria-labelledby="ventures-title"><p class="eyebrow">VENTURES</p>'
            . '<h2 id="ventures-title">Salud por Venture</h2><div class="venture-grid">';
        foreach ($rows as $row) {
            $v = $row['venture'];
            $id = $v['venture_id'];
            $responsible = $v['responsible'];
            $runtime = $row['runtime'];
            $scoped = array_values(array_filter(
                $entries,
                static fn (array $e): bool =>
                    $e['scope']['kind'] === 'venture'
                    && $e['scope']['ref'] === 'controlbot:venture/' . $id,
            ));
            $html .= '<article class="venture-card" id="' . self::e(self::anchor($id))
                . '"><header><p class="eyebrow">' . self::e($id) . '</p><h3>'
                . self::e($v['title']) . '</h3></header>'
                . '<div class="health-grid">'
                . self::healthHtml('Business health', $row['business_health'])
                . self::healthHtml('Technical health', $row['technical_health']) . '</div>'
                . '<dl><div><dt>Responsable</dt><dd>' . self::e($responsible['identity_id'])
                . ' · ' . self::e($responsible['kind']) . ' · ' . self::e($responsible['state'])
                . '</dd></div>'
                . '<div><dt>Runtime</dt><dd>'
                . self::e($runtime === null ? 'unknown' : $runtime['state']) . '</dd></div>'
                . '<div><dt>Runtime source</dt><dd>'
                . self::e($runtime === null ? 'unknown' : $runtime['source'] . ' / ' . $runtime['provider_id'])
                . '</dd></div>'
                . '<div><dt>Asignación</dt><dd>'
                . self::e($runtime === null ? 'none' : ($runtime['assignment_ref'] ?? 'none'))
                . '</dd></div>'
                . '<div><dt>Inbox</dt><dd>' . count($scoped) . ' entradas</dd></div></dl>'
                . self::dimensionHtml('Finance', $row['finance'])
                . self::dimensionHtml('Product', $row['product_health'])
                . self::dimensionHtml('Infrastructure', $row['infrastructure'])
                . ($scoped
                    ? '<div class="venture-inbox"><h4>Excepciones del Venture</h4>'
                        . implode('', array_map([self::class, 'entryHtml'], $scoped)) . '</div>'
                    : '')
                . '</article>';
        }
        return $html . '</div></section>';
    }

    private static function healthHtml(string $label, array $h): string
    {
        return '<section class="health health-' . self::e($h['state'])
            . ' fresh-' . self::e($h['freshness']) . '"><h4>' . self::e($label) . '</h4>'
            . '<strong>' . self::e($h['state']) . '</strong><span>freshness '
            . self::e($h['freshness']) . '</span>'
            . '<code>' . self::e($h['source_ref'] ?? 'unknown') . '</code></section>';
    }

    private static function dimensionHtml(string $label, ?array $d): string
    {
        if ($d === null) {
            return '<section class="dimension"><h4>' . self::e($label) . '</h4><p>unknown</p></section>';
        }
        $fresh = $d['freshness'] ?? 'not-declared';
        $source = $d['source_ref'] ?? 'unknown';
        $observed = $d['observed_at'] ?? 'unknown';
        $summary = [];
        $keys = [
            'currency', 'net_revenue', 'gross_profit', 'operating_result',
            'product_id', 'state', 'incident_count', 'dimension_count',
        ];
        foreach ($keys as $k) {
            if (array_key_exists($k, $d)) {
                $summary[] = $k . '=' . self::value($d[$k]);
            }
        }
        return '<section class="dimension"><h4>' . self::e($label) . '</h4><p>'
            . self::e(implode(' · ', $summary) ?: 'available')
            . '</p><p class="meta">freshness ' . self::e((string) $fresh)
            . ' · source ' . self::e(self::value($source))
            . ' · observed ' . self::e(self::value($observed)) . '</p></section>';
    }

    private static function entries(array $rows): array
    {
        $out = [];
        $seen = [];
        foreach ($rows as $e) {
            self::fields(
                $e,
                [
                    'version', 'entry_ref', 'class', 'scope', 'title', 'summary', 'impact',
                    'actor_ref', 'required_authority_level', 'decision_ref', 'options_ref',
                    'deadline_at', 'source_ref', 'evidence_refs', 'observed_at', 'freshness',
                ],
                'inbox entry',
            );
            self::fields($e['scope'], ['kind', 'ref'], 'inbox scope');
            if (
                $e['version'] !== 1
                || !in_array($e['class'], self::CLASSES, true)
                || !in_array($e['freshness'], self::FRESH, true)
                || isset($seen[$e['entry_ref']])
            ) {
                throw new InvalidArgumentException('Owner Inbox UI entry invalid.');
            }
            $seen[$e['entry_ref']] = true;
            $out[] = $e;
        }
        return $out;
    }

    private static function health(array $h): void
    {
        self::fields($h, ['state', 'freshness', 'source_ref', 'observed_at'], 'health');
        if (
            !in_array($h['state'], self::HEALTH, true)
            || !in_array($h['freshness'], self::FRESH, true)
            || ($h['freshness'] !== 'current' && $h['state'] === 'healthy')
        ) {
            throw new InvalidArgumentException('Health UI input invalid.');
        }
    }

    private static function projectionFields(array $row): void
    {
        self::fields(
            $row['venture']['responsible'],
            ['identity_id', 'kind', 'state', self::FIELD_SOURCE_REF, self::FIELD_OBSERVED_AT],
            'venture responsible',
        );
        self::fields($row['owner_inbox_counts'], self::CLASSES, 'owner inbox counts');
        foreach (self::OPTIONAL_PROJECTIONS as $key => [$label, $expected]) {
            self::optionalFields($row[$key], $expected, $label);
        }
        if ($row['product_health'] !== null) {
            self::fields(
                $row['product_health']['period'],
                ['start_at', 'end_at'],
                'product health period',
            );
        }
    }

    private static function ventureId(mixed $v): string
    {
        if (is_string($v) && preg_match('/^venture-[a-z0-9][a-z0-9-]{1,79}$/D', $v) === 1) {
            return $v;
        }
        throw new InvalidArgumentException('venture_id invalid.');
    }

    private static function anchor(string $id): string
    {
        return 'venture-' . $id;
    }

    private static function value(mixed $v): string
    {
        return $v === null ? 'unknown' : (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v);
    }

    private static function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function safe(mixed $v): void
    {
        if (is_array($v)) {
            foreach ($v as $x) {
                self::safe($x);
            }
            return;
        }
        if (
            is_string($v)
            && (preg_match(self::SENSITIVE, $v) === 1 || preg_match(self::PII, $v) === 1)
        ) {
            throw new InvalidArgumentException('Sensitive UI input.');
        }
    }

    private static function fields(mixed $row, array $expected, string $label): void
    {
        if (!is_array($row) || array_is_list($row)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        $actual = array_keys($row);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new InvalidArgumentException($label . ' fields invalid.');
        }
    }
    private static function optionalFields(mixed $row, array $expected, string $label): void
    {
        if ($row !== null) {
            self::fields($row, $expected, $label);
        }
    }

    private static function styles(): string
    {
        return UiTheme::tokensCss().<<<'CSS'
*{box-sizing:border-box}html{background:var(--bg);scroll-behavior:smooth}body{margin:0;color:var(--text);background:var(--bg);font-family:Inter,system-ui,sans-serif}.top,main,nav{width:min(100%,1180px);margin:auto;padding-inline:16px}.top{padding-top:28px}.top p{color:var(--muted);max-width:70ch}.eyebrow{margin:0;color:var(--muted);letter-spacing:.08em;font:700 .72rem/1.2 ui-monospace,monospace}.eyebrow span{color:var(--cyan)}h1{font-size:clamp(2rem,8vw,3.4rem);margin:.4rem 0}h2{margin:.5rem 0 1rem}h3,h4{margin:.35rem 0}nav{display:flex;gap:8px;overflow:auto;padding-block:14px}nav a{color:var(--cyan);border:1px solid var(--line);border-radius:999px;padding:8px 10px;text-decoration:none;white-space:nowrap}main{padding-bottom:56px}.inbox-grid,.venture-grid,.health-grid{display:grid;grid-template-columns:1fr;gap:12px}.inbox-class,.venture-card,.health,.dimension,.entry{border:1px solid var(--line);background:var(--panel);border-radius:10px;padding:14px}.inbox-class{min-width:0}.entry{margin-top:10px;background:var(--panel-raised)}.entry p,.meta,code{color:var(--muted);overflow-wrap:anywhere}.class-critical{border-color:var(--red)}.class-decision{border-color:var(--amber)}.health{display:grid;gap:5px}.health-healthy.fresh-current strong{color:var(--green)}.health-degraded strong,.fresh-stale strong{color:var(--amber)}.health-critical strong{color:var(--red)}.health-unknown strong,.fresh-unknown strong{color:var(--muted)}dl{margin:12px 0;display:grid;gap:7px}dl div{display:flex;justify-content:space-between;gap:12px;border-top:1px solid var(--line);padding-top:7px}dt{color:var(--muted)}dd{margin:0;text-align:right;overflow-wrap:anywhere}.dimension{margin-top:8px}.venture-inbox{margin-top:14px}.empty{color:var(--muted)}@media(min-width:760px){.top,main,nav{padding-inline:28px}.inbox-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.venture-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.health-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation:none!important;transition:none!important;scroll-behavior:auto!important}}
CSS;
    }
}
