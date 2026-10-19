<?php
declare(strict_types=1);

namespace ControlBot\AutoFactory;

use Throwable;

/**
 * Pure, disabled-by-default bridge boundary. The caller owns pairing verification,
 * atomic storage and the legal/operational activation gate. No public route is mounted.
 */
final class AutoFactoryBridgeEndpoint
{
    private const ACK_CODES = ['ok', 'invalid', 'not_found', 'not_ready', 'timeout', 'unauthorized', 'already_handled', 'failed'];
    private const TAB_STATES = ['paused', 'waiting', 'generating', 'sending', 'error', 'limit', 'requires_login'];
    private const ACCOUNT_STATES = ['ready', 'limit', 'requires_login', 'error', 'unknown'];

    public function __construct(
        private readonly \Closure $verifyCredential,
        private readonly \Closure $storage,
        private readonly \Closure $clock,
        private readonly bool $enabled = false,
    ) {
    }

    public static function offline(callable $verifyCredential, callable $storage, callable $clock, bool $enabled = false): self
    {
        return new self(\Closure::fromCallable($verifyCredential), \Closure::fromCallable($storage), \Closure::fromCallable($clock), $enabled);
    }

    /** @return array{status:int,headers:array<string,string>,body:array<string,mixed>} */
    public function handle(string $method, string $path, array $query, array $body, ?string $credential): array
    {
        if (!$this->enabled) {
            return self::response(503, ['error' => 'not_ready']);
        }
        if ($credential === null || $credential === '' || strlen($credential) > 512) {
            return self::response(401, ['error' => 'unauthorized']);
        }
        try {
            // Only an injected, trusted pairing verifier can establish instance identity.
            $identity = ($this->verifyCredential)($credential);
            $now = ($this->clock)();
            if (!is_array($identity) || !is_int($now) || $now < 1
                || !self::hasKeys($identity, ['instanceId', 'profileAlias', 'expiresAt', 'revoked'])
                || !is_string($identity['instanceId'])
                || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $identity['instanceId']) !== 1
                || !self::alias($identity['profileAlias'])
                || !is_int($identity['expiresAt']) || $identity['expiresAt'] <= $now
                || $identity['revoked'] !== false) {
                return self::response(401, ['error' => 'unauthorized']);
            }
        } catch (Throwable) {
            return self::response(401, ['error' => 'unauthorized']);
        }

