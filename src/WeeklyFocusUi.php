<?php
declare(strict_types=1);

namespace ControlBot\Scheduler;

use InvalidArgumentException;

final class WeeklyFocusUi
{
    private const UNAVAILABLE_REASONS = [
        'archived', 'blocked', 'waiting_dependency', 'frozen', 'waiting_human', 'unavailable',
    ];

    public static function project(array $focusRaw, array $statesRaw): array
    {
        $focus = WeeklyFocus::normalize($focusRaw);
        $states = self::states($focus['ordered_refs'], $statesRaw);
        $last = count($focus['ordered_refs']) - 1;
        $items = [];

        foreach ($focus['ordered_refs'] as $position => $ref) {
            $state = $states[$ref];
            $items[] = [
                'ref' => $ref,
                'position' => $position,
                'state' => $state['state'],
                'reason' => $state['reason'],
                'dispatchable' => $state['state'] === 'available',
                'actions' => [
                    'drag' => [
                        'enabled' => true,
                        'label' => 'Reorder focus item',
                        'desktop' => true,
                        'requires_pointer' => true,
                    ],
                    'move_up' => self::moveAction('Move focus item up', $position > 0),
                    'move_down' => self::moveAction('Move focus item down', $position < $last),
                ],
            ];
        }

        return [
            'version' => 1,
            'focus_id' => $focus['focus_id'],
            'focus_version' => $focus['version'],
            'week_start' => $focus['week_start'],
            'scope' => $focus['scope'],
            'explicit_focus' => $focus['ordered_refs'] !== [],
            'items' => $items,
            'save_contract' => [
                'fields' => ['ordered_refs', 'expected_version'],
                'expected_version' => $focus['version'],
                'conflict_behavior' => 'refresh_reconcile',
                'overwrite_on_conflict' => false,
            ],
            'clear_focus' => [
                'enabled' => $focus['ordered_refs'] !== [],
                'label' => 'Use base prioritization',
                'affects_running_work' => false,
            ],
            'accessibility' => [
                'desktop_drag' => true,
                'mobile_buttons' => true,
                'keyboard_buttons' => true,
                'drag_only' => false,
            ],
        ];
    }

    public static function reorderIntent(array $focusRaw, array $orderedRefs, int $expectedVersion): array
    {
        $focus = WeeklyFocus::normalize($focusRaw);
        if ($expectedVersion !== $focus['version']) {
            return self::conflict($focus);
        }

        $candidate = WeeklyFocus::normalize(array_replace($focus, ['ordered_refs' => $orderedRefs]));
        $current = $focus['ordered_refs'];
        $next = $candidate['ordered_refs'];
        $currentSet = $current;
        $nextSet = $next;
        sort($currentSet, SORT_STRING);
        sort($nextSet, SORT_STRING);
        if ($currentSet !== $nextSet) {
            throw new InvalidArgumentException('WeeklyFocus UI reorder must preserve focus refs.');
        }

        return self::ready($focus, $next);
    }

    public static function moveIntent(array $focusRaw, string $ref, string $direction, int $expectedVersion): array
    {
        $focus = WeeklyFocus::normalize($focusRaw);
        if ($expectedVersion !== $focus['version']) {
            return self::conflict($focus);
        }
        if (!in_array($direction, ['up', 'down'], true)) {
            throw new InvalidArgumentException('WeeklyFocus UI direction invalid.');
        }

        $position = array_search($ref, $focus['ordered_refs'], true);
        if ($position === false) {
            throw new InvalidArgumentException('WeeklyFocus UI ref not in focus.');
        }
        $target = $direction === 'up' ? $position - 1 : $position + 1;
        if ($target < 0 || $target >= count($focus['ordered_refs'])) {
            throw new InvalidArgumentException('WeeklyFocus UI move out of bounds.');
        }

        $next = $focus['ordered_refs'];
        [$next[$position], $next[$target]] = [$next[$target], $next[$position]];
        return self::ready($focus, $next);
    }

    public static function clearIntent(array $focusRaw, int $expectedVersion): array
    {
        $focus = WeeklyFocus::normalize($focusRaw);
        if ($expectedVersion !== $focus['version']) {
            return self::conflict($focus);
        }
        return self::ready($focus, []);
    }

    private static function ready(array $focus, array $orderedRefs): array
    {
        return [
            'status' => 'ready',
            'save_request' => [
                'ordered_refs' => $orderedRefs,
                'expected_version' => $focus['version'],
            ],
            'overwrite_on_conflict' => false,
            'requires_refresh' => false,
            'affects_running_work' => false,
        ];
    }

    private static function conflict(array $focus): array
    {
        return [
            'status' => 'conflict',
            'current_version' => $focus['version'],
            'save_request' => null,
            'overwrite_on_conflict' => false,
            'requires_refresh' => true,
            'reconcile_action' => 'reload_latest_focus',
            'affects_running_work' => false,
        ];
    }

    private static function states(array $refs, array $raw): array
    {
        if (array_is_list($raw)) {
            throw new InvalidArgumentException('WeeklyFocus UI states invalid.');
        }
        $expected = $refs;
        $actual = array_keys($raw);
        sort($expected, SORT_STRING);
        sort($actual, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException('WeeklyFocus UI states mismatch.');
        }

        $out = [];
        foreach ($raw as $ref => $state) {
            if (!is_array($state) || array_is_list($state)) {
                throw new InvalidArgumentException('WeeklyFocus UI state invalid.');
            }
            $keys = array_keys($state);
            sort($keys, SORT_STRING);
            if ($keys !== ['reason', 'state'] || !is_string($state['state'])
                || !in_array($state['state'], ['available', 'unavailable'], true)) {
                throw new InvalidArgumentException('WeeklyFocus UI state fields invalid.');
            }
            $reason = $state['reason'];
            if ($state['state'] === 'available') {
                if ($reason !== null) {
                    throw new InvalidArgumentException('Available focus item cannot have block reason.');
                }
            } elseif (!is_string($reason) || !in_array($reason, self::UNAVAILABLE_REASONS, true)) {
                throw new InvalidArgumentException('Unavailable focus item requires canonical reason.');
            }
            $out[$ref] = ['state' => $state['state'], 'reason' => $reason];
        }
        return $out;
    }

    private static function moveAction(string $label, bool $enabled): array
    {
        return [
            'enabled' => $enabled,
            'label' => $label,
            'mobile' => true,
            'keyboard_focusable' => true,
            'focus_visible' => true,
            'requires_drag' => false,
        ];
    }
}
