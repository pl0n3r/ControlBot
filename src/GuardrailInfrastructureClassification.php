<?php

declare(strict_types=1);

namespace ControlBot\Guardrail;

use ControlBot\Scheduler\SchedulerCore;
use InvalidArgumentException;

final class GuardrailInfrastructureClassification
{
    private const FRESH = ['fresh', 'stale', 'unknown'];
    private const EXT = ['blocked', 'healthy', 'unknown'];
    private const KINDS = ['capacity', 'quota', 'billing', 'entitlement'];
    private const OUTCOME = ['success', 'failure', 'unknown'];

    public static function project(
        array $agentAnalysis,
        array $runs,
        ?array $external,
        ?string $activeExternalFingerprint = null
    ): array {
        $agent = self::agent($agentAnalysis);
        $runs = self::runs($runs);
        $external = self::external($external);
        $active = self::nullableSha(
            $activeExternalFingerprint,
            'active_external_fingerprint'
        );

        $pre = array_values(array_filter(
            $runs,
            fn ($run) => $run['startup_failure'] && $run['steps'] === null
        ));
        $private = array_values(array_filter(
            $pre,
            fn ($run) => $run['visibility'] === 'private'
        ));
        $publicHealthy = array_values(array_filter(
            $runs,
            fn ($run) => $run['visibility'] === 'public'
                && !$run['startup_failure']
                && $run['runner_id'] > 0
                && $run['steps'] !== null
                && $run['steps'] !== []
                && $run['outcome'] === 'success'
                && $run['freshness'] === 'fresh'
        ));

        $correlated = self::correlatedPrivateFailure($private);
        $latestRunObservedAt = max(array_column($runs, 'observed_at'));
        $externalClearsAgent = $external === null
            || (
                $external['freshness'] === 'fresh'
                && $external['state'] === 'healthy'
                && $external['observed_at'] >= $latestRunObservedAt
            );

        $classification = 'unknown';
        $externalFingerprint = null;

        if (
            $correlated !== null
            && $publicHealthy !== []
            && $external !== null
            && $external['freshness'] === 'fresh'
            && $external['state'] === 'blocked'
            && $external['dependency_ref'] === $correlated['dependency_ref']
            && $external['observed_at'] >= $correlated['observed_at']
            && hash_equals(
                $external['fingerprint'],
                $correlated['fingerprint']
            )
        ) {
            $classification = 'blocked_by_infrastructure';
            $externalFingerprint = $external['fingerprint'];
        } elseif (
            $pre === []
            && $externalClearsAgent
            && $agent['health'] === 'stuck'
        ) {
            $classification = 'agent_stuck';
        }

        $retry = null;
        $handoff = null;

        if ($classification === 'blocked_by_infrastructure') {
            $retry = [
                'action' => 'suppress_retry',
                'fingerprint' => $externalFingerprint,
                'dependency_ref' => $correlated['dependency_ref'],
                'deduplicated' => $active !== null
                    && hash_equals($active, $externalFingerprint),
            ];

            $evidence = $correlated['evidence_refs'];
            $evidence[] = $external['evidence_ref'];
            $evidence = array_values(array_unique($evidence));
            sort($evidence, SORT_STRING);

            $handoff = [
                'status' => 'waiting_dependency',
                'source_ref' => $correlated['source_ref'],
                'cause' => 'blocked_by_infrastructure',
                'dependency_ref' => $correlated['dependency_ref'],
                'fingerprint' => $externalFingerprint,
                'evidence_refs' => $evidence,
                'next_action' => 'wait for fresh infrastructure recovery then run one canary',
            ];
        }

        $canary = null;

        if (
            $active !== null
            && $correlated !== null
            && $external !== null
            && $external['freshness'] === 'fresh'
            && $external['state'] === 'healthy'
            && $external['dependency_ref'] === $correlated['dependency_ref']
            && $external['observed_at'] > $correlated['observed_at']
            && hash_equals($active, $correlated['fingerprint'])
            && !hash_equals($active, $external['fingerprint'])
        ) {
            $source = $correlated['source_ref'];
            $projectId = self::projectIdFromSourceRef($source);
            $canary = SchedulerCore::workItem([
                'version' => 1,
                'work_item_id' => 'infra-canary-' . substr($active, 0, 20),
                'project_id' => $projectId,
                'source_ref' => $source,
                'type' => 'verification',
                'priority' => 'critical',
                'state' => 'queued',
                'dependency_ids' => [],
                'required_capabilities' => ['ci'],
                'generation' => 1,
                'attempt' => 1,
                'reservation_id' => null,
                'assigned_session_id' => null,
            ]);
        }

        $result = [
            'version' => 1,
            'classification' => $classification,
            'agent_fingerprint' => $agent['alert_fingerprint'],
            'external_fingerprint' => $externalFingerprint,
            'retry_intent' => $retry,
            'handoff' => $handoff,
            'canary_work_item' => $canary,
            'queue_release_allowed' => false,
        ];

        return $result + [
            'fingerprint' => hash(
                'sha256',
                json_encode(
                    $result,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
                )
            ),
        ];
    }

