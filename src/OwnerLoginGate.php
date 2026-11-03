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
        return self::redirect($next !== '' ? $next : '/overview');
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
                . '<label for="totp">Código de verificación<span class="hint">Solo si activaste la verificación en dos pasos.</span></label>'
                . '<input id="totp" name="totp" type="text" autocomplete="one-time-code" maxlength="128">'
                . '<button type="submit">Entrar</button></form>';
        $mark = '<svg viewBox="0 0 36 36" aria-hidden="true" focusable="false"><rect width="36" height="36" rx="9" fill="var(--cyan)"/>'
            . '<path d="M11 18.5l5 5 9-11" fill="none" stroke="var(--on-accent)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        $html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Acceso del propietario | ControlBot</title><style>'
            . \ControlBot\Ui\UiTheme::tokensCss()
            . ':root{color-scheme:light dark}'
            . 'body{margin:0;min-height:100vh;display:grid;place-items:center;padding:1.5rem 1rem;box-sizing:border-box;'
            . 'font-family:system-ui,-apple-system,"Segoe UI",sans-serif;line-height:1.5;background:var(--bg);color:var(--text)}'
            . '::selection{background:var(--cyan);color:var(--on-accent)}'
            . 'main{box-sizing:border-box;width:min(100%,400px);padding:2rem 1.75rem 1.75rem;'
            . 'border:1px solid var(--line);border-radius:16px;background:var(--panel);'
            . 'box-shadow:0 12px 32px -12px rgba(12,20,25,.28);animation:rise .5s cubic-bezier(.16,1,.3,1) both}'
            . '@keyframes rise{from{opacity:0;transform:translateY(10px)}}'
            . '.brand{display:flex;align-items:center;gap:.7rem;margin:0 0 1.5rem}'
            . '.brand svg{flex:none;width:36px;height:36px}'
            . '.brand span{font-size:1.125rem;font-weight:700;letter-spacing:-.01em}'
            . 'h1{margin:0 0 .25rem;font-size:1.5rem;line-height:1.2;letter-spacing:-.02em;text-wrap:balance}'
            . '.lede{margin:0 0 1.25rem;color:var(--muted);font-size:.9375rem}'
            . 'label{display:block;margin-top:1rem;font-size:.875rem;font-weight:600}'
            . '.hint{display:block;margin-top:.25rem;color:var(--muted);font-size:.8125rem;font-weight:400}'
            . 'input,button{display:block;width:100%;box-sizing:border-box;min-height:44px;font:inherit;border-radius:8px}'
            . 'input{margin-top:.4rem;padding:.65rem .8rem;border:1px solid var(--line-strong);background:var(--field-bg);'
            . 'color:var(--text);caret-color:var(--cyan);transition:border-color .15s,box-shadow .15s}'
            . 'input:hover{border-color:var(--cyan)}'
            . 'input:focus{border-color:var(--cyan);box-shadow:0 0 0 3px var(--cyan-soft)}'
            . 'button{margin-top:1.5rem;padding:.65rem 1rem;border:0;background:var(--cyan);color:var(--on-accent);'
            . 'font-weight:700;cursor:pointer;transition:background-color .15s,transform .15s}'
            . 'button:hover{filter:brightness(.94)}button:active{transform:scale(.98)}'
            . 'a{color:var(--cyan);text-underline-offset:.2em}'
            . '.error{margin:0 0 1rem;padding:.7rem .85rem;border:1px solid var(--red);border-radius:8px;'
            . 'background:var(--warning-bg);color:var(--warning-text);font-size:.9375rem}'
            . '.foot{margin:1.5rem 0 0;color:var(--muted);font-size:.8125rem;text-align:center}'
            . ':focus-visible{outline:3px solid var(--amber);outline-offset:3px}'
            . '@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation:none!important;transition:none!important;scroll-behavior:auto!important}}'
            . '</style></head>'
            . '<body><main><div class="brand">' . $mark . '<span>ControlBot</span></div>'
            . '<h1>' . ($authenticated ? 'Sesión iniciada' : 'Acceso del propietario') . '</h1>'
            . '<p class="lede">' . ($authenticated ? 'Ya puedes entrar al panel.' : 'Entra con tu usuario y contraseña para abrir el panel.') . '</p>'
            . $noticeHtml . $content . '<p class="foot">Zona privada. Los accesos quedan registrados.</p></main></body></html>';
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