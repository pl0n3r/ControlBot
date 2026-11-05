<?php
declare(strict_types=1);

namespace ControlBot\Web;

use ControlBot\Business\FactoryOrchestratorWebEntrypoint;
use ControlBot\GitHub\GitHubProjectEntrypoint;
use ControlBot\GitHub\GitHubPublicEntrypoint;
use ControlBot\Ui\ControlCenterShell;

require_once __DIR__ . '/FactoryOrchestratorWebEntrypoint.php';
require_once __DIR__ . '/GitHubPublicEntrypoint.php';
require_once __DIR__ . '/GitHubProjectEntrypoint.php';
require_once __DIR__ . '/ControlCenterShell.php';

final class ControlBotWebEntrypoint
{
    public static function handle(
        array $server,
        array $environment,
        int $now,
        ?string $githubProjectionPath = null,
        ?string $orchestratorSnapshotPath = null,
        ?string $orchestratorCachePath = null,
    ): array {
        $uri = $server['REQUEST_URI'] ?? null;
        $path = is_string($uri) && $uri !== '' && strlen($uri) <= 2048
            ? parse_url($uri, PHP_URL_PATH)
            : null;

        if (!is_string($path) || $path === '' || str_contains($path, "\0")) {
            return FactoryOrchestratorWebEntrypoint::handle(
                $server,
                $environment,
                $now,
                $orchestratorSnapshotPath,
                $orchestratorCachePath,
            );
        }

        if ($path === '/agentes') return self::forbiddenAgents();

        if ($path !== '/github'
            && preg_match('#^/projects/[a-z][a-z0-9-]{1,63}/github$#D', $path) !== 1) {
            return FactoryOrchestratorWebEntrypoint::handle(
                $server,
                $environment,
                $now,
                $orchestratorSnapshotPath,
                $orchestratorCachePath,
            );
        }

        $request = [
            'method' => self::text($server['REQUEST_METHOD'] ?? null),
            'path' => $path,
            'remote_user' => self::text($server['REMOTE_USER'] ?? null),
        ];
        $config = [
            'owner_login' => self::text($environment['CONTROLBOT_OWNER_LOGIN'] ?? null),
            'max_age_seconds' => 300,
        ];

        $response = $path === '/github'
            ? GitHubPublicEntrypoint::handle($request, $config, $now, $githubProjectionPath)
            : GitHubProjectEntrypoint::handle($request, $config, $now, $githubProjectionPath);

        return self::withShell($response, $path === '/github' ? 'GitHub' : 'GitHub del proyecto');
    }