    private static function correlatedPrivateFailure(array $runs): ?array
    {
        if (count($runs) < 2) {
            return null;
        }

        $first = $runs[0];
        $evidence = [];
        $repositories = [];
        $sources = [];
        $observedAt = 0;

        foreach ($runs as $run) {
            if (
                $run['freshness'] !== 'fresh'
                || $run['runner_id'] !== 0
                || $run['outcome'] !== 'failure'
                || !hash_equals(
                    $first['failure_fingerprint'],
                    $run['failure_fingerprint']
                )
                || $first['dependency_ref'] !== $run['dependency_ref']
            ) {
                return null;
            }

            $evidence[] = $run['evidence_ref'];
            $repositories[] = strstr($run['source_ref'], '#', true);
            $sources[] = $run['source_ref'];
            $observedAt = max($observedAt, $run['observed_at']);
        }

        if (count(array_unique($repositories)) < 2) {
            return null;
        }

        $evidence = array_values(array_unique($evidence));
        sort($evidence, SORT_STRING);

        $sources = array_values(array_unique($sources));
        sort($sources, SORT_STRING);

        return [
            'fingerprint' => $first['failure_fingerprint'],
            'dependency_ref' => $first['dependency_ref'],
            'observed_at' => $observedAt,
            'source_ref' => $sources[0],
            'evidence_refs' => $evidence,
        ];
    }

    private static function agent(array $raw): array
    {
        if (
            !in_array(
                $raw['health'] ?? null,
                ['healthy', 'degraded', 'stuck'],
                true
            )
            || !is_bool($raw['pause_required'] ?? null)
            || !is_bool($raw['escalation_required'] ?? null)
        ) {
            throw new InvalidArgumentException('agent analysis invalid.');
        }

        self::sha(
            $raw['alert_fingerprint'] ?? null,
            'agent alert_fingerprint'
        );

        return $raw;
    }

    private static function runs(mixed $rows): array
    {
        if (
            !is_array($rows)
            || !array_is_list($rows)
            || $rows === []
            || count($rows) > 32
        ) {
            throw new InvalidArgumentException('run evidence invalid.');
        }

        $out = [];

        foreach ($rows as $run) {
            self::fields(
                $run,
                [
                    'source_ref',
                    'visibility',
                    'startup_failure',
                    'runner_id',
                    'steps',
                    'outcome',
                    'failure_fingerprint',
                    'dependency_ref',
                    'evidence_ref',
                    'observed_at',
                    'freshness',
                ],
                'RunEvidence'
            );

            if (
                !in_array($run['visibility'], ['private', 'public'], true)
                || !is_bool($run['startup_failure'])
                || !is_int($run['runner_id'])
                || $run['runner_id'] < 0
                || (
                    $run['steps'] !== null
                    && (
                        !is_array($run['steps'])
                        || !array_is_list($run['steps'])
                    )
                )
                || !in_array($run['outcome'], self::OUTCOME, true)
                || !is_int($run['observed_at'])
                || $run['observed_at'] < 1
                || !in_array($run['freshness'], self::FRESH, true)
            ) {
                throw new InvalidArgumentException('run evidence invalid.');
            }

            $out[] = [
                'source_ref' => self::workRef($run['source_ref']),
                'visibility' => $run['visibility'],
                'startup_failure' => $run['startup_failure'],
                'runner_id' => $run['runner_id'],
                'steps' => $run['steps'],
                'outcome' => $run['outcome'],
                'failure_fingerprint' => self::sha(
                    $run['failure_fingerprint'],
                    'failure_fingerprint'
                ),
                'dependency_ref' => self::ref(
                    $run['dependency_ref'],
                    'dependency_ref'
                ),
                'evidence_ref' => self::ref(
                    $run['evidence_ref'],
                    'evidence_ref'
                ),
                'observed_at' => $run['observed_at'],
                'freshness' => $run['freshness'],
            ];
        }

        return $out;
    }

