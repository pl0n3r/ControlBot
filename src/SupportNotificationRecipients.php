<?php
declare(strict_types=1);

namespace ControlBot\Support;

require_once __DIR__ . '/OwnerLoginGate.php';

use ControlBot\Security\OwnerLoginGate;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/** Owner-only private configuration and side-effect-free notification routing. */
final class SupportNotificationRecipients
{
    private const TYPES = ['incident','availability_down','backup_failure','owner_decision','usage_limit'];
    private const ROUTE = '/support-notifications';
    private const MAX_BYTES = 1048576;

    private readonly string $directory;

    private function __construct(string $directory)
    {
        $root = realpath(dirname(__DIR__));
        $dir = realpath($directory);
        if (!str_starts_with($directory, '/') || is_link($directory)
            || !is_string($dir) || !is_dir($dir) || !is_writable($dir)
            || ($root !== false && ($dir === $root || str_starts_with($dir, $root . DIRECTORY_SEPARATOR)))) {
            throw new RuntimeException('Private directory unavailable.');
        }
        $this->directory = $dir;
    }

    /** Request adapter for a future owner route; no public router changes are authorized by #1428. */
    public static function handle(
        array $server, array $input, array &$session, array $authConfig,
        string $directory, int $now
    ): array {
        $uri = $server['REQUEST_URI'] ?? null;
        if ($uri !== self::ROUTE) {
            return self::reply(404, ['error'=>'not_found']);
        }
        $method = $server['REQUEST_METHOD'] ?? null;
        if ($method !== 'GET' && $method !== 'POST') {
            return self::reply(405, ['error'=>'method_not_allowed'], ['Allow'=>'GET, POST']);
        }
        $gate = OwnerLoginGate::requireOwner($server, $session, $authConfig, $now);
        if ($gate !== null) {
            return self::reply(($gate['status'] ?? null) === 503 ? 503 : 403, ['error'=>'forbidden']);
        }

        try {
            $store = new self($directory);
            if ($method === 'GET') {
                if (!self::validCsrf($session['_cb_support_csrf'] ?? null)) {
                    $session['_cb_support_csrf'] = self::newCsrf();
                }
                $state = $store->transaction(null);
                if ($input === []) {
                    return self::reply(200, [
                        'version'=>1, 'revision'=>$state['revision'],
                        'settings'=>$state['settings'], 'audit'=>$state['audit'],
                        'csrf'=>$session['_cb_support_csrf'],
                    ]);
                }
                self::fields($input, ['action','type','at_utc']);
                $stamp = $input['at_utc'];
                if ($input['action'] !== 'simulate' || !is_string($stamp)
                    || preg_match('/^(?:0|[1-9][0-9]{0,10})$/D', $stamp) !== 1
                    || (int) $stamp > 4102444800) {
                    throw new InvalidArgumentException('Invalid simulation.');
                }
                return self::reply(200, self::simulate(
                    $state['settings'], $input['type'], (int) $stamp
                ));
            }

            self::fields($input, ['csrf','expected_revision','settings']);
            if (!self::validCsrf($input['csrf'])
                || !self::validCsrf($session['_cb_support_csrf'] ?? null)
                || !hash_equals($session['_cb_support_csrf'], $input['csrf'])) {
                return self::reply(403, ['error'=>'csrf']);
            }
            if (!is_int($input['expected_revision']) || $input['expected_revision'] < 0) {
                throw new InvalidArgumentException('Revision invalid.');
            }
            $settings = self::validateSettings($input['settings']);
            $revision = $input['expected_revision'];
            $actor = $authConfig['owner_login'];
            $state = $store->transaction(
                static function (array $old) use ($settings, $revision, $actor, $now): array {
                    if ($old['revision'] !== $revision) {
                        throw new UnexpectedValueException('Stale configuration.');
                    }
                    if (count($old['audit']) >= 2048) {
                        throw new RuntimeException('Audit capacity exceeded.');
                    }
                    $changed = [];
                    foreach (self::TYPES as $type) {
                        if ($old['settings']['recipients'][$type] !== $settings['recipients'][$type]) {
                            $changed[] = 'recipients.' . $type;
                        }
                    }
                    if ($old['settings']['shifts'] !== $settings['shifts']) {
                        $changed[] = 'shifts';
                    }
                    $next = $old;
                    $next['revision']++;
                    $next['settings'] = $settings;
                    $next['audit'][] = [
                        'revision'=>$next['revision'],
                        'actor'=>$actor,
                        'occurred_at_utc'=>gmdate('Y-m-d\TH:i:s\Z', $now),
                        'action'=>'support_notification_recipients.update',
                        'changed_fields'=>$changed,
                        'previous_sha256'=>hash('sha256', json_encode(
                            $old['settings'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
                        )),
                        'new_sha256'=>hash('sha256', json_encode(
                            $settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
                        )),
                    ];
                    return $next;
                }
            );
            $session['_cb_support_csrf'] = self::newCsrf();
            return self::reply(200, [
                'revision'=>$state['revision'], 'csrf'=>$session['_cb_support_csrf'],
            ]);
        } catch (UnexpectedValueException) {
            return self::reply(409, ['error'=>'revision_conflict']);
        } catch (InvalidArgumentException) {
            return self::reply(422, ['error'=>'invalid_input']);
        } catch (Throwable) {
            return self::reply(503, ['error'=>'private_storage_unavailable']);
        }
    }

    /** Read-only preview; prioritizes the configured on-call guard. No delivery API exists. */
    public static function simulate(array $settings, mixed $type, int $atUtc): array
    {
        $settings = self::validateSettings($settings);
        if (!is_string($type) || !in_array($type, self::TYPES, true)
            || $atUtc < 0 || $atUtc > 4102444800) {
            throw new InvalidArgumentException('Invalid type/time.');
        }
        $local = (new DateTimeImmutable('@' . $atUtc))
            ->setTimezone(new DateTimeZone('America/Bogota'));
        $day = (int) $local->format('w');
        $minute = (int) $local->format('H') * 60 + (int) $local->format('i');
        $candidates = [];
        foreach ($settings['shifts'] as $shift) {
            if (in_array($day, $shift['days'], true)
                && $minute >= self::minutes($shift['start'])
                && $minute < self::minutes($shift['end'])) {
                $candidates[] = ['email'=>$shift['email'],'role'=>'on_call'];
            }
        }
        foreach ($settings['recipients'][$type] as $recipient) {
            $candidates[] = ['email'=>$recipient['email'],'role'=>'escalation'];
        }
        $result = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            if (isset($seen[$candidate['email']])) {
                continue;
            }
            $seen[$candidate['email']] = true;
            $result[] = [
                'order'=>count($result)+1, 'email'=>$candidate['email'],
                'role'=>$candidate['role'],
            ];
        }
        return [
            'version'=>1, 'type'=>$type,
            'at_utc'=>gmdate('Y-m-d\TH:i:s\Z', $atUtc),
            'timezone'=>'America/Bogota',
            'status'=>$result === [] ? 'unconfigured' : 'preview',
            'recipients'=>$result, 'sent'=>false,
        ];
    }

