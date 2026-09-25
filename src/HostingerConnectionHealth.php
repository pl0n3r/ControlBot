<?php
declare(strict_types=1);

namespace ControlBot\Production;

use RuntimeException;
use Throwable;

final class HostingerConnectionHealth
{
    public function __construct(private readonly \Closure $probe) {}

    public function verify(ConnectionProfile $profile, int $now): array
    {
        $request = $profile->probeRequest();
        try {
            $response = ($this->probe)($request);
        } catch (Throwable) {
            return self::unavailable($profile, $now, 'transport-failed');
        }

        if (
            !is_array($response)
            || ($response['reachable'] ?? null) !== true
            || !is_string($response['fingerprint'] ?? null)
        ) {
            return self::unavailable($profile, $now, 'probe-invalid');
        }

        if (!$profile->matchesFingerprint($response['fingerprint'])) {
            return self::unavailable($profile, $now, 'identity-mismatch');
        }

        $connected = $profile->markConnected($now);
        return [
            'profile' => $connected,
            'health' => [
                'status' => 'connected',
                'healthy' => true,
                'identity_confirmed' => true,
                'destination' => $connected->destination(),
                'effect' => 'read',
                'code' => 'identity-confirmed',
            ],
        ];
    }

    public function requireConnected(ConnectionProfile $profile): void
    {
        if ($profile->status() !== 'connected') {
            throw new RuntimeException('Conexión Hostinger no verificada.');
        }
    }

    private static function unavailable(ConnectionProfile $profile, int $now, string $code): array
    {
        $unavailable = $profile->markUnavailable($now);
        return [
            'profile' => $unavailable,
            'health' => [
                'status' => 'unavailable',
                'healthy' => false,
                'identity_confirmed' => false,
                'destination' => $unavailable->destination(),
                'effect' => 'read',
                'code' => $code,
            ],
        ];
    }
}
