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

    public static function read(string $name, ?string $repositoryRoot = null): ?string
    {
        $direct = getenv($name);
        if (is_string($direct) && $direct !== '') {
            return $direct;
        }
        $path = getenv($name . '_FILE');
        if (!is_string($path) || $path === '' || $path[0] !== '/' || str_contains($path, "\0")) {
            return null;
        }
        $real = realpath($path);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            return null;
        }
        $root = $repositoryRoot !== null ? realpath($repositoryRoot) : false;
        if ($root !== false && ($real === $root || str_starts_with($real, $root . DIRECTORY_SEPARATOR))) {
            return null; // a secret inside the deployed tree could be served or committed
        }
        $size = filesize($real);
        if ($size === false || $size < 1 || $size > self::MAX_BYTES) {
            return null;
        }
        $content = file_get_contents($real);
        $value = is_string($content) ? trim($content) : '';
        return $value === '' ? null : $value;
    }
}