    /** Serialize updates and append-only audit in a single private, atomic rename. */
    private function transaction(?callable $change): array
    {
        $dir = realpath($this->directory);
        if (!is_string($dir) || $dir !== $this->directory) {
            throw new RuntimeException('Private directory changed.');
        }
        $file = $dir . '/cb-support-notifications.json';
        $lock = $dir . '/cb-support-notifications.lock';
        if (is_link($file) || is_link($lock)) {
            throw new RuntimeException('Unsafe state path.');
        }
        $handle = @fopen($lock, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Private lock unavailable.');
        }
        try {
            if (!@chmod($lock, 0600) || !flock($handle, LOCK_EX) || is_link($file)) {
                throw new RuntimeException('Cannot lock private state.');
            }
            $raw = is_file($file) ? @file_get_contents($file) : null;
            if ($raw === false || (is_string($raw) && strlen($raw) > self::MAX_BYTES)) {
                throw new RuntimeException('Private state unreadable.');
            }
            $state = $raw === null ? [
                'version'=>1, 'revision'=>0,
                'settings'=>['recipients'=>array_fill_keys(self::TYPES, []), 'shifts'=>[]],
                'audit'=>[],
            ] : json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            self::fields($state, ['version','revision','settings','audit']);
            if ($state['version'] !== 1 || !is_int($state['revision'])
                || $state['revision'] < 0 || !is_array($state['audit'])
                || !array_is_list($state['audit'])) {
                throw new RuntimeException('Private state malformed.');
            }
            $state['settings'] = self::validateSettings($state['settings']);
            if ($change === null) {
                return $state;
            }
            $next = $change($state);
            $encoded = json_encode($next, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded) || strlen($encoded) > self::MAX_BYTES) {
                throw new RuntimeException('Private state too large.');
            }
            $tmp = @tempnam($dir, '.cb-support-tmp-');
            if (!is_string($tmp)) {
                throw new RuntimeException('Cannot stage state.');
            }
            try {
                if (!@chmod($tmp, 0600)) {
                    throw new RuntimeException('Cannot protect state.');
                }
                $writer = @fopen($tmp, 'wb');
                if ($writer === false) {
                    throw new RuntimeException('Cannot write state.');
                }
                try {
                    $offset = 0;
                    while ($offset < strlen($encoded)) {
                        $written = fwrite($writer, substr($encoded, $offset));
                        if (!is_int($written) || $written <= 0) {
                            throw new RuntimeException('Short state write.');
                        }
                        $offset += $written;
                    }
                    if (!fflush($writer) || (function_exists('fsync') && !fsync($writer))) {
                        throw new RuntimeException('State fsync failed.');
                    }
                } finally {
                    fclose($writer);
                }
                if (!@rename($tmp, $file)) {
                    throw new RuntimeException('Atomic state commit failed.');
                }
            } finally {
                if (is_file($tmp)) {
                    @unlink($tmp);
                }
            }
            return $next;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Only five notification kinds and a bounded, non-overlapping weekly schedule. */
    private static function validateSettings(mixed $settings): array
    {
        self::fields($settings, ['recipients','shifts']);
        $contacts = $settings['recipients'];
        if (!is_array($contacts) || array_is_list($contacts)
            || count($contacts) !== count(self::TYPES)) {
            throw new InvalidArgumentException('Missing notice types.');
        }
        $normalized = [];
        foreach (self::TYPES as $type) {
            if (!array_key_exists($type, $contacts) || !is_array($contacts[$type])
                || !array_is_list($contacts[$type]) || count($contacts[$type]) > 10) {
                throw new InvalidArgumentException('Recipients malformed.');
            }
            $normalized[$type] = [];
            $seen = [];
            foreach ($contacts[$type] as $i=>$recipient) {
                self::fields($recipient, ['email','order']);
                if ($recipient['order'] !== $i + 1) {
                    throw new InvalidArgumentException('Non-consecutive escalation.');
                }
                $email = self::email($recipient['email']);
                if (isset($seen[$email])) {
                    throw new InvalidArgumentException('Duplicate recipient.');
                }
                $seen[$email] = true;
                $normalized[$type][] = ['email'=>$email,'order'=>$i + 1];
            }
        }
        $shifts = $settings['shifts'];
        if (!is_array($shifts) || !array_is_list($shifts) || count($shifts) > 28) {
            throw new InvalidArgumentException('Shifts malformed.');
        }
        $taken = array_fill(0, 7, []);
        $normalShifts = [];
        foreach ($shifts as $shift) {
            self::fields($shift, ['days','email','end','start']);
            $days = $shift['days'];
            if (!is_array($days) || !array_is_list($days) || !$days
                || count($days) > 7 || count(array_unique($days, SORT_REGULAR)) !== count($days)) {
                throw new InvalidArgumentException('Days malformed.');
            }
            $start = self::minutes($shift['start']);
            $end = self::minutes($shift['end']);
            if ($start >= $end) {
                throw new InvalidArgumentException('Incoherent shift.');
            }
            foreach ($days as $day) {
                if (!is_int($day) || $day < 0 || $day > 6) {
                    throw new InvalidArgumentException('Unknown day.');
                }
                foreach ($taken[$day] as [$from,$to]) {
                    if ($start < $to && $end > $from) {
                        throw new InvalidArgumentException('Overlapping guard shifts.');
                    }
                }
                $taken[$day][] = [$start,$end];
            }
            $normalShifts[] = [
                'days'=>$days,'start'=>$shift['start'],
                'end'=>$shift['end'],'email'=>self::email($shift['email']),
            ];
        }
        return ['recipients'=>$normalized,'shifts'=>$normalShifts];
    }

    private static function email(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 254
            || $value !== trim($value)
            || preg_match('/[\x00-\x20\x7f]/', $value) === 1
            || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Invalid email address.');
        }
        return strtolower($value);
    }

    private static function minutes(mixed $value): int
    {
        if (!is_string($value)
            || preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $value) !== 1) {
            throw new InvalidArgumentException('Invalid hour.');
        }
        return (int) substr($value, 0, 2) * 60 + (int) substr($value, 3, 2);
    }

    private static function fields(mixed $value, array $keys): void
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('Unexpected fields.');
        }
        $given = array_keys($value);
        sort($given, SORT_STRING);
        sort($keys, SORT_STRING);
        if ($given !== $keys) {
            throw new InvalidArgumentException('Missing/extra fields.');
        }
    }

    private static function validCsrf(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_-]{43}$/D', $value) === 1;
    }

    private static function newCsrf(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private static function reply(int $code, array $body, array $other = []): array
    {
        return [
            'status'=>$code,
            'headers'=>array_merge([
                'Content-Type'=>'application/json; charset=utf-8',
                'Cache-Control'=>'no-store',
                'X-Content-Type-Options'=>'nosniff',
                'X-Frame-Options'=>'DENY',
                'Referrer-Policy'=>'no-referrer',
            ], $other),
            'body'=>json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ];
    }
}
