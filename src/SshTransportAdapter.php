<?php
declare(strict_types=1);

namespace ControlBot\Production;

use Closure;
use InvalidArgumentException;
use Throwable;

final class SshTransportAdapter
{
    private const FIELDS = [
        'operation_id', 'capability', 'effect', 'timeout_ms', 'destination',
        'project', 'environment', 'resource', 'run_id', 'username_ref',
        'expected_fingerprint', 'secret_kind',
    ];

    private readonly Closure $usernameResolver;
    private readonly Closure $client;

    public function __construct(callable $usernameResolver, callable $client)
    {
        $this->usernameResolver = Closure::fromCallable($usernameResolver);
        $this->client = Closure::fromCallable($client);
    }

    public function __invoke(array $descriptor, string $secret): array
    {
        if (strlen($secret) < 12 || strlen($secret) > 4096
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $secret) === 1) {
            return self::result('failed', 'ssh_secret_invalid', 'SSH secret invalid.');
        }

        try {
            $request = $this->request($descriptor);
            $username = ($this->usernameResolver)($descriptor['username_ref']);
            if (!is_string($username)
                || preg_match('/^[A-Za-z_][A-Za-z0-9._-]{0,63}$/D', $username) !== 1) {
                return self::result('failed', 'ssh_username_unavailable', 'SSH username unavailable.');
            }
            $request['username'] = $username;
        } catch (InvalidArgumentException) {
            return self::result('failed', 'ssh_descriptor_invalid', 'SSH descriptor invalid.');
        } catch (Throwable) {
            return self::result('failed', 'ssh_username_unavailable', 'SSH username unavailable.');
        }

        try {
            $raw = ($this->client)($request, $secret);
        } catch (Throwable) {
            return self::result('failed', 'ssh_client_error', 'SSH client failed.');
        }

        return $this->normalize($raw, $request['timeout_ms'], $secret);
    }

    private function request(array $d): array
    {
        if (array_is_list($d)
            || count($d) !== count(self::FIELDS)
            || array_diff(self::FIELDS, array_keys($d)) !== []) {
            throw new InvalidArgumentException('descriptor');
        }

        foreach ([
            'operation_id', 'capability', 'effect', 'destination', 'project',
            'environment', 'resource', 'run_id', 'username_ref',
            'expected_fingerprint', 'secret_kind',
        ] as $key) {
            if (!is_string($d[$key]) || $d[$key] === '' || strlen($d[$key]) > 180) {
                throw new InvalidArgumentException($key);
            }
        }

        try {
            $operation = ProductionOperation::fromId($d['operation_id']);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('operation');
        }

        if ($d['capability'] !== $operation->capability()
            || $d['effect'] !== $operation->effect()
            || !is_int($d['timeout_ms'])
            || $d['timeout_ms'] !== $operation->timeoutMs()
            || !in_array($d['secret_kind'], ['password', 'private_key'], true)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{2,159}$/D', $d['username_ref']) !== 1
            || preg_match('/^SHA256:[A-Za-z0-9+\/]{16,86}={0,2}$/D', $d['expected_fingerprint']) !== 1
            || preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $d['project']) !== 1
            || preg_match('/^[a-z][a-z0-9-]{1,31}$/D', $d['environment']) !== 1
            || preg_match('/^[a-z][a-z0-9._:-]{1,119}$/D', $d['resource']) !== 1
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $d['run_id']) !== 1) {
            throw new InvalidArgumentException('context');
        }

        [$host, $port] = self::destination($d['destination']);

        return [
            'version' => 1,
            'operation_id' => $operation->operationId(),
            'capability' => $operation->capability(),
            'effect' => $operation->effect(),
            'timeout_ms' => $operation->timeoutMs(),
            'host' => $host,
            'port' => $port,
            'expected_fingerprint' => $d['expected_fingerprint'],
            'secret_kind' => $d['secret_kind'],
            'project' => $d['project'],
            'environment' => $d['environment'],
            'resource' => $d['resource'],
            'run_id' => $d['run_id'],
        ];
    }

    private static function destination(string $value): array
    {
        $separator = strrpos($value, ':');
        if ($separator === false) {
            throw new InvalidArgumentException('destination');
        }
        $host = substr($value, 0, $separator);
        $portText = substr($value, $separator + 1);
        if ($host === '' || !ctype_digit($portText)) {
            throw new InvalidArgumentException('destination');
        }
        $port = (int) $portText;
        if ($port < 1 || $port > 65535 || (string) $port !== $portText) {
            throw new InvalidArgumentException('destination');
        }
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            if (strlen($host) > 253 || str_contains($host, '..')) {
                throw new InvalidArgumentException('destination');
            }
            foreach (explode('.', $host) as $label) {
                if ($label === '' || strlen($label) > 63
                    || preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/D', $label) !== 1) {
                    throw new InvalidArgumentException('destination');
                }
            }
        }
        return [$host, $port];
    }

    private function normalize(mixed $raw, int $timeoutMs, string $secret): array
    {
        $fields = ['status', 'code', 'summary', 'artifacts', 'duration_ms'];
        if (!is_array($raw)
            || array_is_list($raw)
            || count($raw) !== count($fields)
            || array_diff($fields, array_keys($raw)) !== []
            || !is_string($raw['status'])
            || !in_array($raw['status'], ['success', 'failed', 'cancelled', 'timed_out', 'partial'], true)
            || !is_string($raw['code'])
            || preg_match('/^[a-z][a-z0-9_.-]{1,79}$/D', $raw['code']) !== 1
            || str_contains($raw['code'], $secret)
            || !is_string($raw['summary'])
            || !is_array($raw['artifacts'])
            || !array_is_list($raw['artifacts'])
            || !is_int($raw['duration_ms'])
            || $raw['duration_ms'] < 0) {
            return self::result('failed', 'ssh_client_result_invalid', 'SSH client result invalid.');
        }

        $status = $raw['status'];
        $code = $raw['code'];
        if ($raw['duration_ms'] > $timeoutMs || $code === 'ssh_timeout') {
            $status = 'timed_out';
            $code = 'ssh_timeout';
        } elseif ($code === 'ssh_cancelled') {
            $status = 'cancelled';
        } elseif ($code === 'ssh_partial') {
            $status = 'partial';
        } elseif (in_array($code, ['ssh_host_key_mismatch', 'ssh_auth_failed'], true)) {
            $status = 'failed';
        }

        $summary = substr(str_replace($secret, '[REDACTED]', $raw['summary']), 0, 500);
        $artifacts = [];
        foreach ($raw['artifacts'] as $artifact) {
            if (!is_string($artifact)
                || str_contains($artifact, $secret)
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,159}$/D', $artifact) !== 1) {
                return self::result('failed', 'ssh_client_result_invalid', 'SSH client result invalid.');
            }
            $artifacts[] = $artifact;
        }

        return self::result($status, $code, $summary, $artifacts, $raw['duration_ms']);
    }

    private static function result(
        string $status,
        string $code,
        string $summary,
        array $artifacts = [],
        int $durationMs = 0,
    ): array {
        return [
            'status' => $status,
            'code' => $code,
            'summary' => $summary,
            'artifacts' => $artifacts,
            'duration_ms' => $durationMs,
        ];
    }
}
