<?php
declare(strict_types=1);
namespace ControlBot\Business;
use InvalidArgumentException;
/** Private, accessible, read-only HTML; never projects raw GitHub text. */
final class FactoryAgentActivityUi
{
    public static function renderSection(?array $view): string
    {
        if ($view === null) {
            return '<section class="panel" data-section="agent_activity"><h2>Actividad de agentes</h2>'
                . '<p role="status">UNKNOWN — sin evidencia GitHub completa.</p></section>';
        }
        if (($view['version'] ?? null) !== 1 || ($view['read_only'] ?? null) !== true ||
            !is_array($view['projects'] ?? null) || !array_is_list($view['projects']) ||
            count($view['projects']) !== 7 || !array_key_exists('queue_empty', $view)) {
            throw new InvalidArgumentException('Agent activity UI payload invalid.');
        }
        $alert = $view['queue_empty'] === true
            ? '<p role="status" class="activity-warning">Cola vacía: cero disponibles verificados; hay trabajo planificado desbloqueable.</p>'
            : ($view['queue_empty'] === null
                ? '<p role="status">Estado de cola: UNKNOWN — evidencia insuficiente.</p>' : '');
        $cards = '';
        foreach ($view['projects'] as $project) {
            $repo = self::safeRepo($project['repository_ref'] ?? null);
            $fresh = self::choice($project['freshness'] ?? null, ['current', 'stale', 'unknown']);
            $state = self::choice($project['activity_state'] ?? null, ['OBSERVED', 'UNKNOWN', 'STALE']);
            $lastKind = $project['last_signal_kind'] ?? null;
            if ($lastKind !== null) self::choice($lastKind, ['issue', 'pr', 'coordination']);
            $count = static fn ($value): string => is_int($value) && $value >= 0 ? (string)$value : 'UNKNOWN';
            $age = static fn ($value): string => is_int($value) && $value >= 0 ? $value . 's' : 'UNKNOWN';
            $source = $project['source_ref'] ?? null;
            if ($source !== null && $source !== 'https://api.github.com/repos/' . $repo . '/issues') {
                throw new InvalidArgumentException('Activity source invalid.');
            }
            $cards .= '<article class="front" data-activity-repository="' . self::e($repo) . '">'
                . '<h3>' . self::e($repo) . '</h3>'
                . '<p>Disponibles: ' . $count($project['available'] ?? null) . '</p>'
                . '<p>Reservados: ' . $count($project['reserved'] ?? null) . '</p>'
                . '<p>PR abiertos: ' . $count($project['open_prs'] ?? null) . '</p>'
                . '<p>Última señal GitHub: ' . $age($project['last_signal_age_seconds'] ?? null)
                . ' (' . self::e($lastKind ?? 'UNKNOWN') . ')</p>'
                . '<small>Fuente: ' . self::e($source ?? 'UNKNOWN')
                . ' · timestamp=' . $count($project['observed_at'] ?? null)
                . ' · antigüedad=' . $age($project['source_age_seconds'] ?? null)
                . ' · freshness=' . self::e($fresh)
                . ' · estado=' . self::e($state) . '</small>'
                . ($project['queue_empty'] === true ? '<p class="activity-warning">Cola vacía</p>' : '')
                . '</article>';
        }
        return '<section class="panel" data-section="agent_activity" aria-labelledby="agent-activity-title">'
            . '<h2 id="agent-activity-title">Actividad de agentes</h2>'
            . '<p>Señales leídas de GitHub; una actualización no demuestra que un agente siga conectado.</p>'
            . $alert . '<div class="front-grid">' . $cards . '</div></section>';
    }
    private static function safeRepo(mixed $value): string
    {
        $allowed = ['Factory', 'Condor', 'GrindFlow', 'brvtal', 'ControlBot', 'AutoFactory', 'FactoryRunner'];
        if (!is_string($value) || !in_array($value, array_map(static fn ($name) => 'pl0n3r/' . $name, $allowed), true)) {
            throw new InvalidArgumentException('Activity repository invalid.');
        }
        return $value;
    }
    private static function choice(mixed $value, array $allowed): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException('Activity state invalid.');
        }
        return $value;
    }
    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}