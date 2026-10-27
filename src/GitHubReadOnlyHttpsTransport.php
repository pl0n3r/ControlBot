<?php

declare(strict_types=1);

namespace ControlBot\GitHub;

use Closure;
use Throwable;

/**
 * Disconnected HTTPS request policy. Nothing invokes the network itself.
 * Only an explicitly injected sender can observe the bounded GET envelopes.
 */
final class GitHubReadOnlyHttpsTransport
{
    private const HOST = 'api.github.com';
    private const MAX_PAGES = 3;
    private const MAX_PAGE_SIZE = 20;
    private const MAX_PAGE_BYTES = 32768;
    private const MAX_TOTAL_BYTES = 65536;
    private const MAX_ITEMS = 60;
    private array $events = [];

    public function __construct(private readonly Closure $sender, private readonly bool $enabled = false)
    {
    }

    /** @return array<string, mixed> */
    public function read(array $request): array
    {
        if (!$this->enabled) {
            return $this->result('disabled', 0, 0);
        }
        if (!$this->validRequest($request)) {
            return $this->result('invalid_request', 0, 0);
        }
        $repository = $request['repository'];
        $resource = $request['resource'];
        $pageSize = $request['page_size'];
        $count = 0;
        $totalBytes = 0;
        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $path = '/repos/' . $repository . '/' . $resource;
            $envelope = [
                'method' => 'GET',
                'scheme' => 'https',
                'host' => self::HOST,
                'path' => $path,
                'url' => 'https://' . self::HOST . $path . '?per_page=' . $pageSize . '&page=' . $page,
                'tls_verify_peer' => true,
                'tls_verify_host' => 2,
                'follow_redirects' => false,
                'connect_timeout_seconds' => 2,
                'timeout_seconds' => 5,
                'max_response_bytes' => self::MAX_PAGE_BYTES,
                'mode' => 'offline_test_only',
            ];
            try {
                $reply = ($this->sender)($envelope);
            } catch (Throwable) {
                return $this->result('transport_error', $page, $count);
            }
            if (!is_array($reply) || count($reply) !== 4
                || !isset($reply['status'], $reply['content_type'], $reply['body'])
                || !array_key_exists('redirected', $reply)
                || $reply['status'] !== 200 || $reply['content_type'] !== 'application/json'
                || $reply['redirected'] !== false || !is_string($reply['body'])) {
                return $this->result('response_invalid', $page, $count);
            }
            $size = strlen($reply['body']);
            $totalBytes += $size;
            if ($size === 0 || $size > self::MAX_PAGE_BYTES || $totalBytes > self::MAX_TOTAL_BYTES) {
                return $this->result('size_limit', $page, $count);
            }
            try {
                $payload = json_decode($reply['body'], true, 8, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                return $this->result('response_invalid', $page, $count);
            }
            if (!is_array($payload) || count($payload) !== 4
                || !isset($payload['page'], $payload['items'], $payload['complete'])
                || !array_key_exists('next_page', $payload)
                || $payload['page'] !== $page || !is_bool($payload['complete'])
                || !is_array($payload['items']) || !array_is_list($payload['items'])
                || count($payload['items']) > $pageSize) {
                return $this->result('response_invalid', $page, $count);
            }
            foreach ($payload['items'] as $item) {
                if (!is_array($item) || count($item) !== 2
                    || !isset($item['id'], $item['state'])
                    || !is_int($item['id']) || $item['id'] <= 0
                    || !in_array($item['state'], ['open', 'closed'], true)) {
                    return $this->result('response_invalid', $page, $count);
                }
            }
            $count += count($payload['items']);
            if ($count > self::MAX_ITEMS) {
                return $this->result('item_limit', $page, $count);
            }
            if ($payload['complete'] === true && $payload['next_page'] === null) {
                return $this->result('complete', $page, $count, true);
            }
            if ($payload['complete'] !== false || $payload['next_page'] !== $page + 1) {
                return $this->result('partial', $page, $count);
            }
        }
        return $this->result('page_limit', self::MAX_PAGES, $count);
    }

    private function validRequest(array $r): bool
    {
        if (count($r) !== 8
            || !isset($r['method'], $r['host'], $r['repository'], $r['resource'],
                $r['page_size'], $r['observed_at'], $r['expires_at'], $r['now'])
            || $r['method'] !== 'GET' || $r['host'] !== self::HOST
            || !is_string($r['repository'])
            || preg_match('~^[A-Za-z0-9][A-Za-z0-9._-]{0,38}/[A-Za-z0-9][A-Za-z0-9._-]{0,99}$~D', $r['repository']) !== 1
            || str_contains($r['repository'], '..')
            || !in_array($r['resource'], ['issues', 'pulls'], true)
            || !is_int($r['page_size']) || $r['page_size'] < 1 || $r['page_size'] > self::MAX_PAGE_SIZE
            || !is_int($r['observed_at']) || !is_int($r['expires_at']) || !is_int($r['now'])
            || $r['observed_at'] > $r['now'] || $r['now'] >= $r['expires_at']
            || $r['now'] - $r['observed_at'] > 300
            || $r['expires_at'] - $r['observed_at'] > 300) {
            return false;
        }
        return true;
    }

    /** @return array<string, mixed> */
    private function result(string $reason, int $pages, int $items, bool $complete = false): array
    {
        // Audit contains only bounded control-plane metadata, never payload or request identifiers.
        if (count($this->events) < 16) {
            $this->events[] = ['status' => $complete ? 'CURRENT' : 'UNKNOWN', 'reason' => $reason];
        }
        return [
            'status' => $complete ? 'CURRENT' : 'UNKNOWN',
            'reason' => $reason,
            'projection' => $complete ? ['kind' => 'offline_metadata_only', 'pages' => $pages, 'items_count' => $items] : null,
            'runtime_authorized' => false,
            'production_verified' => false,
            'execute_actions' => false,
            'authority' => 'unchanged',
        ];
    }

    public function audit(): array
    {
        return $this->events;
    }
}
