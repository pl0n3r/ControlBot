<?php
declare(strict_types=1);

use ControlBot\Scheduler\SchedulerCore;
use ControlBot\Scheduler\SchedulerSelection;

require_once __DIR__ . '/../../SchedulerCore.php';
require_once __DIR__ . '/../../SchedulerSelection.php';

/**
 * The owner scheduler view is a deterministic preview, not an assignment.
 * Source data comes exclusively from a trusted server-side backend callback.
 */
return [
    [
        'method' => 'GET',
        'path' => '/api/v1/scheduler',
        'handler' => static function (callable $backend, array $vars, array $request, int $now): array {
            $snapshot = $backend('scheduler.plan.read', $vars, $request, $now);
            try {
                if (!is_array($snapshot) || array_keys($snapshot) !== ['version', 'work_items']
                    || $snapshot['version'] !== 1
                    || !is_array($snapshot['work_items'])
                    || !array_is_list($snapshot['work_items'])
                    || count($snapshot['work_items']) > 64) {
                    throw new RuntimeException('Trusted scheduler snapshot unavailable.');
                }

                $rows = [];
                $candidates = [];
                foreach ($snapshot['work_items'] as $row) {
                    if (!is_array($row) || count($row) !== 2
                        || !array_key_exists('work_item', $row) || !array_key_exists('context', $row)
                        || !is_array($row['work_item']) || !is_array($row['context'])) {
                        throw new RuntimeException('Invalid scheduler row.');
                    }
                    $item = SchedulerCore::workItem($row['work_item']);
                    $candidate = SchedulerCore::candidate($item, $row['context']);
                    $key = $item['work_item_id'];
                    if (isset($rows[$key])) {
                        throw new RuntimeException('Duplicated scheduler WorkItem.');
                    }
                    $candidates[] = $candidate;
                    $rows[$key] = [
                        'work_item_id' => $key,
                        'source_ref' => $item['source_ref'],
                        'priority' => $item['priority'],
                        'state' => $item['state'],
                        'generation' => $item['generation'],
                        'dependency_ids' => $item['dependency_ids'],
                        'readiness' => $candidate['readiness'],
                        'reservation' => [
                            'held' => $item['reservation_id'] !== null,
                            'generation' => $item['reservation_id'] !== null ? $item['generation'] : null,
                        ],
                    ];
                }

                ksort($rows, SORT_STRING);
                // Selection.request validates the policy and duplicate candidates,
                // but NEVER chooses a session, reserves a WorkItem, or writes state.
                $selection = $candidates !== []
                    ? SchedulerSelection::request($candidates)
                    : ['fingerprint' => hash('sha256', json_encode(
                        ['version' => 1, 'policy_ref' => 'factory-dispatcher-v2', 'candidates' => []],
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
                    ))];
                $items = array_values($rows);
                $readyCount = count(array_filter($items, static fn(array $v): bool => $v['readiness']['ready']));
                return [
                    'version' => 1,
                    'policy_ref' => 'factory-dispatcher-v2',
                    'mode' => 'read_only',
                    'assignment_committed' => false,
                    'selection_request_fingerprint' => $selection['fingerprint'],
                    'ready_count' => $readyCount,
                    'blocked_count' => count($items) - $readyCount,
                    'work_items' => $items,
                ];
            } catch (InvalidArgumentException|JsonException $error) {
                // A broken server snapshot is not a malformed owner request.
                throw new RuntimeException('Invalid trusted scheduler snapshot.', 0, $error);
            }
        },
    ],
];
