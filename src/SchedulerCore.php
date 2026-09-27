<?php
declare(strict_types=1);

namespace ControlBot\Scheduler;

use InvalidArgumentException;

final class SchedulerCore
{
    private const STATES = [
        'queued','eligible','reserved','assigned','running','review','verified','done',
        'waiting_dependency','waiting_human','blocked','paused','failed','cancelled',
    ];
    private const PRIORITIES = ['critical','high','medium','low'];
    private const APPROVAL = ['not_required','approved','pending','rejected','unknown'];
    private const FREEZE = ['clear','active','unknown'];
    private const DEPENDENCY = ['satisfied','open','unknown'];

    public static function workItem(array $raw): array
    {
        self::fields($raw, [
            'version','work_item_id','project_id','source_ref','type','priority','state',
            'dependency_ids','required_capabilities','generation','attempt',
            'reservation_id','assigned_session_id',
        ], 'WorkItem');
        if ($raw['version'] !== 1) {
            throw new InvalidArgumentException('WorkItem version invalid.');
        }
        $reservation = self::nullableId($raw['reservation_id'], 'reservation_id');
        $assigned = self::nullableId($raw['assigned_session_id'], 'assigned_session_id');
        if ($assigned !== null && $reservation === null) {
            throw new InvalidArgumentException('Assigned WorkItem requires reservation.');
        }
        return [
            'version' => 1,
            'work_item_id' => self::id($raw['work_item_id'], 'work_item_id'),
            'project_id' => self::id($raw['project_id'], 'project_id'),
            'source_ref' => self::workRef($raw['source_ref'], 'source_ref'),
            'type' => self::slug($raw['type'], 'type'),
            'priority' => self::enum($raw['priority'], self::PRIORITIES, 'priority'),
            'state' => self::enum($raw['state'], self::STATES, 'state'),
            'dependency_ids' => self::uniqueIds($raw['dependency_ids'], 'dependency_ids'),
            'required_capabilities' => self::uniqueSlugs($raw['required_capabilities'], 'required_capabilities'),
            'generation' => self::positiveInt($raw['generation'], 'generation'),
            'attempt' => self::positiveInt($raw['attempt'], 'attempt'),
            'reservation_id' => $reservation,
            'assigned_session_id' => $assigned,
        ];
    }

    public static function readiness(array $workRaw, array $context): array
    {
        $work = self::workItem($workRaw);
        self::fields($context, [
            'dependency_states','approval_state','freeze_state','reservations',
            'capacity','expected_generation','expected_owner_session_id',
        ], 'SchedulerContext');

        $dependencies = self::dependencyStates($context['dependency_states'], $work['dependency_ids']);
        $approval = self::enum($context['approval_state'], self::APPROVAL, 'approval_state');
        $freeze = self::enum($context['freeze_state'], self::FREEZE, 'freeze_state');
        $reservations = self::reservations($context['reservations']);
        $capacity = self::capacity($context['capacity']);
        $expectedGeneration = self::positiveInt($context['expected_generation'], 'expected_generation');
        $expectedOwner = self::nullableId($context['expected_owner_session_id'], 'expected_owner_session_id');

        $reasons = [];
        if (!in_array($work['state'], ['queued','eligible','reserved'], true)) {
            $reasons[] = 'workitem_not_assignable';
        }
        $open = array_keys(array_filter($dependencies, static fn(string $state): bool => $state === 'open'));
        $unknown = array_keys(array_filter($dependencies, static fn(string $state): bool => $state === 'unknown'));
        if ($open !== []) {
            $reasons[] = 'open_dependencies';
        }
        if ($unknown !== []) {
            $reasons[] = 'unknown_dependencies';
        }
        if ($approval === 'pending') {
            $reasons[] = 'pending_human_gate';
        } elseif ($approval === 'unknown') {
            $reasons[] = 'approval_unknown';
        } elseif ($approval === 'rejected') {
            $reasons[] = 'approval_rejected';
        }
        if ($freeze === 'active') {
            $reasons[] = 'freeze_active';
        } elseif ($freeze === 'unknown') {
            $reasons[] = 'freeze_unknown';
        }
        if ($expectedGeneration !== $work['generation']) {
            $reasons[] = 'stale_generation';
        }

        $active = array_values(array_filter($reservations, static fn(array $row): bool => $row['active']));
        if (count($active) > 1) {
            throw new InvalidArgumentException('Multiple active reservation owners invalid.');
        }
        $current = $active[0] ?? null;
        if ($current === null && ($work['reservation_id'] !== null || $work['assigned_session_id'] !== null)) {
            $reasons[] = 'missing_active_reservation';
        }
        if ($current !== null) {
            if ($work['reservation_id'] === null || $current['reservation_id'] !== $work['reservation_id']) {
                $reasons[] = 'incompatible_reservation';
            }
            if ($current['generation'] !== $work['generation']) {
                $reasons[] = 'stale_reservation_generation';
            }
            if (($work['assigned_session_id'] !== null && $current['owner_session_id'] !== $work['assigned_session_id'])
                || ($expectedOwner !== null && $current['owner_session_id'] !== $expectedOwner)) {
                $reasons[] = 'stale_owner';
            }
        }
        if (!$capacity['eligible'] || $capacity['free_capacity'] < 1) {
            $reasons[] = 'account_capacity_unavailable';
        }

        $reasons = array_values(array_unique($reasons));
        sort($reasons);
        sort($open);
        sort($unknown);
        return [
            'work_item_id' => $work['work_item_id'],
            'generation' => $work['generation'],
            'ready' => $reasons === [],
            'reasons' => $reasons,
            'open_dependencies' => $open,
            'unknown_dependencies' => $unknown,
            'reservation_owner' => $current['owner_session_id'] ?? null,
            'account_id' => $capacity['account_id'],
        ];
    }

