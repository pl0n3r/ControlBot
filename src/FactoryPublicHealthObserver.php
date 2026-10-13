<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;
use Throwable;

/**
 * Public, read-only health observation. The trusted endpoint allowlist MUST
 * originate from server-side configuration, never an Issue or browser input.
 * No observation is evidence of production_green.
 */
final class FactoryPublicHealthObserver
{
    private const REPOS = ['Condor', 'brvtal', 'GrindFlow', 'ControlBot'];
    private const TIMEOUT_MS = 2000;
    private const MAX_BYTES = 4096;
    private const MAX_AGE_SECONDS = 300;

    public static function collect(
        array $approved,
        array $configured,
        callable $resolve,
        callable $transport,
        int $now
    ): array {
        if ($now < 1 || array_diff(array_keys($approved), self::REPOS)
            || array_diff(array_keys($configured), self::REPOS)) {
            throw new InvalidArgumentException('public health configuration invalid');
        }
        $rows = [];
        foreach (self::REPOS as $repo) {
            $url = $configured[$repo] ?? null;
            $allowed = $approved[$repo] ?? null;
            if ($url === null || $url === '') {
                $rows[] = self::unknown($repo, 'no_public_endpoint');
                continue;
            }
            if (!is_string($url) || !is_string($allowed) || !hash_equals($allowed, $url)) {
                $rows[] = self::unknown($repo, 'endpoint_not_allowlisted');
                continue;
            }
            $host = self::safeHost($url);
            if ($host === null) {
                $rows[] = self::unknown($repo, 'endpoint_unsafe');
                continue;
            }
            try {
                $ips = $resolve($host);
                if (!is_array($ips) || !$ips || !array_is_list($ips) || count($ips) > 16) {
                    throw new InvalidArgumentException('dns_invalid');
                }
                foreach ($ips as $ip) {
                    if (!self::publicIpv4($ip)) {
                        throw new InvalidArgumentException('dns_not_public');
                    }
                }
                $response = $transport($url, $ips[0], [
                    'method' => 'GET',
                    'timeout_ms' => self::TIMEOUT_MS,
                    'max_bytes' => self::MAX_BYTES,
                    'follow_redirects' => false,
                    'send_credentials' => false,
                ]);
                if (!is_array($response) || array_keys($response) !== [
                    'status', 'latency_ms', 'bytes', 'redirected'
                ]) {
                    throw new InvalidArgumentException('transport_contract_invalid');
                }
                if (!is_int($response['status']) || $response['status'] < 100
                    || $response['status'] > 599 || !is_int($response['latency_ms'])
                    || $response['latency_ms'] < 0 || $response['latency_ms'] > self::TIMEOUT_MS
                    || !is_int($response['bytes']) || $response['bytes'] < 0
                    || $response['bytes'] > self::MAX_BYTES
                    || $response['redirected'] !== false) {
                    throw new InvalidArgumentException('transport_response_unsafe');
                }
                $ok = $response['status'] >= 200 && $response['status'] < 300;
                $rows[] = [
                    'repository_ref' => 'pl0n3r/' . $repo,
                    'endpoint' => $url,
                    'status' => $ok ? 'reachable' : 'unreachable',
                    'http_status' => $response['status'],
                    'latency_ms' => $response['latency_ms'],
                    'observed_at' => $now,
                    'source_age_seconds' => 0,
                    'reason' => $ok ? 'http_success' : 'http_non_success',
                ];
            } catch (Throwable) {
                // Do not persist exception strings, DNS answers, bodies, or secrets.
                $rows[] = self::unknown($repo, 'probe_failed');
            }
        }
        return $rows;
    }

    /** Mark prior evidence stale rather than reclassifying it as a fresh pass. */
    public static function age(array $rows, int $now): array
    {
        if ($now < 1 || count($rows) !== 4) {
            throw new InvalidArgumentException('public health age invalid');
        }
        foreach ($rows as &$row) {
            if (!is_array($row) || !array_key_exists('observed_at', $row)) {
                throw new InvalidArgumentException('public health row invalid');
            }
            if ($row['observed_at'] === null) {
                continue;
            }
            if (!is_int($row['observed_at']) || $row['observed_at'] > $now
                || $row['observed_at'] < 1) {
                throw new InvalidArgumentException('public health time invalid');
            }
            $row['source_age_seconds'] = $now - $row['observed_at'];
            if ($row['source_age_seconds'] > self::MAX_AGE_SECONDS) {
                $row['status'] = 'stale';
                $row['reason'] = 'evidence_stale';
            }
        }
        unset($row);
        return $rows;
    }

    public static function safeHost(string $url): ?string
    {
        // Exact HTTPS origin and an ordinary path only. No credentials, ports,
        // query, fragment, IP literals, backslashes, unicode or percent escapes.
        if (strlen($url) > 240 || preg_match(
            '#^https://([a-z0-9.-]+)(/[a-zA-Z0-9/_~.-]*)?$#D',
            $url, $m
        ) !== 1) {
            return null;
        }
        $host = $m[1];
        if (preg_match('~^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$~D', $host) !== 1
            || filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }
        return $host;
    }

    public static function publicIpv4(mixed $ip): bool
    {
        return is_string($ip) && filter_var(
            $ip, FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /** Production adapter: DNS pinned to the already validated public IPv4. */
    public static function pinnedGet(string $url, string $ip, array $limits): array
    {
        $host = self::safeHost($url);
        if ($host === null || !self::publicIpv4($ip) || !function_exists('curl_init')
            || ($limits['method'] ?? null) !== 'GET'
            || ($limits['follow_redirects'] ?? null) !== false
            || ($limits['send_credentials'] ?? null) !== false
            || ($limits['timeout_ms'] ?? null) !== self::TIMEOUT_MS
            || ($limits['max_bytes'] ?? null) !== self::MAX_BYTES) {
            throw new InvalidArgumentException('unsafe transport configuration');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new InvalidArgumentException('transport unavailable');
        }
        $count = 0;
        $oversized = false;
        $started = microtime(true);
        try {
            if (!curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_HTTPGET => true,
                CURLOPT_PROXY => '',
                CURLOPT_CONNECTTIMEOUT_MS => 1000,
                CURLOPT_TIMEOUT_MS => self::TIMEOUT_MS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_RESOLVE => [$host . ':443:' . $ip],
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'Cache-Control: no-store'],
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$count, &$oversized): int {
                    $count += strlen($chunk);
                    if ($count > self::MAX_BYTES) {
                        $oversized = true;
                        return 0; // stop reading; never store the response body
                    }
                    return strlen($chunk);
                },
            ])) {
                throw new InvalidArgumentException('transport options unavailable');
            }
            $success = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $elapsed = (int) round((microtime(true) - $started) * 1000);
        } finally {
            curl_close($ch);
        }
        if ($success !== true || $oversized || $elapsed > self::TIMEOUT_MS) {
            throw new InvalidArgumentException('public probe failed');
        }
        return ['status' => $code, 'latency_ms' => $elapsed,
            'bytes' => $count, 'redirected' => false];
    }

    private static function unknown(string $repo, string $reason): array
    {
        return [
            'repository_ref' => 'pl0n3r/' . $repo,
            'endpoint' => 'UNKNOWN',
            'status' => 'unknown',
            'http_status' => null,
            'latency_ms' => null,
            'observed_at' => null,
            'source_age_seconds' => null,
            'reason' => $reason,
        ];
    }
}
