<?php
declare(strict_types=1);

namespace ControlBot\Security;

/**
 * Reads a server-side secret from an environment variable or, when the hosting plan has no
 * environment variables, from a private file outside the repository and document root.
 * The path (never the secret) is configured through NAME_FILE, e.g. in .htaccess.
 */
final class SecretSource
{
    private const MAX_BYTES = 4096;

    /** Whitelisted private filenames for lookup outside the deployed web tree. */
    private const CONVENTIONAL_FILES = [
        'CONTROLBOT_VAULT_KEY' => 'vault.key',
        'CONTROLBOT_OWNER_PASSWORD_HASH' => 'owner-password.hash',
        'CONTROLBOT_OWNER_LOGIN' => 'owner-login',
    ];

    /** Public owner identifier documented in docs/hostinger-root-publication.md (not a secret). */
    private const DEFAULT_OWNER_LOGIN = 'USUARIO';

    public static function read(string $name, ?string $repositoryRoot = null): ?string
    {
        $direct = getenv($name);
        if (is_string($direct) && $direct !== '') {
            return $direct;
        }
        return self::readFromFile($name, $repositoryRoot);
    }

    /**
     * Reads only private files, without accepting a direct env value. Candidates, first hit wins:
     * the configured NAME_FILE, then private/<file> in ancestors of the deployed root (symlinks resolved),
     * then private/<file> in up to four ancestors of it that are not inside a public_html tree.
     */
    private static function readFromFile(string $name, ?string $repositoryRoot): ?string
    {
        $root = realpath($repositoryRoot ?? dirname(__DIR__));
        if ($root === false) {
            return null; // unknown repository root: never skip the containment check
        }
        $candidates = [];
        $explicit = getenv($name . '_FILE');
        if (is_string($explicit) && $explicit !== '') {
            $candidates[] = $explicit;
        }
        $file = self::CONVENTIONAL_FILES[$name] ?? null;
        if ($file !== null) {
            foreach (self::privateDirectories($root) as $dir) {
                $candidates[] = $dir . DIRECTORY_SEPARATOR . $file;
            }
        }
        foreach (array_unique($candidates) as $path) {
            $value = self::loadFile($path, $root);
            if ($value !== null) {
                return $value;
            }
        }
        // PHP web workers log the secret's name, never its path or its value.
        // CLI tests and optional public owner identifiers must stay silent.
        if (PHP_SAPI !== 'cli' && $file !== null && $name !== 'CONTROLBOT_OWNER_LOGIN') {
            error_log('ControlBot: archivo privado no disponible para ' . $name);
        }
        return null;
    }

    /**
     * `private/` directories in up to four ancestors of the deployed root, never inside a public_html
     * tree (a subdomain can live in public_html/<sub>, so its siblings would be web-reachable).
     *
     * @return list<string>
     */
    private static function privateDirectories(string $root): array
    {
        $dirs = [];
        $dir = $root;
        for ($i = 0; $i < 4; $i++) {
            $dir = dirname($dir);
            if ($dir === '/' || $dir === '.' || $dir === DIRECTORY_SEPARATOR) {
                break;
            }
            if (preg_match('#(^|/)public_html(/|$)#', $dir) === 1) {
                continue;
            }
            $dirs[] = $dir . DIRECTORY_SEPARATOR . 'private';
        }
        return $dirs;
    }

    /**
     * Directory for the owner-login attempt throttle: CONTROLBOT_OWNER_RATE_LIMIT_DIR, else
     * `owner-rate-limit/` inside the first writable private/ directory outside public_html (created 0700).
     */
    public static function rateLimitDirectory(?string $repositoryRoot = null): ?string
    {
        $direct = getenv('CONTROLBOT_OWNER_RATE_LIMIT_DIR');
        if (is_string($direct) && $direct !== '') {
            return $direct;
        }
        $root = realpath($repositoryRoot ?? dirname(__DIR__));
        if ($root === false) {
            return null;
        }
        foreach (self::privateDirectories($root) as $private) {
            if (!is_dir($private) || is_link($private) || !is_writable($private)) {
                continue;
            }
            $real = realpath($private);
            if ($real === false || $real === $root || str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $dir = $real . DIRECTORY_SEPARATOR . 'owner-rate-limit';
            if (!is_dir($dir) && !@mkdir($dir, 0700)) {
                continue;
            }
            if (is_dir($dir) && !is_link($dir) && is_writable($dir)) {
                return $dir;
            }
        }
        return null;
    }

    private static function loadFile(string $path, string $root): ?string
    {
        if ($path[0] !== '/' || str_contains($path, "\0")) {
            return null;
        }
        $real = realpath($path);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            return null;
        }
        if ($real === $root || str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return null; // a secret inside the deployed tree could be served or committed
        }
        // Resolve symlinks before deciding: the file must never be web-served.
        if (preg_match('#(^|/)public_html(/|$)#', $real) === 1) {
            return null;
        }
        $size = filesize($real);
        if ($size === false || $size < 1 || $size > self::MAX_BYTES) {
            return null;
        }
        // Bound the actual read too: the file can grow after filesize() is checked.
        $content = file_get_contents($real, false, null, 0, self::MAX_BYTES + 1);
        if (!is_string($content) || strlen($content) > self::MAX_BYTES) {
            return null;
        }
        $value = trim($content);
        return $value === '' ? null : $value;
    }

    /**
     * Owner login identifier: environment variable, then private/owner-login, then the public default.
     * Only identifies the owner; the password hash and the vault key remain mandatory elsewhere.
     */
    public static function ownerLogin(?string $repositoryRoot = null): string
    {
        // A malformed env value must not shadow a valid protected file.
        $direct = getenv('CONTROLBOT_OWNER_LOGIN');
        if (is_string($direct) && preg_match('/^[A-Za-z0-9._@-]{1,64}$/D', $direct) === 1) {
            return $direct;
        }
        $file = self::readFromFile('CONTROLBOT_OWNER_LOGIN', $repositoryRoot);
        if (is_string($file) && preg_match('/^[A-Za-z0-9._@-]{1,64}$/D', $file) === 1) {
            return $file;
        }
        return self::DEFAULT_OWNER_LOGIN;
    }
}