    /**
     * Canal autenticado para las vistas del dueño. La sesion procede del
     * almacenamiento privado de PHP, no de cabeceras ni de REMOTE_USER.
     *
     * La integracion del bootstrap HTTP se hace por separado: nunca inferir
     * autenticacion OAuth/TOTP de una cabecera introducida por el cliente.
     */
    public static function handleAuthenticated(
        array $server,
        array $environment,
        int $now,
        array $ownerSession,
        \ControlBot\Security\OwnerSessionService $ownerAuth,
        ?string $githubProjectionPath = null,
        ?string $orchestratorSnapshotPath = null,
        ?string $orchestratorCachePath = null,
        ?callable $ownerApiBackend = null,
    ): array {
        require_once __DIR__ . '/Routing/OwnerAuthenticatedRouter.php';
        $owner = \ControlBot\Routing\OwnerAuthenticatedRouter::verifiedOwner(
            $ownerAuth,
            $ownerSession,
            $now,
        );
        $expected = $environment['CONTROLBOT_OWNER_LOGIN'] ?? null;
        $uri = $server['REQUEST_URI'] ?? null;
        $apiPath = is_string($uri) && strlen($uri) <= 2048 && !str_contains($uri, "\0")
            ? parse_url($uri, PHP_URL_PATH) : null;
        if ($owner === null || !is_string($expected) || !hash_equals($expected, $owner)) {
            if (is_string($apiPath) && str_starts_with($apiPath, '/api/v1/')) {
                return \ControlBot\Routing\OwnerAuthenticatedRouter::forbidden($server);
            }
            return [
                'status' => 403,
                'headers' => [
                    'Content-Type' => 'text/plain; charset=utf-8',
                    'Cache-Control' => 'no-store',
                    'X-Content-Type-Options' => 'nosniff',
                    'X-Frame-Options' => 'DENY',
                    'Referrer-Policy' => 'no-referrer',
                ],
                'body' => "Forbidden.\n",
            ];
        }
        if ($apiPath === '/agentes') {
            return ($server['REQUEST_METHOD'] ?? null) === 'GET'
                ? self::agentsPage($now, $ownerApiBackend) : self::forbiddenAgents();
        }
        // API owner-v1: el servidor NO confiere autoridad por cabeceras.
        // OAuth+TOTP ya se validaron arriba, pero el registro vuelve a
        // verificarlos antes de seleccionar handlers/mutaciones.
        if (is_string($apiPath) && str_starts_with($apiPath, '/api/v1/')) {
            return \ControlBot\Routing\OwnerAuthenticatedRouter::dispatch(
                $ownerAuth, $ownerSession, $server, $now, $ownerApiBackend,
            ) ?? self::error();
        }
        // Delegar solo DESPUES de verificar OAuth y TOTP; se descarta
        // cualquier REMOTE_USER recibido en la peticion original.
        // Recovery is reached only after server-backed owner OAuth/TOTP verification.
        // Never route this view through the REMOTE_USER-based public handle().
        $uri = $server['REQUEST_URI'] ?? null;
        if (is_string($uri) && strlen($uri) <= 2048 && !str_contains($uri, "\0")
            && parse_url($uri, PHP_URL_PATH) === '/security/resilience') {
            require_once __DIR__ . '/RecoveryCenterWebEntrypoint.php';
            return RecoveryCenterWebEntrypoint::handle($server);
        }
        $trustedServer = $server;
        $trustedServer['REMOTE_USER'] = $owner;
        return self::handle(
            $trustedServer,
            $environment,
            $now,
            $githubProjectionPath,
            $orchestratorSnapshotPath,
            $orchestratorCachePath,
        );
    }

    /**
     * Vistas del dueño tras la página de login (#1346). La sesión local ya fue verificada
     * por OwnerLoginGate::requireOwner en el servidor; el login se toma de la configuración
     * del servidor, nunca de REMOTE_USER ni de cabeceras del cliente.
     */
    public static function handleLocalOwner(array $server, array $environment, int $now): array
    {
        $owner = self::text($environment['CONTROLBOT_OWNER_LOGIN'] ?? null);
        if ($owner === null) {
            return self::error();
        }
        // La sesión local de contraseña NO sustituye un acceso OAuth+TOTP.
        // El API mobile de owner se reserva a handleAuthenticated.
        $uri = $server['REQUEST_URI'] ?? null;
        $path = is_string($uri) && strlen($uri) <= 2048
            ? parse_url($uri, PHP_URL_PATH) : null;
        if (is_string($path) && str_starts_with($path, '/api/v1/')) {
            require_once __DIR__ . '/Routing/OwnerAuthenticatedRouter.php';
            return \ControlBot\Routing\OwnerAuthenticatedRouter::forbidden($server);
        }
        if ($path === '/agentes') {
            return ($server['REQUEST_METHOD'] ?? null) === 'GET'
                ? self::agentsPage($now, null) : self::forbiddenAgents();
        }
        $server['REMOTE_USER'] = $owner;
        return self::handle($server, $environment, $now);
    }