    private static function external(?array $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        self::fields(
            $raw,
            [
                'state',
                'kind',
                'dependency_ref',
                'fingerprint',
                'evidence_ref',
                'observed_at',
                'freshness',
            ],
            'ExternalSignal'
        );

        if (
            !in_array($raw['state'], self::EXT, true)
            || !in_array($raw['kind'], self::KINDS, true)
            || !is_int($raw['observed_at'])
            || $raw['observed_at'] < 1
            || !in_array($raw['freshness'], self::FRESH, true)
        ) {
            throw new InvalidArgumentException('external signal invalid.');
        }

        return $raw + [
            'dependency_ref' => self::ref(
                $raw['dependency_ref'],
                'dependency_ref'
            ),
            'fingerprint' => self::sha(
                $raw['fingerprint'],
                'external fingerprint'
            ),
            'evidence_ref' => self::ref(
                $raw['evidence_ref'],
                'external evidence_ref'
            ),
        ];
    }

    private static function projectIdFromSourceRef(string $sourceRef): string
    {
        $pattern = '/^[A-Za-z0-9_.-]+\/([A-Za-z0-9_.-]+)#[1-9][0-9]*$/D';

        if (preg_match($pattern, $sourceRef, $match) !== 1) {
            throw new InvalidArgumentException('source_ref invalid.');
        }

        $projectId = strtolower($match[1]);

        if (preg_match('/^[a-z][a-z0-9._-]{0,79}$/D', $projectId) !== 1) {
            throw new InvalidArgumentException(
                'source_ref project_id invalid.'
            );
        }

        return $projectId;
    }

    private static function nullableSha(mixed $value, string $label): ?string
    {
        return $value === null ? null : self::sha($value, $label);
    }

    private static function sha(mixed $value, string $label): string
    {
        if (
            !is_string($value)
            || preg_match('/^[0-9a-f]{64}$/D', $value) !== 1
        ) {
            throw new InvalidArgumentException($label . ' invalid.');
        }

        return $value;
    }

    private static function workRef(mixed $value): string
    {
        $pattern = '/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+#[1-9][0-9]*$/D';

        if (!is_string($value) || preg_match($pattern, $value) !== 1) {
            throw new InvalidArgumentException('source_ref invalid.');
        }

        return $value;
    }

    private static function ref(mixed $value, string $label): string
    {
        $pattern = '/^controlbot:[A-Za-z0-9][A-Za-z0-9._:\/#-]{0,179}$/D';
        $sensitive = '/(?:token|secret|password|cookie|authorization|dsn)/i';

        if (
            !is_string($value)
            || preg_match($pattern, $value) !== 1
            || preg_match($sensitive, $value) === 1
        ) {
            throw new InvalidArgumentException($label . ' invalid.');
        }

        return $value;
    }

    private static function fields(
        mixed $record,
        array $expected,
        string $label
    ): void {
        if (!is_array($record) || array_is_list($record)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }

        $actual = array_keys($record);
        sort($actual);
        sort($expected);

        if ($actual !== $expected) {
            throw new InvalidArgumentException($label . ' fields invalid.');
        }
    }
}
