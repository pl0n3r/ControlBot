<?php
declare(strict_types=1);

use ControlBot\Requirements\RequirementIntake;
use ControlBot\Requirements\RequirementIntakeDecisionUi;

require_once __DIR__ . '/../../RequirementIntake.php';
require_once __DIR__ . '/../../RequirementIntakeDecisionUi.php';

// All state is supplied by the trusted, server-bound adapter. A missing adapter
// fails closed (503); nothing here calls RequirementMaterializer or GitHub.
$exact = static function (mixed $value, array $keys): array {
    if (!is_array($value) || array_is_list($value)) {
        throw new InvalidArgumentException('Invalid intake object.');
    }
    $actual = array_keys($value);
    sort($actual);
    sort($keys);
    if ($actual !== $keys) {
        throw new InvalidArgumentException('Invalid intake fields.');
    }
    return $value;
};
$source = static function (
    callable $backend,
    string $operation,
    array $vars,
    array $request,
    int $now,
): array {
    $result = $backend($operation, $vars, $request, $now);
    if (!is_array($result) || array_is_list($result)) {
        throw new RuntimeException('Trusted intake source unavailable.');
    }
    return $result;
};
$identifier = static function (mixed $value): string {
    if (!is_string($value) || preg_match('/^idea-[a-f0-9]{40}$/D', $value) !== 1) {
        throw new InvalidArgumentException('Invalid intake identifier.');
    }
    return $value;
};
$read = static function (callable $backend, array $vars, int $now) use (
    $exact,
    $source,
    $identifier,
): array {
    $id = $identifier($vars['intake_id'] ?? null);
    $record = $exact($source($backend, 'intake.read', ['intake_id' => $id], [], $now), [
        'draft', 'candidates',
    ]);
    if (!is_array($record['draft'])
        || ($record['draft']['source_ref'] ?? null) !== 'controlbot:requirement-source/web-intake'
        || !is_array($record['candidates'])) {
        throw new RuntimeException('Untrusted intake record.');
    }
    try {
        $proposal = RequirementIntake::analyze($record['draft'], $record['candidates']);
        $decision = RequirementIntakeDecisionUi::project($proposal);
    } catch (InvalidArgumentException $error) {
        throw new RuntimeException('Invalid stored intake record.', 0, $error);
    }
    if ($identifier('idea-' . substr($record['draft']['fingerprint'], 0, 40)) !== $id) {
        throw new RuntimeException('Intake identifier mismatch.');
    }
    return [
        'version' => 1,
        'intake_id' => $id,
        'draft_ref' => $record['draft']['draft_ref'],
        'proposal_ref' => $proposal['proposal_ref'],
        'decision' => $decision,
        'execution' => false,
    ];
};

return [
    [
        'method' => 'GET',
        'path' => '/api/v1/intake',
        'handler' => static function (
            callable $backend, array $vars, array $request, int $now,
        ) use ($source, $exact, $identifier): array {
            $data = $exact($source($backend, 'intake.list', [], [], $now), ['entries']);
            if (!is_array($data['entries']) || !array_is_list($data['entries'])
                || count($data['entries']) > 50) {
                throw new RuntimeException('Invalid intake index.');
            }
            $seen = [];
            $entries = [];
            foreach ($data['entries'] as $entry) {
                if (!is_array($entry) || array_keys($entry) !== ['intake_id']) {
                    throw new RuntimeException('Invalid intake index entry.');
                }
                $id = $identifier($entry['intake_id']);
                if (isset($seen[$id])) {
                    throw new RuntimeException('Duplicate intake.');
                }
                $seen[$id] = true;
                $entries[] = [
                    'intake_id' => $id,
                    'decision_path' => '/api/v1/intake/' . $id . '/decision',
                    'state' => 'pending_owner_decision',
                ];
            }
            return [
                'version' => 1,
                'form' => ['method' => 'POST', 'path' => '/api/v1/intake',
                    'fields' => ['content'], 'max_bytes' => 8000],
                'entries' => $entries,
                'execution' => false,
            ];
        },
    ],
    [
        'method' => 'POST',
        'path' => '/api/v1/intake',
        'handler' => static function (
            callable $backend, array $vars, array $request, int $now,
        ) use ($source, $exact, $identifier): array {
            $data = $exact($request, ['content']);
            $draft = RequirementIntake::normalize([
                'version' => 1,
                'source_kind' => 'text',
                'source_ref' => 'controlbot:requirement-source/web-intake',
                'captured_at' => $now,
                'content' => $data['content'],
            ]);
            $candidates = $exact(
                $source($backend, 'intake.candidates', [], [], $now), ['projects'],
            )['projects'];
            $proposal = RequirementIntake::analyze($draft, $candidates);
            $decision = RequirementIntakeDecisionUi::project($proposal);
            $id = $identifier('idea-' . substr($draft['fingerprint'], 0, 40));
            $receipt = $exact($source($backend, 'intake.record', [], [
                'intake_id' => $id,
                'draft' => $draft,
                'candidates' => $candidates,
            ], $now), ['stored', 'intake_id']);
            if ($receipt['stored'] !== true || $receipt['intake_id'] !== $id) {
                throw new RuntimeException('Intake was not recorded.');
            }
            return [
                'version' => 1,
                'intake_id' => $id,
                'draft_ref' => $draft['draft_ref'],
                'proposal_ref' => $proposal['proposal_ref'],
                'decision' => $decision,
                'decision_path' => '/api/v1/intake/' . $id . '/decision',
                'status' => 'pending_owner_decision',
                'execution' => false,
            ];
        },
    ],
    [
        'method' => 'GET',
        'path' => '/api/v1/intake/{intake_id}/decision',
        'handler' => static function (
            callable $backend, array $vars, array $request, int $now,
        ) use ($read): array {
            return $read($backend, $vars, $now);
        },
    ],
    [
        'method' => 'POST',
        'path' => '/api/v1/intake/{intake_id}/decision',
        'handler' => static function (
            callable $backend, array $vars, array $request, int $now,
        ) use ($read, $exact): array {
            $selection = $exact($request, ['project_mode', 'project_ref']);
            if (!in_array($selection['project_mode'], ['new', 'existing'], true)) {
                throw new InvalidArgumentException('Invalid project choice.');
            }
            $entry = $read($backend, $vars, $now);
            $matched = $entry['decision']['impacts']['project_match'];
            if ($selection['project_mode'] === 'new') {
                if ($selection['project_ref'] !== null) {
                    throw new InvalidArgumentException('New project cannot link existing.');
                }
                $project = [
                    'operation' => 'propose_create',
                    'project_ref' => null,
                ];
            } else {
                if ($matched['status'] !== 'matched'
                    || !is_string($selection['project_ref'])
                    || !hash_equals($matched['project_ref'], $selection['project_ref'])) {
                    throw new InvalidArgumentException('Unverified existing project.');
                }
                $project = [
                    'operation' => 'link_proposal',
                    'project_ref' => $matched['project_ref'],
                ];
            }
            // Never invoke RequirementMaterializer: a decision here is a
            // preview, not authorization to create a repository or Issue.
            return [
                'version' => 1,
                'intake_id' => $entry['intake_id'],
                'project' => $project,
                'epic' => $entry['decision']['materialization_diff']['epic'],
                'issues' => $entry['decision']['materialization_diff']['issues'],
                'status' => 'preview_only',
                'execution' => false,
                'committed' => false,
                'repositories_created' => 0,
                'issues_created' => 0,
            ];
        },
    ],
];
