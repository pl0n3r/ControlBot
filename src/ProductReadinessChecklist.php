<?php
declare(strict_types=1);

namespace ControlBot\Readiness;

use InvalidArgumentException;

/** Pure Definition of Done evidence projection. Never authorizes go-live. */
final class ProductReadinessChecklist
{
    private const PRIORITIES = ['P0', 'P1', 'P2', 'P3'];
    private const CONCLUSIONS = ['pass', 'fail', 'unknown'];
    private const LIMIT = 64;

    /** @param array<int, mixed> $criteria */
    public static function evaluate(array $criteria, int $now): array
    {
        if ($now < 1 || !array_is_list($criteria) || $criteria === [] || count($criteria) > self::LIMIT) {
            throw new InvalidArgumentException('Readiness inventory invalid.');
        }
        $rows = [];
        $seen = [];
        $noGo = false;
        foreach ($criteria as $raw) {
            self::exactFields($raw, ['id', 'priority', 'issue', 'blocker', 'evidence']);
            $id = $raw['id'];
            $priority = $raw['priority'];
            if (!is_string($id) || preg_match('/^[A-Z][A-Z0-9_-]{1,39}$/D', $id) !== 1 || isset($seen[$id])
                || !is_string($priority) || !in_array($priority, self::PRIORITIES, true)) {
                throw new InvalidArgumentException('Readiness criterion identity invalid.');
            }
            $seen[$id] = true;
            $issue = $raw['issue'];
            $blocker = $raw['blocker'];
            if ($issue !== null && (!is_int($issue) || $issue < 1)) {
                throw new InvalidArgumentException('Readiness issue invalid.');
            }
            if ($blocker !== null && (!is_string($blocker) || !preg_match('/^[a-z][a-z0-9_]{2,49}$/D', $blocker))) {
                throw new InvalidArgumentException('Readiness blocker invalid.');
            }
            $status = 'UNKNOWN';
            $reason = 'evidence_missing';
            if ($issue === null && in_array($priority, ['P0', 'P1'], true)) {
                $reason = 'critical_issue_missing';
            } elseif ($issue === null && $blocker === null) {
                $reason = 'issue_or_blocker_missing';
            } else {
                [$status, $reason] = self::evidence($raw['evidence'], $now);
            }
            if ($status !== 'PASS') {
                $noGo = true;
            }
            $rows[] = [
                'id' => $id,
                'priority' => $priority,
                'issue' => $issue,
                'has_blocker' => $blocker !== null,
                'status' => $status,
                'reason' => $reason,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));
        return [
            'version' => 1,
            'observed_at' => $now,
            'decision' => $noGo ? 'NO_GO' : 'CANDIDATE_ONLY',
            'no_go' => $noGo,
            'rows' => $rows,
            'authorizes_live' => false,
        ];
    }

    /** @return array{string,string} */
    private static function evidence(mixed $raw, int $now): array
    {
        if ($raw === null) {
            return ['UNKNOWN', 'evidence_missing'];
        }
        self::exactFields($raw, ['source_ref', 'revision', 'observed_at', 'valid_for_seconds', 'conclusion']);
        $source = $raw['source_ref'];
        $revision = $raw['revision'];
        $observed = $raw['observed_at'];
        $validFor = $raw['valid_for_seconds'];
        $conclusion = $raw['conclusion'];
        if (!is_string($source) || preg_match('~^https://github\.com/pl0n3r/[A-Za-z0-9_.-]+/(?:actions/runs|issues|pull)/[1-9][0-9]*$~D', $source) !== 1
            || !is_string($revision) || preg_match('/^(?:[a-f0-9]{40}|v[0-9]+(?:\.[0-9]+){1,2})$/D', $revision) !== 1
            || !is_int($observed) || $observed < 1 || $observed > $now
            || !is_int($validFor) || $validFor < 1 || $validFor > 86400
            || !is_string($conclusion) || !in_array($conclusion, self::CONCLUSIONS, true)) {
            return ['UNKNOWN', 'evidence_invalid'];
        }
        if ($now - $observed > $validFor) {
            return ['UNKNOWN', 'evidence_stale'];
        }
        if ($conclusion === 'unknown') {
            return ['UNKNOWN', 'evidence_unknown'];
        }
        return $conclusion === 'pass' ? ['PASS', 'verified'] : ['FAIL', 'evidence_failed'];
    }

    private static function exactFields(mixed $raw, array $fields): void
    {
        if (!is_array($raw) || array_is_list($raw) || array_keys($raw) !== $fields) {
            throw new InvalidArgumentException('Readiness evidence shape invalid.');
        }
    }
}
