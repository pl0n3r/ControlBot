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

    /** Conventional private files (sibling `private/` of the deployed root) for the two server secrets. */
    private const CONVENTIONAL_FILES = [
        'CONTROLBOT_VAULT_KEY' => 'vault.key',
        'CONTROLBOT_OWNER_PASSWORD_HASH' => 'owner-password.hash',
    ];

    public static function read(string $name, ?string $repositoryRoot = null): ?string
    {
        $direct = getenv($name);
        if (is_string($direct) && $direct !== '') {
            return $direct;
        }
        $path = getenv($name . '_FILE');
        if (!is_string($path) || $path === '') {
            $path = self::conventionalPath($name, $repositoryRoot);
        }
        if ($path === null || $path[0] !== '/' || str_contains($path, "\0")) {
            return null;
        }
        $real = realpath($path);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            return null;
        }
        $root = realpath($repositoryRoot ?? dirname(__DIR__));
        if ($root === false) {
            return null; // unknown repository root: never skip the containment check
        }
        if ($real === $root || str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return null; // a secret inside the deployed tree could be served or committed
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

    private static function conventionalPath(string $name, ?string $repositoryRoot): ?string
    {
        $file = self::CONVENTIONAL_FILES[$name] ?? null;
        $root = realpath($repositoryRoot ?? dirname(__DIR__));
        if ($file === null || $root === false) {
            return null;
        }
        return dirname($root) . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . $file;
    }
}
