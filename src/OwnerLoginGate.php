<?php
declare(strict_types=1);
namespace ControlBot\Security;
require_once __DIR__ . '/UiTheme.php';
require_once __DIR__ . '/OwnerLocalAuthStore.php';
use Throwable;
final class OwnerLoginGate {
    private const IDLE_SECONDS = 900;
    private const WINDOW_SECONDS = 900;
    private const MAX_FAILURES = 5;
        public static function handle(
        array $server,
        array $post,
        array &$session,
        array $config,
        int $now,
        ?callable $regenerate = null,
    ): array {
        if (!self::configured($config) || $now <= 0) return self::response(503, 'Service unavailable.');
        $method = $server['REQUEST_METHOD'] ?? null;
        if ($method !== 'GET' && $method !== 'POST') return self::response(405, 'Method not allowed.', ['Allow' => 'GET, POST']);
        if (!self::validRequestPath($server, ['/login', '/login.php'])) return self::response(404, 'Not found.');
        try {
            $store = self::store($config);
            self::expire($session, $now);
            if (($session['_cb_owner_web'] ?? null) === true
                && !$store->validVersion($session['_cb_owner_revision'] ?? null)) {
                unset($session['_cb_owner_web'], $session['_cb_owner_last_seen'], $session['_cb_owner_revision']);
            }
        } catch (Throwable) {
            return self::response(503, 'Service unavailable.');
        }
        if (!self::validToken($session['_cb_login_csrf'] ?? null)) {
            $session['_cb_login_csrf'] = self::randomToken();
        }
        parse_str((string) ($server['QUERY_STRING'] ?? ''), $query);
        $next = self::safeNext($method === 'GET' ? ($query['next'] ?? null) : ($post['next'] ?? null));
        if ($method === 'GET') {
            try {
                $status = $store->status();
            } catch (Throwable) {
                return self::response(503, 'Service unavailable.');
            }
            return self::loginPage($session, self::isAuthenticated($session, $now),
                '', 200, $status, $next);
        }
        $action = $post['action'] ?? 'login';
        $token = $post['csrf'] ?? null;
        if (!is_string($token) || !hash_equals($session['_cb_login_csrf'], $token)) return self::response(403, 'Solicitud inválida.');
        if ($action === 'logout') {
            $wasAuthenticated = self::isAuthenticated($session, $now);
            unset($session['_cb_owner_web'], $session['_cb_owner_last_seen'], $session['_cb_owner_revision']);
            $session['_cb_login_csrf'] = self::randomToken();
            try {
                if ($wasAuthenticated) $store->logout($now);
            } catch (Throwable) {
                return self::response(503, 'Service unavailable.');
            }
            if ($regenerate !== null && $regenerate() !== true) return self::response(503, 'Service unavailable.');
            return self::redirect('/login');
        }

        if ($action !== 'login') {
            if (!self::isAuthenticated($session, $now)
                || !$store->validVersion($session['_cb_owner_revision'] ?? null)) {
                return self::response(403, 'Forbidden.');
            }
            $code = $post['totp'] ?? '';
            if (!is_string($code) || strlen($code) > 128) {
                return self::response(400, 'Solicitud inválida.');
            }
            // La posesión de una sesión y su CSRF no basta para operaciones
            // sensibles: revocar sesiones o enrolar MFA exige la clave actual.
            if (in_array($action, ['revoke_all', 'totp_begin'], true)) {
                $password = $post['password'] ?? null;
                if (!is_string($password) || strlen($password) > 4096) {
                    return self::response(403, 'Operación denegada.');
                }
                $ip = $server['REMOTE_ADDR'] ?? null;
                if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                    return self::response(503, 'Service unavailable.');
                }
                try {
                    $check = self::checkAndRecord($config, $ip, $config['owner_login'], $password, $now);
                } catch (Throwable) {
                    return self::response(503, 'Service unavailable.');
                }
                if (is_array($check) && $check['status'] === 'limited') {
                    return self::response(429, 'Demasiados intentos. Inténtalo más tarde.',
                        ['Retry-After' => (string) $check['retry_after']]);
                }
                if ($check !== 'authenticated') {
                    return self::response(403, 'Operación denegada.');
                }
            }
            try {
                switch ($action) {
                    case 'revoke_all':
                        $store->revokeAll($now);
                        unset($session['_cb_owner_web'], $session['_cb_owner_last_seen'], $session['_cb_owner_revision']);
                        $session['_cb_login_csrf'] = self::randomToken();
                        if ($regenerate !== null && $regenerate() !== true) {
                            return self::response(503, 'Service unavailable.');
                        }
                        return self::redirect('/login');
                    case 'totp_begin':
                        $secret = $store->beginEnrollment($now);
                        return self::loginPage($session, true,
                            'Configura el secreto TOTP, luego confirma un código: ' . $secret,
                            200, $store->status());
                    case 'totp_confirm':
                        $enrollment = $store->confirmEnrollment($code, $now, (int) ($session['_cb_owner_revision'] ?? -1));
                        // Nunca adoptar una revisión global posterior a esta operación:
                        // una revocación concurrente debe invalidar esta sesión.
                        $session['_cb_owner_revision'] = $enrollment['version'];
                        return self::loginPage($session, true,
                            'Guarda estos códigos de recuperación de un solo uso fuera del sistema: '
                            . implode(' ', $enrollment['codes']), 200, $store->status());
                    case 'totp_disable':
                        $session['_cb_owner_revision'] = $store->disable($code, $now, (int) ($session['_cb_owner_revision'] ?? -1));
                        return self::redirect('/login');
                    case 'totp_required':
                    case 'totp_optional':
                        $session['_cb_owner_revision'] = $store->setRequired(
                            $action === 'totp_required', $code, $now,
                            (int) ($session['_cb_owner_revision'] ?? -1)
                        );
                        return self::redirect('/login');
                    default:
                        return self::response(400, 'Solicitud inválida.');
                }
            } catch (Throwable) {
                return self::response(403, 'Operación denegada.');
            }
        }
        $login = $post['username'] ?? null;
        $password = $post['password'] ?? null;
        if (!is_string($login) || !is_string($password)
            || strlen($login) > 128 || strlen($password) > 4096) {
            return self::failurePage($session, $next);
        }
        $ip = $server['REMOTE_ADDR'] ?? null;
        if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) return self::response(503, 'Service unavailable.');
        try {
            $code = $post['totp'] ?? '';
            if (!is_string($code) || strlen($code) > 128) return self::failurePage($session, $next);
            // Capturar versión antes de validar credenciales; el store compara
            // de nuevo dentro de su lock al establecer la sesión.
            $attemptVersion = $store->status()['version'];
            $result = self::checkAndRecord($config, $ip, $login, $password, $now,
                static fn (): bool => $store->verify($code, $now));
        } catch (Throwable) {
            return self::response(503, 'Service unavailable.');
        }
        if (is_array($result) && $result['status'] === 'limited') {
            return self::response(429, 'Demasiados intentos. Inténtalo más tarde.',
                ['Retry-After' => (string) $result['retry_after']]);
        }
        if ($result !== 'authenticated') return self::failurePage($session, $next);
        if ($regenerate !== null && $regenerate() !== true) return self::response(503, 'Service unavailable.');
        try {
            $session['_cb_owner_revision'] = $store->login($now, $attemptVersion);
        } catch (Throwable) {
            unset($session['_cb_owner_web'], $session['_cb_owner_last_seen'], $session['_cb_owner_revision']);
            return self::response(503, 'Service unavailable.');
        }
        $session['_cb_owner_web'] = true;
        $session['_cb_owner_last_seen'] = $now;
        $session['_cb_login_csrf'] = self::randomToken();
        return self::redirect($next !== '' ? $next : '/');
    }
    /** Comprueba la sesión *local*, sin conceder por ello tokens OAuth/TOTP. */
    public static function isAuthenticated(array $session, int $now): bool {
        $last = $session['_cb_owner_last_seen'] ?? null;
        return ($session['_cb_owner_web'] ?? null) === true
            && is_int($last) && $last > 0 && $now >= $last
            && $now - $last < self::IDLE_SECONDS;
    }
        public static function requireOwner(
        array $server,
        array &$session,
        array $config,
        int $now,
    ): ?array {
        $uri = $server['REQUEST_URI'] ?? null;
        $path = is_string($uri) && strlen($uri) <= 2048 && !str_contains($uri, "\0")
            ? parse_url($uri, PHP_URL_PATH) : null;
        if (!is_string($path) || $path === '' || str_contains($path, '%')
            || str_contains($path, '//') || str_contains($path, '..')) {
            return self::response(403, 'Forbidden.');
        }
        $method = $server['REQUEST_METHOD'] ?? null;
        if ($method === 'GET' && (
            $path === '/health' || $path === '/health.php' ||
            preg_match('#^/assets/[A-Za-z0-9/_-]+\.(?:css|js|svg|png|jpg|webp|ico)$#D', $path) === 1
        )) {
            return null;
        }
        if (!self::configured($config) || $now <= 0) return self::response(503, 'Service unavailable.');
        if ($path === '/login' || $path === '/login.php') return null;
        self::expire($session, $now);
        if (!self::isAuthenticated($session, $now)) return self::redirect(self::loginUrl($method, $path), 302);
        try {
            if (!self::store($config)->validVersion($session['_cb_owner_revision'] ?? null)) {
                unset($session['_cb_owner_web'], $session['_cb_owner_last_seen'], $session['_cb_owner_revision']);
                return self::redirect(self::loginUrl($method, $path), 302);
            }
        } catch (Throwable) {
            return self::response(503, 'Service unavailable.');
        }
        $session['_cb_owner_last_seen'] = $now;
        return null;
    }
    private static function configured(array $config): bool {
        $owner = $config['owner_login'] ?? null;
        $hash = $config['password_hash'] ?? null;
        $directory = $config['rate_limit_dir'] ?? null;
        return is_string($owner) && preg_match('/^[A-Za-z0-9-]{1,39}$/D', $owner) === 1
            && is_string($hash) && $hash !== ''
            && password_get_info($hash)['algo'] === PASSWORD_ARGON2ID
            && is_string($directory) && str_starts_with($directory, '/')
            && is_dir($directory) && !is_link($directory) && is_writable($directory);
    }
    private static function validRequestPath(array $server, array $allowed): bool {
        $uri = $server['REQUEST_URI'] ?? null;
        if (!is_string($uri) || strlen($uri) > 2048 || str_contains($uri, "\0")) return false;
        return in_array(parse_url($uri, PHP_URL_PATH), $allowed, true);
    }
    private static function expire(array &$session, int $now): void {
        if (!self::isAuthenticated($session, $now)) {
            unset($session['_cb_owner_web'], $session['_cb_owner_last_seen'], $session['_cb_owner_revision']);
        }
    }
    /**
     * Throttle exclusively by caller IP, never by a globally shared username.
     * Filename contains a keyed pseudonym rather than the raw IP or account.
     *
     * @return string|array{status:'limited',retry_after:int}
     */
    private static function checkAndRecord(
        array $config,
        string $ip,
        string $login,
        string $password,
        int $now,
        ?callable $secondary = null,
    ): string|array {
        $hash = $config['password_hash'];
        $dir = realpath($config['rate_limit_dir']);
        if (!is_string($dir) || !is_dir($dir)) {
            throw new \RuntimeException('Private throttle directory unavailable.');
        }
        $ipKey = 'ip:' . hash_hmac('sha256', $ip, $hash);
        $file = $dir . '/cb-owner-' . hash('sha256', $ipKey) . '.json';
        if (is_link($file) || (file_exists($file) && !is_file($file))) {
            throw new \RuntimeException('Invalid throttle file.');
        }
        $handle = @fopen($file, 'c+');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Throttle unavailable.');
        }
        try {
            @chmod($file, 0600);
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Throttle lock failed.');
            }
            rewind($handle);
            $raw = stream_get_contents($handle);
            if (!is_string($raw)) {
                throw new \RuntimeException('Throttle read failed.');
            }
            $state = $raw !== '' ? json_decode($raw, true) : null;
            if (!is_array($state) || !isset($state['start'], $state['count'], $state['blocked_until'])
                || !is_int($state['start']) || !is_int($state['count']) || !is_int($state['blocked_until'])
                || $now - $state['start'] >= self::WINDOW_SECONDS || $now < $state['start']) {
                $state = ['start' => $now, 'count' => 0, 'blocked_until' => 0];
            }
            if ($state['blocked_until'] > $now || $state['count'] >= self::MAX_FAILURES) {
                // Saturating the window blocks until its expiry, even after a short delay.
                $windowRemaining = $state['count'] >= self::MAX_FAILURES
                    ? max(0, $state['start'] + self::WINDOW_SECONDS - $now) : 0;
                return ['status' => 'limited', 'retry_after' => max(
                    1, $state['blocked_until'] - $now, $windowRemaining,
                )];
            }
            // Both comparisons are performed, regardless of account existence.
            $passwordOk = password_verify($password, $hash);
            $ownerOk = hash_equals($config['owner_login'], $login);
            if ($passwordOk && $ownerOk && ($secondary === null || $secondary())) {
                self::saveThrottle($handle, ['start' => $now, 'count' => 0, 'blocked_until' => 0]);
                return 'authenticated';
            }
            $state['count']++;
            if ($state['count'] >= 3) {
                $state['blocked_until'] = $now + min(300, 2 ** min(8, $state['count'] - 2));
            }
            self::saveThrottle($handle, $state);
            return 'invalid';
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
    private static function saveThrottle($handle, array $state): void {
        rewind($handle);
        if (!ftruncate($handle, 0) ||
            fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR)) === false ||
            !fflush($handle)) throw new \RuntimeException('Throttle write failed.');
    }
    private static function loginPage(
        array $session, bool $authenticated, string $notice = '', int $status = 200,
        array $mfa = [], string $next = '',
    ): array {
        $csrf = htmlspecialchars($session['_cb_login_csrf'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $noticeHtml = $notice !== '' ? '<p role="alert" class="error">' . htmlspecialchars($notice, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>' : '';
        $content = $authenticated
            ? '<p>Sesión de acceso local iniciada.</p><p><a href="/">Ir al panel</a></p>'
                . '<form method="post" action="/login"><input type="hidden" name="action" value="logout">'
                . '<input type="hidden" name="csrf" value="' . $csrf . '"><button type="submit">Salir</button></form>'
                . self::managementForms($csrf, $mfa)
            : '<form method="post" action="/login"><input type="hidden" name="action" value="login">'
                . '<input type="hidden" name="csrf" value="' . $csrf . '">'
                . ($next !== '' ? '<input type="hidden" name="next" value="' . htmlspecialchars($next, ENT_QUOTES, 'UTF-8') . '">' : '')
                . '<label for="username">Usuario</label><input id="username" name="username" type="text" autocomplete="username" required maxlength="128">'
                . '<label for="password">Contraseña</label><input id="password" name="password" type="password" autocomplete="current-password" required>'
                . '<label for="totp">Código TOTP o de recuperación (si aplica)</label>'
                . '<input id="totp" name="totp" type="text" autocomplete="one-time-code" maxlength="128">'
                . '<button type="submit">Entrar</button></form>';
        $html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Acceso del propietario | ControlBot</title><style>'
            . \ControlBot\Ui\UiTheme::tokensCss()
            . 'body{margin:0;min-height:100vh;display:grid;place-items:center;'
            . 'font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:var(--bg);color:var(--text)}'
            . 'main{box-sizing:border-box;width:min(92vw,390px);padding:2rem;'
            . 'border:1px solid var(--line);border-radius:16px;background:var(--panel)}'
            . 'label,input,button{display:block;width:100%;box-sizing:border-box;margin-top:.65rem}'
            . 'input,button{min-height:44px;padding:.75rem;border-radius:8px;border:1px solid var(--line-strong)}'
            . 'input{background:var(--field-bg);color:var(--text);font:inherit}'
            . 'button{background:var(--cyan);color:var(--on-accent);font-weight:700;cursor:pointer;margin-top:1.25rem}'
            . 'button:hover{filter:brightness(.94)}a{color:var(--cyan)}.error{color:var(--red)}'
            . ':focus-visible{outline:3px solid var(--amber);outline-offset:3px}'
            . '@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation:none!important;transition:none!important;scroll-behavior:auto!important}}'
            . '</style></head>'
            . '<body><main><h1>ControlBot</h1><p>Acceso del propietario</p>' . $noticeHtml . $content . '</main></body></html>';
        return self::response($status, $html, ['Content-Type' => 'text/html; charset=utf-8']);
    }
    private static function managementForms(string $csrf, array $mfa): string {
        $form = static fn (string $action, string $label, bool $withCode = false): string =>
            '<form method="post" action="/login"><input type="hidden" name="csrf" value="' . $csrf . '">'
            . '<input type="hidden" name="action" value="' . $action . '">'
            . ($withCode ? '<label>Código TOTP</label><input name="totp" type="text" autocomplete="one-time-code" required>' : '')
            . '<button type="submit">' . $label . '</button></form>';
        $sensitiveForm = static fn (string $action, string $label): string =>
            '<form method="post" action="/login"><input type="hidden" name="csrf" value="' . $csrf . '">'
            . '<input type="hidden" name="action" value="' . $action . '">'
            . '<label>Contraseña actual</label><input name="password" type="password" autocomplete="current-password" required>'
            . '<button type="submit">' . $label . '</button></form>';
        $html = $sensitiveForm('revoke_all', 'Revocar todas las sesiones');
        if (($mfa['enabled'] ?? false) !== true) {
            return $html . $sensitiveForm('totp_begin', 'Configurar autenticación en dos pasos')
                . $form('totp_confirm', 'Confirmar TOTP y generar códigos', true);
        }
        if (($mfa['required'] ?? false) !== true) {
            return $html . $form('totp_disable', 'Desactivar TOTP', true)
                . $form('totp_required', 'Hacer TOTP obligatorio', true);
        }
        return $html . $form('totp_optional', 'Hacer TOTP opcional', true);
    }
    private static function store(array $config): OwnerLocalAuthStore {
        $key = $config['totp_key'] ?? getenv('CONTROLBOT_OWNER_TOTP_KEY');
        return new OwnerLocalAuthStore(
            $config['rate_limit_dir'],
            $config['owner_login'],
            is_string($key) ? $key : null,
        );
    }
    private static function failurePage(array $session, string $next = ''): array {
        return self::loginPage($session, false, 'Usuario o contraseña incorrectos.', 401, [], $next);
    }
    /** Ruta interna a la que volver tras entrar; vacío si no es segura (nada de redirecciones abiertas). */
    public static function safeNext(mixed $value): string {
        if (!is_string($value) || strlen($value) > 256
            || preg_match('#^/(?:[A-Za-z0-9_-]+(?:[./][A-Za-z0-9_-]+)*)?$#D', $value) !== 1
            || str_contains($value, '..') || preg_match('#^/login(?:\.php)?(?:/|$)#D', $value) === 1) {
            return '';
        }
        return $value;
    }
    private static function loginUrl(mixed $method, string $path): string {
        $next = $method === 'GET' ? self::safeNext($path) : '';
        return $next !== '' && $next !== '/' ? '/login?next=' . rawurlencode($next) : '/login';
    }
    private static function redirect(string $to, int $status = 303): array { return self::response($status, '', ['Location' => $to]); }
    private static function response(int $status, string $body, array $extras = []): array {
        return ['status' => $status, 'headers' => array_merge([
            'Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; style-src 'unsafe-inline'",
        ], $extras), 'body' => $body];
    }
    private static function validToken(mixed $token): bool { return is_string($token) && preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) === 1; }
    private static function randomToken(): string { return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); }
}