    public static function candidate(array $workRaw, array $context): array
    {
        $work = self::workItem($workRaw);
        $readiness = self::readiness($work, $context);
        return [
            'version' => 1,
            'policy_ref' => 'factory-dispatcher-v2',
            'key' => $work['work_item_id'],
            'source_ref' => $work['source_ref'],
            'priority' => $work['priority'],
            'generation' => $work['generation'],
            'required_capabilities' => $work['required_capabilities'],
            'account_id' => $readiness['account_id'],
            'readiness' => [
                'ready' => $readiness['ready'],
                'reasons' => $readiness['reasons'],
            ],
        ];
    }

    private static function dependencyStates(mixed $raw, array $expectedIds): array
    {
        if (!is_array($raw) || array_is_list($raw)) {
            throw new InvalidArgumentException('dependency_states invalid.');
        }
        $keys = array_keys($raw);
        sort($keys);
        if ($keys !== $expectedIds) {
            throw new InvalidArgumentException('dependency_states mismatch.');
        }
        $out = [];
        foreach ($expectedIds as $id) {
            $out[$id] = self::enum($raw[$id], self::DEPENDENCY, 'dependency_state');
        }
        return $out;
    }

    private static function reservations(mixed $rows): array
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 8) {
            throw new InvalidArgumentException('reservations invalid.');
        }
        $out = [];
        $ids = [];
        foreach ($rows as $row) {
            self::fields($row, ['reservation_id','owner_session_id','generation','active'], 'Reservation');
            if (!is_bool($row['active'])) {
                throw new InvalidArgumentException('reservation.active invalid.');
            }
            $id = self::id($row['reservation_id'], 'reservation_id');
            if (isset($ids[$id])) {
                throw new InvalidArgumentException('Reservation duplicated.');
            }
            $ids[$id] = true;
            $out[] = [
                'reservation_id' => $id,
                'owner_session_id' => self::id($row['owner_session_id'], 'owner_session_id'),
                'generation' => self::positiveInt($row['generation'], 'reservation.generation'),
                'active' => $row['active'],
            ];
        }
        return $out;
    }

    private static function capacity(mixed $raw): array
    {
        self::fields($raw, ['account_id','eligible','free_capacity','session_ids'], 'Capacity');
        if (!is_bool($raw['eligible']) || !is_int($raw['free_capacity']) || $raw['free_capacity'] < 0 || $raw['free_capacity'] > 32) {
            throw new InvalidArgumentException('Capacity invalid.');
        }
        $sessions = self::uniqueIds($raw['session_ids'], 'capacity.session_ids');
        if ($raw['eligible'] && $raw['free_capacity'] < 1) {
            throw new InvalidArgumentException('Capacity eligibility inconsistent.');
        }
        return [
            'account_id' => self::id($raw['account_id'], 'account_id'),
            'eligible' => $raw['eligible'],
            'free_capacity' => $raw['free_capacity'],
            'session_ids' => $sessions,
        ];
    }

    private static function uniqueIds(mixed $values, string $label): array
    {
        return self::uniqueList($values, $label, static fn(mixed $value): string => self::id($value, $label));
    }

    private static function uniqueSlugs(mixed $values, string $label): array
    {
        return self::uniqueList($values, $label, static fn(mixed $value): string => self::slug($value, $label));
    }

    private static function uniqueList(mixed $values, string $label, callable $normalize): array
    {
        if (!is_array($values) || !array_is_list($values) || count($values) > 64) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        $out = [];
        foreach ($values as $value) {
            $normalized = $normalize($value);
            if (isset($out[$normalized])) {
                throw new InvalidArgumentException($label . ' duplicated.');
            }
            $out[$normalized] = true;
        }
        $values = array_keys($out);
        sort($values);
        return $values;
    }

    private static function fields(mixed $row, array $expected, string $label): void
    {
        if (!is_array($row) || array_is_list($row)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        $keys = array_keys($row);
        sort($keys);
        sort($expected);
        if ($keys !== $expected) {
            throw new InvalidArgumentException($label . ' fields invalid.');
        }
    }

    private static function workRef(mixed $value, string $label): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+#[1-9][0-9]*$/D', $value) !== 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function id(mixed $value, string $label): string
    {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9._-]{0,79}$/D', $value) !== 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function nullableId(mixed $value, string $label): ?string
    {
        return $value === null ? null : self::id($value, $label);
    }

    private static function slug(mixed $value, string $label): string
    {
        return self::id($value, $label);
    }

    private static function enum(mixed $value, array $allowed, string $label): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function positiveInt(mixed $value, string $label): int
    {
        if (!is_int($value) || $value < 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }
}
