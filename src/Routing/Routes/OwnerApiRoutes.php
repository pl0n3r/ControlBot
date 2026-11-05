<?php
declare(strict_types=1);

use ControlBot\Business\OwnerInbox;
use ControlBot\ExternalApi\ExternalApiContract;
use ControlBot\ExternalApi\ExternalApiOwnerDecisionRead;
use ControlBot\ExternalApi\ExternalApiReadProjection;

require_once __DIR__ . '/../../OwnerInbox.php';
require_once __DIR__ . '/../../ExternalApiContract.php';
require_once __DIR__ . '/../../ExternalApiReadProjection.php'; require_once __DIR__ . '/../../ExternalApiOwnerDecisionRead.php';

$source = static function (callable $backend, string $operation, array $vars, array $request, int $now): array {
    $resolved = $backend($operation, $vars, $request, $now);
    if (!is_array($resolved) || array_is_list($resolved))
        throw new RuntimeException('Trusted owner source unavailable.');
    return $resolved;
};

return [
    ['method' => 'GET', 'path' => '/api/v1/cockpit',
        'handler' => static function (callable $backend, array $vars, array $request, int $now) use ($source): array {
            try {
                $data = $source($backend, 'cockpit.read', $vars, $request, $now);
                return ExternalApiReadProjection::cockpit($data['meta'], $data['ventures']);
            } catch (InvalidArgumentException|JsonException $error) {
                throw new RuntimeException('Invalid trusted cockpit source.', 0, $error);
            }
        },
    ],
    ['method' => 'GET', 'path' => '/api/v1/owner-inbox',
        'handler' => static function (callable $backend, array $vars, array $request, int $now) use ($source): array {
            try {
                $data = $source($backend, 'owner_inbox.read', $vars, $request, $now);
                // Validate domain data before projection; never leak internal authority.
                $inbox = OwnerInbox::collection($data['entries']);
                $entries = [];
                $seenIds = [];
                foreach ($inbox['entries'] as $entry) {
                    $slug = substr($entry['entry_ref'], (int) strrpos($entry['entry_ref'], '/') + 1);
                    if (isset($seenIds[$slug])) throw new RuntimeException('Duplicate owner inbox entry ID.');
                    $seenIds[$slug] = true;
                    $decisionRef = $entry['decision_ref'];
                    $decisionId = $decisionRef === null ? null
                        : substr($decisionRef, (int) strrpos($decisionRef, '/') + 1);
                    $entries[] = [
                        'entry_id' => $slug,
                        'kind' => strtoupper($entry['class']),
                        'venture_ref' => $entry['scope']['kind'] === 'venture' ? $entry['scope']['ref'] : null,
                        'title' => $entry['title'],
                        'summary' => $entry['summary'],
                        'decision_id' => $decisionId,
                        'deadline_at' => $entry['deadline_at'],
                    ];
                }
                return ExternalApiReadProjection::ownerInbox($data['meta'], $entries);
            } catch (InvalidArgumentException|JsonException $error) {
                throw new RuntimeException('Invalid trusted inbox source.', 0, $error);
            }
        },
    ],
    ['method' => 'GET', 'path' => '/api/v1/owner-decisions/{decision_id}',
        'handler' => static function (callable $backend, array $vars, array $request, int $now) use ($source): array {
            try {
                $data = $source($backend, 'owner_decision.read', $vars, $request, $now);
                return ExternalApiOwnerDecisionRead::detail(
                    $data['access'], $data['authentication'], $data['scope'],
                    $vars['decision_id'], $data['meta'], $data['detail'], $now,
                );
            } catch (InvalidArgumentException|JsonException $error) {
                throw new RuntimeException('Invalid trusted decision source.', 0, $error);
            }
        },
    ],
    ['method' => 'POST', 'path' => '/api/v1/owner-decisions/{decision_id}/decision',
        'handler' => static function (callable $backend, array $vars, array $request, int $now) use ($source): array {
            $mutation = ExternalApiContract::decisionMutation($request);
            // Only audited, idempotent DecisionRuntime persistence may report committed=true.
            $data = $source($backend, 'owner_decision.decide', $vars, $mutation, $now);
            if (($data['committed'] ?? false) !== true
                || !is_array($data['response'] ?? null)
                || array_is_list($data['response'])
                || !isset($data['response']['meta'], $data['response']['data'])) {
                throw new RuntimeException('Decision was not committed.');
            }
            try {
                $meta = ExternalApiContract::responseMeta($data['response']['meta']);
                $body = $data['response']['data'];
            } catch (InvalidArgumentException|JsonException $error) {
                throw new RuntimeException('Invalid committed decision source.', 0, $error);
            }
            if (!is_array($body) || array_is_list($body)
                || ($body['decision_id'] ?? null) !== $vars['decision_id']
                || ($body['outcome'] ?? null) !== $mutation['outcome']) {
                throw new RuntimeException('Committed decision mismatch.');
            }
            return ['meta' => $meta, 'data' => $body];
        },
    ],
];
