<?php
declare(strict_types=1);

namespace ControlBot\Security;

require_once __DIR__ . '/OwnerSession.php';

use RuntimeException;
use Throwable;

/**
 * Estado local del propietario en directorio privado. Todos los cambios son
 * transaccionales por flock; nunca guarda OTP, códigos ni tokens en claro.
 * Las llamadas del panel deben haber superado autorización owner y CSRF.
 */
final class OwnerLocalAuthStore
{
    private string $path;
    private ?string $key;

    public function __construct(string $directory, string $owner, ?string $base64Key)
    {
        if (!str_starts_with($directory, '/') || is_link($directory)
            || !is_dir($directory) || !is_writable($directory)
            || preg_match('/^[A-Za-z0-9-]{1,39}$/D', $owner) !== 1) {
            throw new RuntimeException('Private owner state unavailable.');
        }
        $dir = realpath($directory);
        if (!is_string($dir)) {
            throw new RuntimeException('Private owner state unavailable.');
        }
        $repoRoot = realpath(dirname(__DIR__));
        if (is_string($repoRoot) && ($dir === $repoRoot
            || str_starts_with($dir, $repoRoot . DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('Owner state must be outside web tree.');
        }
        $this->path = $dir . '/cb-auth-' . hash('sha256', 'owner:' . $owner) . '.json';
        $decoded = is_string($base64Key) ? base64_decode($base64Key, true) : false;
        $this->key = is_string($decoded) && strlen($decoded) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES
            ? $decoded : null;
    }

    /** @return array{enabled:bool,required:bool,version:int} */
    public function status(): array
    {
        return $this->transaction(static fn (array &$s): array => [
            'enabled' => $s['secret'] !== '',
            'required' => $s['required'],
            'version' => $s['version'],
        ]);
    }

    public function validVersion(mixed $version): bool
    {
        return is_int($version) && $version >= 0
            && $this->transaction(static fn (array &$s): bool => $s['version'] === $version);
    }

    public function login(int $now): int
    {
        return $this->transaction(function (array &$s) use ($now): int {
            self::auditEvent($s, 'login', $now);
            return $s['version'];
        });
    }

    public function logout(int $now): void
    {
        $this->transaction(function (array &$s) use ($now): void {
            self::auditEvent($s, 'logout', $now);
        });
    }

    public function revokeAll(int $now): void
    {
        $this->transaction(function (array &$s) use ($now): void {
            $s['version']++;
            self::auditEvent($s, 'revoke', $now);
        });
    }

    public function beginEnrollment(int $now): string
    {
        $secret = self::base32(random_bytes(20));
        $this->transaction(function (array &$s) use ($secret, $now): void {
            if ($s['secret'] !== '') {
                throw new RuntimeException('TOTP already active.');
            }
            $s['pending'] = $this->seal($secret);
            $s['pending_until'] = $now + 300;
            self::auditEvent($s, 'totp_setup_started', $now);
        });
        return $secret;
    }

    /** Returns recovery codes exactly once; store contains only their hashes. */
    public function confirmEnrollment(string $code, int $now): array
    {
        return $this->transaction(function (array &$s) use ($code, $now): array {
            if ($s['secret'] !== '' || $s['pending'] === '' || $now > $s['pending_until']
                || $now <= 0 || !$this->matches($this->unseal($s['pending']), $code, $now, -1)) {
                throw new RuntimeException('TOTP setup invalid.');
            }
            $codes = [];
            for ($i = 0; $i < 8; $i++) {
                $codes[] = strtoupper(bin2hex(random_bytes(12)));
            }
            $s['recovery'] = array_map(static fn (string $v): string => hash('sha256', $v), $codes);
            $s['secret'] = $s['pending'];
            $s['pending'] = '';
            $s['pending_until'] = 0;
            $s['last_counter'] = -1;
            $s['version']++;
            self::auditEvent($s, 'totp_enabled', $now);
            return $codes;
        });
    }

    public function verify(string $code, int $now): bool
    {
        return $this->transaction(function (array &$s) use ($code, $now): bool {
            if ($s['required'] && $s['secret'] === '') {
                return false;
            }
            if ($s['secret'] === '') {
                return $code === '';
            }
            if (!$this->matches($this->unseal($s['secret']), $code, $now, $s['last_counter'])) {
                if (preg_match('/^[A-Fa-f0-9]{24}$/D', $code) !== 1) {
                    return false;
                }
                $hash = hash('sha256', strtoupper($code));
                $i = array_search($hash, $s['recovery'], true);
                if ($i === false) {
                    return false;
                }
                unset($s['recovery'][$i]);
                $s['recovery'] = array_values($s['recovery']);
                self::auditEvent($s, 'recovery_used', $now);
                return true;
            }
            $s['last_counter'] = $this->matchedCounter($this->unseal($s['secret']), $code, $now);
            self::auditEvent($s, 'totp_used', $now);
            return true;
        });
    }

    public function disable(string $code, int $now): void
    {
        $this->transaction(function (array &$s) use ($code, $now): void {
            if ($s['required'] || $s['secret'] === ''
                || !$this->matches($this->unseal($s['secret']), $code, $now, $s['last_counter'])) {
                throw new RuntimeException('TOTP disable forbidden.');
            }
            $s['secret'] = '';
            $s['pending'] = '';
            $s['pending_until'] = 0;
            $s['recovery'] = [];
            $s['last_counter'] = -1;
            $s['version']++;
            self::auditEvent($s, 'totp_disabled', $now);
        });
    }

    public function setRequired(bool $required, string $code, int $now): void
    {
        $this->transaction(function (array &$s) use ($required, $code, $now): void {
            if ($s['secret'] === '' || !$this->matches(
                $this->unseal($s['secret']), $code, $now, $s['last_counter']
            )) {
                throw new RuntimeException('TOTP policy change forbidden.');
            }
            $s['required'] = $required;
            $s['last_counter'] = $this->matchedCounter($this->unseal($s['secret']), $code, $now);
            $s['version']++;
            self::auditEvent($s, 'totp_policy', $now);
        });
    }

    public function auditTrail(): array
    {
        return $this->transaction(static fn (array &$s): array => $s['audit']);
    }

    private function seal(string $plain): string
    {
        if ($this->key === null) {
            throw new RuntimeException('TOTP key unavailable.');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    private function unseal(string $sealed): string
    {
        if ($this->key === null) {
            throw new RuntimeException('TOTP key unavailable.');
        }
        $raw = base64_decode($sealed, true);
        if (!is_string($raw) || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 17) {
            throw new RuntimeException('TOTP ciphertext invalid.');
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key,
        );
        if (!is_string($plain)) {
            throw new RuntimeException('TOTP ciphertext invalid.');
        }
        return $plain;
    }

    private function matchedCounter(string $secret, string $code, int $now): int
    {
        if ($now < 1 || preg_match('/^[0-9]{6}$/D', $code) !== 1) {
            return -1;
        }
        for ($offset = -1; $offset <= 1; $offset++) {
            $counter = intdiv($now, 30) + $offset;
            if ($counter >= 0 && hash_equals(Totp::code($secret, $counter * 30), $code)) {
                return $counter;
            }
        }
        return -1;
    }

    private function matches(string $secret, string $code, int $now, int $last): bool
    {
        return $this->matchedCounter($secret, $code, $now) > $last;
    }

    private static function base32(string $bytes): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $result = '';
        foreach (str_split($bits, 5) as $chunk) {
            $result .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
        }
        return $result;
    }

    private static function auditEvent(array &$s, string $event, int $now): void
    {
        if ($now < 1) {
            throw new RuntimeException('Clock invalid.');
        }
        $s['audit'][] = ['event' => $event, 'time' => $now];
        $s['audit'] = array_slice($s['audit'], -80);
    }

    private function transaction(callable $fn): mixed
    {
        if (is_link($this->path) || (file_exists($this->path) && !is_file($this->path))) {
            throw new RuntimeException('Owner state file invalid.');
        }
        $handle = @fopen($this->path, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Owner state unavailable.');
        }
        try {
            @chmod($this->path, 0600);
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Owner state lock failed.');
            }
            rewind($handle);
            $raw = stream_get_contents($handle, 65537);
            if (!is_string($raw) || strlen($raw) > 65536) {
                throw new RuntimeException('Owner state too large.');
            }
            $s = $raw === '' ? [
                'version' => 0, 'secret' => '', 'pending' => '', 'pending_until' => 0,
                'required' => false, 'last_counter' => -1, 'recovery' => [], 'audit' => [],
            ] : json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($s) || !is_int($s['version'] ?? null)
                || !is_string($s['secret'] ?? null) || !is_string($s['pending'] ?? null)
                || !is_int($s['pending_until'] ?? null) || !is_bool($s['required'] ?? null)
                || !is_int($s['last_counter'] ?? null) || !is_array($s['recovery'] ?? null)
                || !is_array($s['audit'] ?? null)) {
                throw new RuntimeException('Owner state corrupt.');
            }
            $out = $fn($s);
            $encoded = json_encode($s, JSON_THROW_ON_ERROR);
            if (!is_string($encoded) || strlen($encoded) > 65536) {
                throw new RuntimeException('Owner state too large.');
            }
            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, $encoded) !== strlen($encoded)
                || !fflush($handle)) {
                throw new RuntimeException('Owner state write failed.');
            }
            return $out;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