        if ($method === 'POST' && $path === '/v1/bridge/heartbeat' && $query === []) {
            if (!self::heartbeat($body) || !hash_equals($identity['profileAlias'], $body['profileAlias'])) {
                return self::response(400, ['error' => 'invalid']);
            }
            try {
                $result = ($this->storage)('heartbeat', $identity['instanceId'], $body);
                return $result === true ? self::response(202, ['accepted' => true]) : self::response(503, ['error' => 'not_ready']);
            } catch (Throwable) {
                return self::response(503, ['error' => 'not_ready']);
            }
        }
        if ($method === 'POST' && $path === '/v1/bridge/commands/ack' && $query === []) {
            if (!self::ack($body)) {
                return self::response(400, ['error' => 'invalid']);
            }
            try {
                // A durable adapter MUST atomically dedupe (instanceId, orderId),
                // returning created/duplicate/conflict/missing, never a blind success.
                $result = ($this->storage)('ack', $identity['instanceId'], $body);
                return match ($result) {
                    'created' => self::response(200, ['accepted' => true, 'duplicate' => false]),
                    'duplicate' => self::response(200, ['accepted' => true, 'duplicate' => true]),
                    'conflict' => self::response(409, ['error' => 'invalid']),
                    'missing' => self::response(404, ['error' => 'not_found']),
                    default => self::response(503, ['error' => 'not_ready']),
                };
            } catch (Throwable) {
                return self::response(503, ['error' => 'not_ready']);
            }
        }
        if ($method === 'GET' && $path === '/v1/bridge/commands/next' && $body === []) {
            // $_GET contains strings, not native integers or null. Normalize only
            // recognized values; malformed and unknown parameters still fail closed.
            if (!array_key_exists('cursor', $query) || $query['cursor'] === '') {
                $query['cursor'] = null;
            }
            if (is_string($query['waitMs'] ?? null)
                && preg_match('/^[0-9]{4,5}$/D', $query['waitMs']) === 1) {
                $query['waitMs'] = (int) $query['waitMs'];
            }
            if (!self::hasKeys($query, ['profileAlias', 'cursor', 'waitMs'])
                || !self::alias($query['profileAlias'])
                || !hash_equals($identity['profileAlias'], $query['profileAlias'])
                || !($query['cursor'] === null || (is_string($query['cursor']) && preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $query['cursor']) === 1))
                || !is_int($query['waitMs']) || $query['waitMs'] < 5000 || $query['waitMs'] > 30000) {
                return self::response(400, ['error' => 'invalid']);
            }
            try {
                $result = ($this->storage)('next', $identity['instanceId'], $query);
                if (!is_array($result) || !self::hasKeys($result, ['cursor', 'command'])
                    || !($result['cursor'] === null || (is_string($result['cursor']) && preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $result['cursor']) === 1))
                    || !self::command($result['command'])) {
                    return self::response(503, ['error' => 'not_ready']);
                }
                return self::response(200, $result);
            } catch (Throwable) {
                return self::response(503, ['error' => 'not_ready']);
            }
        }
        return self::response(404, ['error' => 'not_found']);
    }

    private static function alias(mixed $value): bool
    {
        return is_string($value) && strlen($value) > 0 && strlen($value) <= 64
            && trim($value) === $value && preg_match('/[@\x00-\x1f]/', $value) !== 1;
    }

    private static function hasKeys(array $data, array $keys): bool
    {
        $actual = array_keys($data);
        sort($actual);
        sort($keys);
        return $actual === $keys;
    }

    private static function heartbeat(array $body): bool
    {
        $keys = ['version', 'kind', 'profileAlias', 'accountAlias', 'tabs', 'accountState', 'lastEvent'];
        if (array_key_exists('messageBudget', $body)) {
            $keys[] = 'messageBudget';
        }
        if (!self::hasKeys($body, $keys) || $body['version'] !== 1 || $body['kind'] !== 'heartbeat'
            || !self::alias($body['profileAlias']) || !self::alias($body['accountAlias'])
            || !is_array($body['tabs']) || !array_is_list($body['tabs']) || count($body['tabs']) > 40
            || !is_array($body['accountState']) || !self::hasKeys($body['accountState'], ['state', 'resetAt'])
            || !in_array($body['accountState']['state'], self::ACCOUNT_STATES, true)
            || ($body['accountState']['state'] !== 'limit' && $body['accountState']['resetAt'] !== null)
            || ($body['accountState']['state'] === 'limit' && $body['accountState']['resetAt'] !== null
                && (!is_int($body['accountState']['resetAt']) || $body['accountState']['resetAt'] < 1))
            || !($body['lastEvent'] === null || (is_string($body['lastEvent']) && preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $body['lastEvent']) === 1))) {
            return false;
        }
        $seen = [];
        foreach ($body['tabs'] as $tab) {
            if (!is_array($tab) || !self::hasKeys($tab, ['tabId', 'enabled', 'state'])
                || !is_int($tab['tabId']) || $tab['tabId'] < 0 || isset($seen[$tab['tabId']])
                || !is_bool($tab['enabled']) || !in_array($tab['state'], self::TAB_STATES, true)) {
                return false;
            }
            $seen[$tab['tabId']] = true;
        }
        if (array_key_exists('messageBudget', $body)) {
            $budget = $body['messageBudget'];
            if (!is_array($budget) || !self::hasKeys($budget, ['windowMs', 'budget', 'sent', 'remaining', 'limitEvents', 'highReasoningSends', 'nextAllowedAt', 'source'])
                || !is_int($budget['windowMs']) || $budget['windowMs'] < 60000 || $budget['windowMs'] > 86400000
                || !is_int($budget['budget']) || $budget['budget'] < 1 || $budget['budget'] > 512
                || !is_int($budget['sent']) || $budget['sent'] < 0 || $budget['sent'] > $budget['budget']
                || $budget['remaining'] !== $budget['budget'] - $budget['sent']
                || !is_int($budget['limitEvents']) || $budget['limitEvents'] < 0 || $budget['limitEvents'] > 512
                || !is_int($budget['highReasoningSends']) || $budget['highReasoningSends'] < 0 || $budget['highReasoningSends'] > $budget['sent']
                || !($budget['nextAllowedAt'] === null || (is_int($budget['nextAllowedAt']) && $budget['nextAllowedAt'] > 0))
                || !in_array($budget['source'], ['default', 'factory'], true)) {
                return false;
            }
        }
        return true;
    }

    private static function ack(array $body): bool
    {
        return self::hasKeys($body, ['version', 'kind', 'id', 'ok', 'code'])
            && $body['version'] === 1 && $body['kind'] === 'ack'
            && is_string($body['id']) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,79}$/D', $body['id']) === 1
            && is_bool($body['ok']) && in_array($body['code'], self::ACK_CODES, true)
            && $body['ok'] === ($body['code'] === 'ok');
    }

    private static function command(mixed $command): bool
    {
        if (!is_array($command) || !self::hasKeys($command, ['version', 'kind', 'id', 'action', 'target', 'payload'])
            || $command['version'] !== 1 || $command['kind'] !== 'command'
            || !is_string($command['id']) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,79}$/D', $command['id']) !== 1
            || !in_array($command['action'], ['pause','resume','open_chat','set_prompt','set_mode','send_message'], true)
            || !($command['target'] === 'all' || (is_int($command['target']) && $command['target'] >= 0))
            || ($command['target'] === 'all' && !in_array($command['action'], ['pause', 'resume'], true))) {
            return false;
        }
        if (in_array($command['action'], ['send_message', 'set_prompt'], true)) {
            return is_array($command['payload']) && self::hasKeys($command['payload'], ['text'])
                && is_string($command['payload']['text']) && trim($command['payload']['text']) !== ''
                && strlen($command['payload']['text']) <= 16000;
        }
        if ($command['action'] === 'set_mode') {
            return is_array($command['payload']) && self::hasKeys($command['payload'], ['mode'])
                && in_array($command['payload']['mode'], ['chat', 'work'], true);
        }
        return $command['payload'] === null;
    }

    private static function response(int $status, array $body): array
    {
        return ['status' => $status, 'headers' => ['Cache-Control' => 'no-store', 'Content-Type' => 'application/json'], 'body' => $body];
    }
}
