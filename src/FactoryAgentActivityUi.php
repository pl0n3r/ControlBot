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
            $age = static fn ($value): string => self::ageLabel($value);
            $source = $project['source_ref'] ?? null;
            if ($source !== null && $source !== 'https://api.github.com/repos/' . $repo . '/issues') {
                throw new InvalidArgumentException('Activity source invalid.');
            }
            $observed = $project['observed_at'] ?? null;
            $sourceAge = $project['source_age_seconds'] ?? null;
            $exactAge = is_int($sourceAge) && $sourceAge >= 0 ? (string) $sourceAge : 'UNKNOWN';
            $exactTime = $count($observed);
            $details = 'Fuente: ' . ($source ?? 'UNKNOWN') . ' · timestamp=' . $exactTime
                . ' · antigüedad=' . $exactAge . 's · freshness=' . $fresh . ' · estado=' . $state;
            $freshLabel = ['current' => 'Vigente', 'stale' => 'Desactualizado', 'unknown' => 'Sin evidencia'][$fresh];
            $stateLabel = ['OBSERVED' => 'Observado', 'STALE' => 'Desactualizado', 'UNKNOWN' => 'Desconocido'][$state];
            $symbol = ['OBSERVED' => '●', 'STALE' => '◐', 'UNKNOWN' => '?'][$state];
            $cards .= '<article class="front" data-activity-repository="' . self::e($repo)
                . '" data-freshness="' . self::e($fresh) . '" data-source-age-seconds="'
                . self::e($exactAge) . '" data-observed-at="' . self::e($exactTime) . '">'
                . '<h3>' . self::e($repo) . '</h3>'
                . '<p>Disponibles: ' . $count($project['available'] ?? null) . '</p>'
                . '<p>Reservados: ' . $count($project['reserved'] ?? null) . '</p>'
                . '<p>Planificados (no despachables): ' . $count($project['planned'] ?? null) . '</p>'
                . '<p>Bloqueados: ' . $count($project['blocked'] ?? null) . '</p>'
                . '<p>PR abiertos: ' . $count($project['open_prs'] ?? null) . '</p>'
                . '<p>Última señal GitHub: ' . self::e($age($project['last_signal_age_seconds'] ?? null))
                . ' (' . self::e($lastKind ?? 'UNKNOWN') . ')</p>'
                . '<small title="' . self::e($details) . '"><span aria-hidden="true">'
                . $symbol . '</span> ' . self::e($stateLabel) . ' · ' . self::e($freshLabel)
                . ' · ' . self::e($age($sourceAge)) . '</small>'
                . ($project['queue_empty'] === true ? '<p class="activity-warning">Cola vacía</p>' : '')
                . '</article>';
        }
        return '<section class="panel" data-section="agent_activity" aria-labelledby="agent-activity-title">'
            . '<h2 id="agent-activity-title">Actividad de agentes</h2>'
            . '<p>Señales leídas de GitHub; una actualización no demuestra que un agente siga conectado.</p>'
            . $alert . '<div class="front-grid">' . $cards . '</div></section>';
    }
    private static function ageLabel(mixed $seconds): string
    {
        if (!is_int($seconds) || $seconds < 0) return 'sin evidencia de antigüedad';
        if ($seconds < 60) return 'hace ' . $seconds . ' s';
        if ($seconds < 3600) return 'hace ' . intdiv($seconds, 60) . ' min';
        if ($seconds < 86400) return 'hace ' . intdiv($seconds, 3600) . ' h';
        return 'hace ' . intdiv($seconds, 86400) . ' d';
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