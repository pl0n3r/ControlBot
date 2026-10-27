<?php
declare(strict_types=1);

namespace ControlBot\Hostinger;

/**
 * Offline-only Hostinger HTTPS boundary: NO network adapter, tokens or side effects.
 * HTTP 2xx alone never proves a mutation or creates an approved/closed state.
 */
final class HostingerHttpsTransportSimulator
{
    /** @var array<string,array{digest:string,receipt:array<string,mixed>}> */
    private array $seen = [];
    private int $attempts = 0;

    public function attemptCount(): int
    {
        return $this->attempts;
    }

    /**
     * @param array<string,mixed> $command Synthetic tenant, operation, method and idempotency key
     * @param array<string,mixed> $response An untrusted synthetic HTTPS observation
     * @return array<string,mixed> Whitelisted safe receipt; never echoes response or secrets
     */
    public function simulate(array $command, array $response): array
    {
        if (!self::validCommand($command)) {
            return self::receipt(null, null, 'UNKNOWN', 'invalid_fixture_input', 0);
        }

        $tenant = $command['tenant_ref'];
        $operation = $command['operation_ref'];
        $key = $command['idempotency_key'];
        $digest = hash('sha256', json_encode([
            $tenant, $operation, $command['method']
        ], JSON_THROW_ON_ERROR));

        if (isset($this->seen[$key])) {
            if (hash_equals($this->seen[$key]['digest'], $digest)) {
                // Never replay a mutation after timeout, 5xx or even an apparent 2xx.
                return $this->seen[$key]['receipt'];
            }
            // Do not reveal the cached record belonging to another tenant.
            return self::receipt($tenant, $operation, 'REJECTED', 'idempotency_conflict', 0);
        }

        ++$this->attempts;
        [$state, $reason] = self::classify($response);
        $receipt = self::receipt($tenant, $operation, $state, $reason, 1);
        $this->seen[$key] = ['digest' => $digest, 'receipt' => $receipt];
        return $receipt;
    }

    /** @param array<string,mixed> $input */
    private static function validCommand(array $input): bool
    {
        if (!self::exactKeys($input, ['tenant_ref','operation_ref','method','idempotency_key'])) {
            return false;
        }
        foreach (['tenant_ref','operation_ref','method','idempotency_key'] as $name) {
            if (!is_string($input[$name])) {
                return false;
            }
        }
        // No documents, emails, phone numbers, names, URLs, tokens or arbitrary payloads.
        return preg_match('~^fixture:tenant/[a-z][a-z0-9_-]{0,31}$~D', $input['tenant_ref']) === 1
            && preg_match('~^fixture:mutation/[a-z][a-z0-9_-]{0,31}$~D', $input['operation_ref']) === 1
            && in_array($input['method'], ['POST','PATCH','DELETE'], true)
            && preg_match('~^fixture:key/[a-z][a-z0-9_-]{7,40}$~D', $input['idempotency_key']) === 1;
    }

    /** @param array<string,mixed> $response @return array{string,string} */
    private static function classify(array $response): array
    {
        if (!isset($response['kind']) || !is_string($response['kind'])) {
            return ['UNKNOWN', 'missing_transport_evidence'];
        }
        $kind = $response['kind'];
        if (in_array($kind, ['timeout', 'network_error', 'ambiguous', 'certificate_invalid'], true)) {
            if (!self::exactKeys($response, ['kind'])) {
                return ['UNKNOWN', 'invalid_transport_evidence'];
            }
            return match ($kind) {
                'timeout' => ['UNKNOWN', 'timeout_uncertain'],
                'network_error' => ['UNKNOWN', 'network_uncertain'],
                'ambiguous' => ['UNKNOWN', 'ambiguous_response'],
                default => ['REJECTED', 'certificate_invalid'],
            };
        }
        if (!in_array($kind, ['http', 'accepted', 'redirect'], true)
            || !self::exactKeys($response, ['kind','status_code','tls_verified','redirect_count'])
            || !is_int($response['status_code'])
            || $response['status_code'] < 100 || $response['status_code'] > 599
            || !is_int($response['redirect_count'])
            || $response['tls_verified'] !== true
        ) {
            return ['UNKNOWN', 'invalid_transport_evidence'];
        }
        if ($response['redirect_count'] !== 0 || ($response['status_code'] >= 300 && $response['status_code'] < 400)) {
            return ['REJECTED', 'redirect_blocked'];
        }
        if ($kind === 'redirect') {
            return ['UNKNOWN', 'invalid_transport_evidence'];
        }
        if ($kind === 'accepted') {
            return $response['status_code'] >= 200 && $response['status_code'] < 300
                ? ['UNKNOWN', 'http_2xx_not_proof_of_mutation']
                : ['UNKNOWN', 'invalid_transport_evidence'];
        }
        if ($response['status_code'] >= 400 && $response['status_code'] < 500) {
            return ['REJECTED', 'http_4xx'];
        }
        if ($response['status_code'] >= 500) {
            return ['UNKNOWN', 'http_5xx_uncertain'];
        }
        return ['UNKNOWN', 'http_status_not_proof'];
    }

    /** @param array<string,mixed> $input @param list<string> $keys */
    private static function exactKeys(array $input, array $keys): bool
    {
        $actual = array_keys($input);
        sort($actual);
        sort($keys);
        return $actual === $keys;
    }

    /** @return array<string,mixed> */
    private static function receipt(?string $tenant, ?string $operation, string $state, string $reason, int $attemptCount): array
    {
        return [
            'tenant_ref' => $tenant,
            'operation_ref' => $operation,
            'status' => $state,
            'reason' => $reason,
            'approved' => false,
            'closed' => false,
            'retry_allowed' => false,
            'attempt_count' => $attemptCount,
            'evidence_ref' => null,
            'official' => false,
        ];
    }
}
