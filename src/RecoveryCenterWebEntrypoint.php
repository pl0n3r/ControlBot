<?php
declare(strict_types=1);

namespace ControlBot\Web;

use ControlBot\Security\RecoveryCenterUi;
use ControlBot\Ui\ControlCenterShell;
use Throwable;

require_once __DIR__ . '/InfrastructureProvider.php';
require_once __DIR__ . '/RecoveryProfile.php';
require_once __DIR__ . '/BackupReceipt.php';
require_once __DIR__ . '/RecoveryEvidence.php';
require_once __DIR__ . '/RecoveryDrillProjection.php';
require_once __DIR__ . '/RecoveryCenterUi.php';
require_once __DIR__ . '/ControlCenterShell.php';

/** Called only after ControlBotWebEntrypoint::handleAuthenticated verified owner + TOTP. */
final class RecoveryCenterWebEntrypoint
{
    public static function handle(array $server, ?string $profilePath = null): array
    {
        if (($server['REQUEST_METHOD'] ?? null) !== 'GET') {
            return self::response(405, "Method not allowed.\n", ['Allow' => 'GET']);
        }
        try {
            // Local versioned policy is never proof of live backups or Factory Health.
            $profilePath ??= dirname(__DIR__) . '/config/recovery/controlbot.json';
            if ($profilePath === '' || $profilePath[0] !== DIRECTORY_SEPARATOR
                || str_contains($profilePath, "\0") || !is_file($profilePath)) {
                throw new \RuntimeException('Recovery profile unavailable');
            }
            $bytes = filesize($profilePath);
            if (!is_int($bytes) || $bytes < 2 || $bytes > 32768) {
                throw new \RuntimeException('Recovery profile size invalid');
            }
            $raw = file_get_contents($profilePath);
            if (!is_string($raw) || strlen($raw) !== $bytes) {
                throw new \RuntimeException('Recovery profile read failed');
            }
            $profile = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($profile) || array_is_list($profile)) {
                throw new \RuntimeException('Recovery profile invalid');
            }
            // No evidence, receipt or Factory Recovery Health may be inferred.
            $projection = RecoveryCenterUi::project($profile, null, null, null, null);
            $html = self::render($projection);
            return self::response(200, $html, [], 'text/html; charset=utf-8');
        } catch (Throwable) {
            return self::response(503, "Service unavailable.\n");
        }
    }

    private static function render(array $projection): string
    {
        if (($projection['execution'] ?? null) !== false
            || ($projection['actions'] ?? null) !== []
            || ($projection['dr_status'] ?? null) !== 'UNKNOWN'
            || ($projection['reasons'] ?? null) !== ['RECOVERY_HEALTH_MISSING']) {
            throw new \RuntimeException('Recovery status authority unavailable');
        }
        $targets = $projection['profile'];
        $content = '<main class="recovery" aria-labelledby="recovery-title">'
            . '<p>Security Center / Resilience</p>'
            . '<h1 id="recovery-title">Recuperación ante desastres</h1>'
            . '<p role="status">Estado de Recovery: <strong>' . self::e($projection['dr_status']) . '</strong></p>'
            . '<p>Sin evidencia Factory Recovery Health. Este estado no acredita copias de seguridad ni restores.</p>'
            . '<dl><dt>Proyecto</dt><dd>' . self::e($projection['project_ref']) . '</dd>'
            . '<dt>RPO objetivo</dt><dd>' . self::e((string) $targets['rpo_target_minutes']) . ' min</dd>'
            . '<dt>RTO objetivo</dt><dd>' . self::e((string) $targets['rto_target_minutes']) . ' min</dd>'
            . '<dt>Restore drill</dt><dd>' . self::e((string) $projection['restore_drill']['status']) . '</dd></dl>';
        foreach (['database_backup' => 'Copia de base de datos', 'offsite_copy' => 'Copia externa',
                     'immutable_copy' => 'Copia inmutable', 'media_versioning' => 'Versionado de medios'] as $key => $label) {
            $state = $projection['signals'][$key]['state'] ?? 'unknown';
            $content .= '<p>' . self::e($label) . ': ' . self::e((string) $state) . '</p>';
        }
        $content .= '<p>Fuente de salud: ' . self::e((string) $projection['health']['source'])
            . '. Los valores anteriores son objetivos o desconocidos; nunca mediciones reales.</p></main>';
        return ControlCenterShell::render('Resilience', 'overview',
            ['overview' => '/overview', 'github' => '/github'], $content);
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function response(int $status, string $body, array $extra = [], string $type = 'text/plain; charset=utf-8'): array
    {
        return ['status' => $status, 'headers' => $extra + [
            'Content-Type' => $type,
            'Cache-Control' => 'no-store',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
        ], 'body' => $body];
    }
}
