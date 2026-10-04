<?php
declare(strict_types=1);

namespace ControlBot\GitHub;

use InvalidArgumentException;

final class GitHubIntentEnvelope
{
    private const TYPES = [
        'issue.create',
        'issue.update',
        'issue.close',
        'issue.reserve',
        'issue.release',
        'pr.review',
        'pr.merge',
        'workflow.dispatch',
        'release.approve',
        'project.freeze',
        'project.unfreeze',
    ];

    private const PARAMS = [
        'issue.create' => ['payload_ref' => 'ref'],
        'issue.update' => ['issue_number' => 'positive_int', 'payload_ref' => 'ref'],
        'issue.close' => ['issue_number' => 'positive_int'],
        'issue.reserve' => ['issue_number' => 'positive_int', 'reservation_ref' => 'ref'],
        'issue.release' => ['issue_number' => 'positive_int', 'reservation_ref' => 'ref'],
        'pr.review' => ['pr_number' => 'positive_int', 'review_ref' => 'ref'],
        'pr.merge' => [
            'pr_number' => 'positive_int',
            'expected_head_sha' => 'sha',
            'merge_method' => 'merge_method',
        ],
        'workflow.dispatch' => [
            'workflow_ref' => 'workflow',
            'git_ref' => 'git_ref',
            'inputs_ref' => 'ref',
        ],
        'release.approve' => [
            'release_ref' => 'ref',
            'candidate_sha' => 'sha',
            'approval_ref' => 'ref',
        ],
        'project.freeze' => ['reason_ref' => 'ref'],
        'project.unfreeze' => ['reason_ref' => 'ref'],
    ];

    private const SENSITIVE_COMPONENTS =
        '#(?:^|[:/._-])(?:password|passwd|secret|credential|authorization|bearer|'
        .'private[-_ ]?key|api[-_ ]?key|access[-_ ]?token|refresh[-_ ]?token|token|otp|cookie)'
        .'(?:$|[:/._-])#i';

    /** Normalize one GitHub-specific intent without granting or executing authority. */
    public static function envelope(array $raw): array
    {
        self::exactFields($raw, [
            'version',
            'intent_id',
            'project_ref',
            'repository_ref',
            'type',
            'params',
            'idempotency_key',
            'evidence_refs',
        ], 'GitHubIntentEnvelope');

        if (($raw['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('GitHubIntentEnvelope version invalid.');
        }

        $type = self::enumValue($raw['type'], self::TYPES, 'type');

        return [
            'version' => 1,
            'intent_id' => self::opaqueId($raw['intent_id']),
            'project_ref' => self::projectRef($raw['project_ref']),
            'repository_ref' => self::repositoryRef($raw['repository_ref']),
            'type' => $type,
            'params' => self::params($type, $raw['params']),
            'idempotency_key' => self::idempotencyKey($raw['idempotency_key']),
            'evidence_refs' => self::evidenceRefs($raw['evidence_refs']),
            'execution' => false,
        ];
    }

    private static function params(string $type, mixed $raw): array
    {
        if (!is_array($raw) || array_is_list($raw)) {
            throw new InvalidArgumentException('params invalid.');
        }

        $schema = self::PARAMS[$type];
        self::exactFields($raw, array_keys($schema), 'params');

        $out = [];
        foreach ($schema as $field => $kind) {
            $out[$field] = match ($kind) {
                'positive_int' => self::positiveInt($raw[$field], $field),
                'ref' => self::internalRef($raw[$field], $field),
                'sha' => self::sha($raw[$field], $field),
                'merge_method' => self::enumValue(
                    $raw[$field],
                    ['merge', 'squash', 'rebase'],
                    $field,
                ),
                'workflow' => self::workflowRef($raw[$field]),
                'git_ref' => self::gitRef($raw[$field]),
                default => throw new InvalidArgumentException('params schema invalid.'),
            };
        }
        return $out;
    }

    private static function exactFields(array $raw, array $fields, string $label): void
    {
        if (array_is_list($raw)) {
            throw new InvalidArgumentException($label . ' fields invalid.');
        }
        $expected = array_fill_keys($fields, true);
        if (array_diff_key($raw, $expected) !== [] || array_diff_key($expected, $raw) !== []) {
            throw new InvalidArgumentException($label . ' fields invalid.');
        }
    }

    private static function opaqueId(mixed $value): string
    {
        if (!is_string($value)
            || preg_match('/^[a-f0-9]{32}$/D', $value) !== 1
        ) {
            throw new InvalidArgumentException('intent_id invalid.');
        }
        return $value;
    }

    private static function projectRef(mixed $value): string
    {
        if (!is_string($value)
            || preg_match('#^controlbot:project/[a-z][a-z0-9-]{1,63}$#D', $value) !== 1
            || self::sensitive($value)
        ) {
            throw new InvalidArgumentException('project_ref invalid.');
        }
        return $value;
    }

    private static function repositoryRef(mixed $value): string
    {
        if (!is_string($value)
            || strlen($value) > 160
            || preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D', $value) !== 1
            || self::sensitive($value)
        ) {
            throw new InvalidArgumentException('repository_ref invalid.');
        }
        return $value;
    }

    private static function idempotencyKey(mixed $value): string
    {
        if (!is_string($value)
            || strlen($value) < 8
            || strlen($value) > 128
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]+$/D', $value) !== 1
            || self::sensitive($value)
        ) {
            throw new InvalidArgumentException('idempotency_key invalid.');
        }
        return $value;
    }

    private static function evidenceRefs(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 20) {
            throw new InvalidArgumentException('evidence_refs invalid.');
        }

        $refs = [];
        foreach ($value as $ref) {
            $refs[] = self::internalRef($ref, 'evidence_ref');
        }
        if (count(array_unique($refs, SORT_STRING)) !== count($refs)) {
            throw new InvalidArgumentException('evidence_refs duplicated.');
        }
        return $refs;
    }

    private static function internalRef(mixed $value, string $label): string
    {
        if (!is_string($value)
            || strlen($value) > 180
            || preg_match('#^(?:controlbot|github):[a-z][a-z0-9._/-]{1,159}$#D', $value) !== 1
            || self::sensitive($value)
        ) {
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

    private static function sha(mixed $value, string $label): string
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{40}$/D', $value) !== 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function workflowRef(mixed $value): string
    {
        if (!is_string($value)
            || strlen($value) > 120
            || preg_match('/^[A-Za-z0-9_.-]+(?:\.ya?ml)?$/D', $value) !== 1
            || self::sensitive($value)
        ) {
            throw new InvalidArgumentException('workflow_ref invalid.');
        }
        return $value;
    }

    private static function gitRef(mixed $value): string
    {
        if (!is_string($value)
            || strlen($value) > 120
            || str_contains($value, '..')
            || preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#D', $value) !== 1
            || self::sensitive($value)
        ) {
            throw new InvalidArgumentException('git_ref invalid.');
        }
        return $value;
    }

    private static function enumValue(mixed $value, array $allowed, string $label): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function sensitive(string $value): bool
    {
        return preg_match(self::SENSITIVE_COMPONENTS, $value) === 1;
    }
}
