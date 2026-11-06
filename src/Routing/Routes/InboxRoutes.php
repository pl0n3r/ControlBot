<?php
declare(strict_types=1);

namespace ControlBot\Routing\Routes;

use ControlBot\Routing\OwnerAuthenticatedRouter;
use ControlBot\Security\OwnerSessionService;
use ControlBot\Ui\ControlCenterShell;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/../OwnerAuthenticatedRouter.php';
require_once __DIR__ . '/../../ControlCenterShell.php';

if (!class_exists(InboxRoutes::class, false)) {
    final class InboxRoutes
    {
        public static function page(
            OwnerSessionService $auth, array $session, array $server, int $now,
            ?callable $backend = null, array $form = [],
        ): ?array {
            $uri = $server['REQUEST_URI'] ?? null;
            if (!is_string($uri) || strlen($uri) > 2048 || str_contains($uri, "\0")) return null;
            $path = parse_url($uri, PHP_URL_PATH);
            $match = [];
            $detail = is_string($path)
                && preg_match('#^/decisiones/([a-z][a-z0-9-]{1,63})$#D', $path, $match) === 1
                ? $match[1] : null;
            if ($path !== '/inbox' && $detail === null) return null;
            if (OwnerAuthenticatedRouter::verifiedOwner($auth, $session, $now) === null)
                return self::response(403, 'Sin permiso', '<p>Acceso exclusivo del dueño autenticado.</p>');
            $method = $server['REQUEST_METHOD'] ?? null;
            if (!in_array($method, ['GET', 'POST'], true) || ($detail === null && $method !== 'GET'))
                return self::response(405, 'Método no permitido', '<p>Operación no admitida.</p>');
            if ($backend === null) return self::unavailable();
            try {
                if ($method === 'POST') {
                    if (!is_string($form['_csrf'] ?? null) || $form['_csrf'] === '')
                        return self::response(403, 'Sin permiso', '<p>CSRF requerido.</p>');
                    $keys = array_keys($form);
                    sort($keys);
                    if ($keys !== ['_csrf', 'idempotency_key', 'outcome']
                        || !is_string($form['_csrf']) || !is_string($form['idempotency_key'])
                        || !preg_match('/^[a-f0-9]{32,64}$/D', $form['idempotency_key'])
                        || !in_array($form['outcome'], ['approve', 'reject'], true))
                        return self::response(400, 'Petición inválida', '<p>Formulario inválido.</p>');
                    $request = $server;
                    $request['REQUEST_URI'] = '/api/v1/owner-decisions/' . $detail . '/decision';
                    $request['CONTENT_TYPE'] = 'application/json';
                    $request['HTTP_X_CSRF_TOKEN'] = $form['_csrf'];
                    $json = json_encode(['version' => 1, 'outcome' => $form['outcome'],
                        'idempotency_key' => $form['idempotency_key']], JSON_THROW_ON_ERROR);
                    $result = OwnerAuthenticatedRouter::dispatch(
                        $auth, $session, $request, $now, $backend, null, $json,
                    );
                    if (($result['status'] ?? null) === 403)
                        return self::response(403, 'Sin permiso', '<p>CSRF o sesión inválidos.</p>');
                    if (($result['status'] ?? null) !== 200) return self::unavailable();
                    $receipt = json_decode($result['body'], true, 16, JSON_THROW_ON_ERROR);
                    if (($receipt['data']['decision_id'] ?? null) !== $detail
                        || ($receipt['data']['outcome'] ?? null) !== $form['outcome'])
                        return self::unavailable();
                    return self::response(200, 'Decisión registrada',
                        '<p role="status">El backend confirmó la decisión y su registro auditable.</p>'
                        . '<p><a href="/inbox">Volver al Inbox</a></p>');
                }
                $query = parse_url($uri, PHP_URL_QUERY);
                if ($detail !== null && $query !== null)
                    return self::response(400, 'Petición inválida', '<p>Parámetros inesperados.</p>');
                if ($detail === null) {
                    if ($query !== null && preg_match('/^estado=(all|decision|critical)$/D', $query, $q) !== 1)
                        return self::response(400, 'Filtro inválido', '<p>Usa los filtros ofrecidos.</p>');
                    $filter = $q[1] ?? 'all';
                    $data = self::api($auth, $session, $server, $now, $backend, '/api/v1/owner-inbox');
                    $entries = $data['entries'] ?? null;
                    if (!is_array($entries) || !array_is_list($entries)) return self::unavailable();
                    $body = '<h1>Inbox del dueño</h1><p>Decisiones abiertas y su historial.</p>'
                        . '<nav aria-label="Filtrar decisiones"><a href="/inbox">Todas</a> · '
                        . '<a href="/inbox?estado=decision">Decisiones</a> · '
                        . '<a href="/inbox?estado=critical">Críticas</a></nav><ul class="inbox-list">';
                    $count = 0;
                    foreach ($entries as $entry) {
                        if (!is_array($entry) || !isset($entry['kind'], $entry['title'], $entry['summary'])) return self::unavailable();
                        $kind = $entry['kind'];
                        $id = $entry['decision_id'] ?? null;
                        if (!in_array($kind, ['DECISION', 'CRITICAL'], true) || !is_string($id)
                            || ($filter !== 'all' && $kind !== strtoupper($filter))) continue;
                        $count++;
                        $body .= '<li><a href="/decisiones/' . self::e($id) . '">'
                            . '<strong>' . self::e($entry['title']) . '</strong></a> '
                            . '<small>' . self::e($kind) . '</small>'
                            . '<p>' . self::e($entry['summary']) . '</p></li>';
                    }
                    $body .= '</ul>';
                    if ($count === 0) $body .= '<p role="status">No hay decisiones pendientes para este filtro.</p>';
                    return self::response(200, 'Inbox', $body . self::history($backend, [], $now));
                }
                $data = self::api($auth, $session, $server, $now, $backend,
                    '/api/v1/owner-decisions/' . $detail);
                if (($data['decision_id'] ?? null) !== $detail || !is_array($data['options'] ?? null))
                    return self::unavailable();
                $body = '<p><a href="/inbox">← Inbox</a></p><h1>' . self::e($data['title'])
                    . '</h1><p>' . self::e($data['question']) . '</p><ul>';
                foreach ($data['options'] as $option)
                    $body .= '<li><strong>' . self::e($option['key']) . '</strong>: '
                        . self::e($option['label']) . '</li>';
                $body .= '</ul><p>Estado: ' . self::e($data['state']) . '</p>';
                if ($data['state'] === 'pending') {
                    $csrf = $auth->csrfToken($session);
                    $body .= '<form method="post" action="/decisiones/' . self::e($detail) . '">'
                        . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
                        . '<input type="hidden" name="idempotency_key" value="' . bin2hex(random_bytes(16)) . '">'
                        . '<button type="submit" name="outcome" value="approve">Aprobar</button> '
                        . '<button type="submit" name="outcome" value="reject">Rechazar</button></form>';
                }
                return self::response(200, 'Decisión', $body . self::history($backend, ['decision_id' => $detail], $now));
            } catch (Throwable) {
                return self::unavailable();
            }
        }

        private static function api(
            OwnerSessionService $auth, array $session, array $server, int $now,
            callable $backend, string $path,
        ): array {
            $request = $server;
            $request['REQUEST_METHOD'] = 'GET';
            $request['REQUEST_URI'] = $path;
            $result = OwnerAuthenticatedRouter::dispatch($auth, $session, $request, $now, $backend);
            if (($result['status'] ?? null) !== 200) throw new RuntimeException('Owner source unavailable.');
            $payload = json_decode($result['body'], true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($payload['data'] ?? null)) throw new RuntimeException('Invalid projection.');
            return $payload['data'];
        }

        private static function history(callable $backend, array $filter, int $now): string
        {
            try {
                $projection = $backend('decision_history.read', $filter, [], $now);
                $rows = $projection['entries'] ?? null;
                if (!is_array($rows) || !array_is_list($rows) || count($rows) > 100)
                    throw new RuntimeException('History unavailable.');
                $html = '<section aria-labelledby="history-title"><h2 id="history-title">Historial</h2>';
                foreach ($rows as $row) {
                    if (!is_array($row) || !is_string($row['result'] ?? null)
                        || !in_array($row['result'], ['success', 'failed', 'blocked'], true)
                        || !is_int($row['at'] ?? null) || $row['at'] < 1)
                        throw new RuntimeException('Invalid history.');
                    $html .= '<p>' . self::e(gmdate('Y-m-d H:i', $row['at']))
                        . ' · ' . self::e($row['result']) . '</p>';
                }
                return $html . ($rows === [] ? '<p>Sin registros confirmados.</p>' : '') . '</section>';
            } catch (Throwable) {
                return '<section aria-label="Historial"><h2>Historial</h2>'
                    . '<p role="status">Historial no disponible; no se infiere ausencia de decisiones.</p></section>';
            }
        }

        private static function unavailable(): array
        {
            return self::response(503, 'No disponible',
                '<p role="alert">No hay una fuente privada y verificada disponible. Ninguna decisión fue ejecutada.</p>');
        }

        private static function response(int $status, string $title, string $body): array
        {
            $css = '.inbox-list{list-style:none;padding:0;display:grid;gap:12px}'
                . '.inbox-list li{padding:16px;border:1px solid var(--line);border-radius:10px}'
                . '.inbox-list p{margin-bottom:0}main{max-width:840px;margin:auto;padding:20px}'
                . 'button{min-height:44px;padding:10px 18px;margin:8px 0;cursor:pointer}'
                . 'a{color:var(--cyan)}@media(min-width:700px){main{padding:36px}}';
            return ['status' => $status, 'headers' => [
                'Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'DENY',
                'Referrer-Policy' => 'no-referrer',
                'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'",
            ], 'body' => ControlCenterShell::render($title, 'decisions',
                ['overview' => '/overview', 'decisions' => '/inbox'], '<main>' . $body . '</main>', $css)];
        }

        private static function e(string $value): string
        {
            return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }
}

// API route discovery: authenticated alias backed by the same typed OwnerInbox projection.
return [['method' => 'GET', 'path' => '/api/v1/web-inbox',
    'handler' => static function (callable $backend, array $vars, array $request, int $now): array {
        $routes = require __DIR__ . '/OwnerApiRoutes.php';
        foreach ($routes as $route) {
            if ($route['path'] === '/api/v1/owner-inbox')
                return $route['handler']($backend, $vars, $request, $now);
        }
        throw new RuntimeException('Owner inbox route not registered.');
    },
]];