    /** Rendering from a trusted backend only; missing live adapter is UNKNOWN. */
    private static function agentsPage(int $now, ?callable $backend): array
    {
        if ($now < 1) return self::error();
        try {
            $routes = require __DIR__ . '/Routing/Routes/AgentRoutes.php';
            $handler = $routes[0]['handler'] ?? null;
            if (!is_callable($handler)) return self::error();
            $backend ??= static fn (): array => [
                'accounts' => [], 'agents' => [], 'rows' => [], 'capacity_observations' => [],
            ];
            $view = $handler($backend, [], [], $now);
            $e = static fn (mixed $value): string => htmlspecialchars(
                (string) ($value ?? 'UNKNOWN'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8',
            );
            $content = '<main style="padding:clamp(1rem,2vw,2rem);max-width:80rem">'
                . '<h1>Agentes y sesiones</h1><p role="status">Presencia: '
                . $e($view['presence']) . ' · Capacidad: ' . $e($view['capacity'])
                . ' · Datos: ' . $e($view['state']) . '</p>';
            foreach ([
                'agents' => ['Agentes', ['id' => 'Agente', 'role' => 'Rol',
                    'presence' => 'Presencia', 'session_count' => 'Sesiones']],
                'accounts' => ['Cuentas', ['id' => 'Cuenta', 'alias' => 'Alias',
                    'status' => 'Estado', 'capacity' => 'Capacidad', 'free_slots' => 'Libres']],
                'sessions' => ['Sesiones', ['id' => 'Sesión', 'agent_id' => 'Agente',
                    'account_id' => 'Cuenta', 'status' => 'Estado', 'presence' => 'Presencia']],
            ] as $field => [$label, $columns]) {
                $content .= '<section aria-label="' . $e($label)
                    . '" style="margin-block:1.5rem"><h2>' . $e($label) . '</h2>';
                if ($view[$field] === []) {
                    $content .= '<p>UNKNOWN · Sin observaciones.</p></section>';
                    continue;
                }
                $content .= '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse">'
                    . '<thead><tr>';
                foreach ($columns as $heading) $content .= '<th scope="col" style="padding:.5rem;text-align:left">' . $e($heading) . '</th>';
                $content .= '</tr></thead><tbody>';
                foreach ($view[$field] as $record) {
                    $content .= '<tr>';
                    foreach ($columns as $name => $heading) {
                        $content .= '<td style="padding:.5rem;border-top:1px solid var(--line)">'
                            . $e($record[$name] ?? null) . '</td>';
                    }
                    $content .= '</tr>';
                }
                $content .= '</tbody></table></div></section>';
            }
            $html = ControlCenterShell::render('Agentes', 'agents', [
                'overview' => '/overview', 'agents' => '/agentes', 'github' => '/github',
            ], $content . '</main>');
            return ['status' => 200, 'headers' => [
                'Content-Type' => 'text/html; charset=utf-8',
                'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'DENY', 'Referrer-Policy' => 'no-referrer',
            ], 'body' => $html];
        } catch (\Throwable) {
            return self::error();
        }
    }

    private static function forbiddenAgents(): array
    {
        return ['status' => 403, 'headers' => [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY', 'Referrer-Policy' => 'no-referrer',
        ], 'body' => "Forbidden.\n"];
    }

    private static function withShell(array $response, string $title): array
    {
        if (($response['status'] ?? null) !== 200
            || !is_string($response['body'] ?? null)
            || !str_starts_with((string)($response['headers']['Content-Type'] ?? ''), 'text/html')) {
            return $response;
        }

        $html = $response['body'];
        if (preg_match('#<body[^>]*>(.*)</body>#sD', $html, $bodyMatch) !== 1) {
            return self::error();
        }
        $css = preg_match('#<style>(.*)</style>#sD', $html, $styleMatch) === 1
            ? $styleMatch[1]
            : '';

        $routes = ['overview' => '/overview', 'github' => '/github'];
        $response['body'] = ControlCenterShell::render(
            $title,
            'github',
            $routes,
            $bodyMatch[1],
            $css,
        );
        return $response;
    }

    private static function error(): array
    {
        return [
            'status' => 503,
            'headers' => [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'DENY',
                'Referrer-Policy' => 'no-referrer',
            ],
            'body' => "Service unavailable.\n",
        ];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
