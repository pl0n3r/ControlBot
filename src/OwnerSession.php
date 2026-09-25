<?php
declare(strict_types=1);

namespace ControlBot\Security;

use ControlBot\Approvals\OwnerContext;
use InvalidArgumentException;
use RuntimeException;

final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function code(string $base32Secret, int $timestamp, int $period = 30, int $digits = 6): string
    {
        if ($timestamp < 0 || $period < 1 || $digits < 6 || $digits > 8) {
            throw new InvalidArgumentException('Parámetros TOTP inválidos.');
        }
        $secret = self::decodeBase32($base32Secret);
        $counter = intdiv($timestamp, $period);
        $high = intdiv($counter, 0x100000000);
        $low = $counter % 0x100000000;
        $digest = hash_hmac('sha1', pack('N2', $high, $low), $secret, true);
        $offset = ord($digest[19]) & 0x0f;
        $binary = ((ord($digest[$offset]) & 0x7f) << 24)
            | ((ord($digest[$offset + 1]) & 0xff) << 16)
            | ((ord($digest[$offset + 2]) & 0xff) << 8)
            | (ord($digest[$offset + 3]) & 0xff);
        $modulo = 10 ** $digits;

        return str_pad((string) ($binary % $modulo), $digits, '0', STR_PAD_LEFT);
    }

    public static function verify(
        string $base32Secret,
        string $candidate,
        int $timestamp,
        int $window = 1,
        int $period = 30,
        int $digits = 6,
    ): bool {
        if (preg_match('/^[0-9]{' . $digits . '}$/', $candidate) !== 1 || $window < 0 || $window > 2) {
            return false;
        }

        for ($offset = -$window; $offset <= $window; $offset++) {
            $time = $timestamp + ($offset * $period);
            if ($time < 0) {
                continue;
            }
            if (hash_equals(self::code($base32Secret, $time, $period, $digits), $candidate)) {
                return true;
            }
        }
        return false;
    }

    private static function decodeBase32(string $encoded): string
    {
        $normalized = strtoupper(str_replace([' ', '-'], '', trim($encoded)));
        if ($normalized === '' || preg_match('/^[A-Z2-7]+=*$/', $normalized) !== 1) {
            throw new InvalidArgumentException('Secreto TOTP inválido.');
        }
        $normalized = rtrim($normalized, '=');
        $bits = '';
        foreach (str_split($normalized) as $char) {
            $value = strpos(self::ALPHABET, $char);
            if ($value === false) {
                throw new InvalidArgumentException('Secreto TOTP inválido.');
            }
            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';
        for ($i = 0, $length = strlen($bits); $i + 8 <= $length; $i += 8) {
            $decoded .= chr(bindec(substr($bits, $i, 8)));
        }
        if ($decoded === '') {
            throw new InvalidArgumentException('Secreto TOTP inválido.');
        }
        return $decoded;
    }
}

final class TokenVault
{
    private readonly string $key;

    public function __construct(string $base64Key)
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            throw new RuntimeException('Sodium no disponible.');
        }
        $key = base64_decode($base64Key, true);
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new InvalidArgumentException('Clave de cifrado inválida.');
        }
        $this->key = $key;
    }

    public function seal(string $token): string
    {
        if ($token === '' || strlen($token) > 4096) {
            throw new InvalidArgumentException('Token GitHub inválido.');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($token, $nonce, $this->key);
        return base64_encode($nonce . $ciphertext);
    }

    public function open(string $sealed): string
    {
        $payload = base64_decode($sealed, true);
        if (!is_string($payload) || strlen($payload) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Credencial cifrada inválida.');
        }
        $nonce = substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);
        if (!is_string($plain) || $plain === '') {
            throw new RuntimeException('Credencial cifrada inválida.');
        }
        return $plain;
    }
}

final class OwnerSessionService
{
    public function __construct(
        private readonly string $expectedOwner,
        private readonly TokenVault $vault,
    ) {
        if (preg_match('/^[A-Za-z0-9-]{1,39}$/', $expectedOwner) !== 1) {
            throw new InvalidArgumentException('Owner configurado inválido.');
        }
    }

    public function establishTrustedOAuthSession(array &$session, string $githubLogin, string $githubToken): void
    {
        if (!hash_equals($this->expectedOwner, $githubLogin)) {
            throw new RuntimeException('La identidad GitHub no corresponde al dueño.');
        }

        $session = [
            '_owner_login' => $githubLogin,
            '_github_token_sealed' => $this->vault->seal($githubToken),
            '_csrf' => self::base64Url(random_bytes(32)),
        ];
    }

    public function csrfToken(array $session): string
    {
        $token = $session['_csrf'] ?? null;
        if (!is_string($token) || strlen($token) < 32) {
            throw new RuntimeException('Sesión CSRF inválida.');
        }
        return $token;
    }

    public function reauthenticateTotp(array &$session, string $code, string $base32Secret, int $now): void
    {
        $this->assertOwnerSession($session);
        if (!Totp::verify($base32Secret, $code, $now)) {
            unset($session['_reauthenticated_at']);
            throw new RuntimeException('Código TOTP inválido.');
        }
        $session['_reauthenticated_at'] = $now;
    }

    public function assertCsrf(array $session, string $provided): void
    {
        $expected = $this->csrfToken($session);
        if ($provided === '' || !hash_equals($expected, $provided)) {
            throw new RuntimeException('CSRF inválido.');
        }
    }

    public function contextFromRequest(array $session, array $request, int $now): OwnerContext
    {
        $csrf = $request['_csrf'] ?? null;
        if (!is_string($csrf)) {
            throw new RuntimeException('CSRF inválido.');
        }
        $this->assertCsrf($session, $csrf);
        $this->assertOwnerSession($session);

        $reauthenticatedAt = $session['_reauthenticated_at'] ?? 0;
        if (!is_int($reauthenticatedAt)) {
            throw new RuntimeException('Estado de reautenticación inválido.');
        }

        $context = new OwnerContext($this->expectedOwner, true, $reauthenticatedAt);
        $context->assertFresh($now);
        return $context;
    }

    public function githubToken(array $session): string
    {
        $this->assertOwnerSession($session);
        $sealed = $session['_github_token_sealed'] ?? null;
        if (!is_string($sealed)) {
            throw new RuntimeException('Credencial GitHub ausente.');
        }
        return $this->vault->open($sealed);
    }

    private function assertOwnerSession(array $session): void
    {
        $login = $session['_owner_login'] ?? null;
        if (!is_string($login) || !hash_equals($this->expectedOwner, $login)) {
            throw new RuntimeException('Sesión del dueño inválida.');
        }
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
