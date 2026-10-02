<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

final class FactoryLiveOrchestratorSnapshot
{
    private const REPOSITORIES = [
        'pl0n3r/Factory',
        'pl0n3r/Condor',
        'pl0n3r/GrindFlow',
        'pl0n3r/brvtal',
        'pl0n3r/ControlBot',
        'pl0n3r/AutoFactory',
        'pl0n3r/FactoryRunner',
    ];
    private const FRONT_STATES = ['available','reserved','in_review','blocked','merged','unknown'];
    private const FRONT_LIMIT = 24;
    private const SENSITIVE = '/(?:password|passwd|secret|token|cookie|authorization|bearer|private[_ -]?key|api[_ -]?key|dsn)/i';
    private const PII = '/(?:[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}|\+?(?=(?:[0-9(). -]*[0-9]){10})[0-9][0-9(). -]{7,}[0-9])/i';

    public static function build(array $snapshot, int $now): array
    {
        if ($now < 1 || array_is_list($snapshot)) {
            throw new InvalidArgumentException('Orchestrator snapshot input invalid.');
        }
        if (($snapshot['version'] ?? null) !== 1
            || !is_int($snapshot['observed_at'] ?? null)
            || $snapshot['observed_at'] < 1
            || $snapshot['observed_at'] > $now
            || !is_array($snapshot['sections'] ?? null)
            || array_is_list($snapshot['sections'])
            || !is_string($snapshot['fingerprint'] ?? null)
            || preg_match('/^[0-9a-f]{64}$/D', $snapshot['fingerprint']) !== 1) {
            throw new InvalidArgumentException('Canonical FactoryLiveSnapshot invalid.');
        }

        $central = self::central($snapshot['work_inventory'] ?? null, $now);
        $fronts = self::fronts($snapshot['sections']['work'] ?? null, $now);
        $ownerDecisions = self::ownerDecisions($snapshot['sections']['owner_decisions'] ?? null, $now);

        $canonical = [
            'version' => 1,
            'observed_at' => $now,
            'source_snapshot' => $snapshot['fingerprint'],
            'read_only' => true,
            'central' => $central,
            'fronts' => $fronts,
            'owner_decisions' => $ownerDecisions,
        ];

        return $canonical + [
            'fingerprint' => hash(
                'sha256',
                json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
            ),
        ];
    }

    private static function central(mixed $inventory, int $now): array
    {
        if ($inventory === null) {
            return [
                'activity_state' => 'UNKNOWN',
                'available' => null,
                'reserved' => null,
                'blocked' => null,
                'source_ref' => null,
                'observed_at' => null,
                'freshness' => 'unknown',
                'age_seconds' => null,
            ];
        }
        if (!is_array($inventory) || array_is_list($inventory)) {
            throw new InvalidArgumentException('work_inventory invalid.');
        }

        $source = self::safeText($inventory['source_ref'] ?? null, 'work_inventory.source_ref', 240);
        $observed = self::time($inventory['observed_at'] ?? null, 'work_inventory.observed_at', $now);
        $freshness = $inventory['freshness'] ?? null;
        if (!is_string($freshness) || !in_array($freshness, ['current','stale'], true)) {
            throw new InvalidArgumentException('work_inventory.freshness invalid.');
        }
        $projects = $inventory['projects'] ?? null;
        if (!is_array($projects) || !array_is_list($projects) || count($projects) !== count(self::REPOSITORIES)) {
            throw new InvalidArgumentException('work_inventory.projects invalid.');
        }

        $available = 0;
        $reserved = 0;
        $blocked = 0;
        foreach ($projects as $index => $project) {
            if (!is_array($project) || array_is_list($project)
                || ($project['repository_ref'] ?? null) !== self::REPOSITORIES[$index]
                || !is_array($project['counts'] ?? null)
                || array_is_list($project['counts'])) {
                throw new InvalidArgumentException('work_inventory project invalid.');
            }
            $available += self::count($project['counts']['available'] ?? null, 'available');
            $reserved += self::count($project['counts']['reserved'] ?? null, 'reserved');
            $blocked += self::count($project['counts']['blocked'] ?? null, 'blocked');
        }

        $activity = $freshness === 'stale'
            ? 'STALE'
            : ($reserved > 0 ? 'ACTIVE' : ($available > 0 ? 'READY' : ($blocked > 0 ? 'BLOCKED' : 'IDLE')));

        return [
            'activity_state' => $activity,
            'available' => $available,
            'reserved' => $reserved,
            'blocked' => $blocked,
            'source_ref' => $source,
            'observed_at' => $observed,
            'freshness' => $freshness,
            'age_seconds' => $now - $observed,
        ];
    }

    private static function fronts(mixed $signals, int $now): array
    {
        if (!is_array($signals) || !array_is_list($signals)) {
            throw new InvalidArgumentException('work signals invalid.');
        }

        $out = [];
        foreach ($signals as $signal) {
            self::signal($signal, 'github_project_snapshot', $now);
            if (($signal['freshness'] ?? null) === 'unknown') {
                continue;
            }
            $data = $signal['data'] ?? null;
            if (!is_array($data) || array_is_list($data)) {
                throw new InvalidArgumentException('work data invalid.');
            }

            $repository = self::repository($data['repository_ref'] ?? null);
            $issueRef = self::issueRef($data['issue_ref'] ?? null);
            $status = $data['status'] ?? 'unknown';
            if (!is_string($status) || !in_array($status, self::FRONT_STATES, true)) {
                throw new InvalidArgumentException('work status invalid.');
            }

            $progress = null;
            $progressState = 'UNKNOWN';
            if (($signal['freshness'] ?? null) === 'current'
                && array_key_exists('progress_percent', $data)
                && array_key_exists('progress_evidence', $data)) {
                $percent = $data['progress_percent'];
                if (!is_int($percent) || $percent < 0 || $percent > 100) {
                    throw new InvalidArgumentException('progress_percent invalid.');
                }
                self::safeText($data['progress_evidence'], 'progress_evidence', 240);
                $progress = $percent;
                $progressState = 'EVIDENCED';
            }

            $out[] = [
                'id' => self::safeId($signal['id'] ?? null),
                'repository_ref' => $repository,
                'issue_ref' => $issueRef,
                'status' => $status,
                'progress_percent' => $progress,
                'progress_state' => $progressState,
                'source_ref' => self::safeText($signal['source_ref'] ?? null, 'work.source_ref', 240),
                'observed_at' => self::time($signal['observed_at'] ?? null, 'work.observed_at', $now),
                'freshness' => $signal['freshness'],
                'age_seconds' => $now - $signal['observed_at'],
            ];
        }

        if (count($out) > self::FRONT_LIMIT) {
            throw new InvalidArgumentException('Too many active fronts.');
        }
        usort($out, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
        return $out;
    }

    private static function ownerDecisions(mixed $signals, int $now): array
    {
        if (!is_array($signals) || !array_is_list($signals)) {
            throw new InvalidArgumentException('owner_decisions signals invalid.');
        }

        $out = [];
        foreach ($signals as $signal) {
            self::signal($signal, 'owner_inbox', $now);
            if (($signal['freshness'] ?? null) === 'unknown') {
                continue;
            }
            $data = $signal['data'] ?? null;
            if (!is_array($data) || array_is_list($data)) {
                throw new InvalidArgumentException('owner decision data invalid.');
            }
            $out[] = [
                'id' => self::safeId($signal['id'] ?? null),
                'issue_ref' => self::issueRef($data['issue_ref'] ?? null),
                'source_ref' => self::safeText($signal['source_ref'] ?? null, 'owner_decision.source_ref', 240),
                'observed_at' => self::time($signal['observed_at'] ?? null, 'owner_decision.observed_at', $now),
                'freshness' => $signal['freshness'],
                'age_seconds' => $now - $signal['observed_at'],
            ];
        }
        usort($out, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
        return $out;
    }

    private static function signal(mixed $signal, string $authority, int $now): void
    {
        if (!is_array($signal) || array_is_list($signal)
            || ($signal['authority'] ?? null) !== $authority
            || !is_string($signal['freshness'] ?? null)
            || !in_array($signal['freshness'], ['current','stale','unknown'], true)) {
            throw new InvalidArgumentException('Canonical signal invalid.');
        }

        if ($signal['freshness'] === 'unknown') {
            if (($signal['source_ref'] ?? null) !== null || ($signal['observed_at'] ?? null) !== null) {
                throw new InvalidArgumentException('Unknown signal provenance invalid.');
            }
            return;
        }

        self::safeText($signal['source_ref'] ?? null, 'signal.source_ref', 240);
        self::time($signal['observed_at'] ?? null, 'signal.observed_at', $now);
        if ($signal['freshness'] === 'stale' && ($signal['state'] ?? null) === 'healthy') {
            throw new InvalidArgumentException('Stale signal cannot be healthy.');
        }
    }

    private static function repository(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::REPOSITORIES, true)) {
            throw new InvalidArgumentException('repository_ref invalid.');
        }
        return $value;
    }

    private static function issueRef(mixed $value): string
    {
        if (!is_string($value) || preg_match(
            '~^(?:https://github\.com/pl0n3r/[A-Za-z0-9_.-]+/issues/[1-9][0-9]*|github:pl0n3r/[A-Za-z0-9_.-]+#[1-9][0-9]*)$~D',
            $value
        ) !== 1) {
            throw new InvalidArgumentException('issue_ref invalid.');
        }
        return self::safeText($value, 'issue_ref', 240);
    }

    private static function safeId(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9._:\/#-]{2,160}$/D', $value) !== 1) {
            throw new InvalidArgumentException('signal id invalid.');
        }
        return self::safeText($value, 'signal.id', 180);
    }

    private static function safeText(mixed $value, string $label, int $max): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > $max
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1
            || preg_match(self::SENSITIVE, $value) === 1
            || preg_match(self::PII, $value) === 1) {
            throw new InvalidArgumentException($label . ' unsafe.');
        }
        return $value;
    }

    private static function time(mixed $value, string $label, int $now): int
    {
        if (!is_int($value) || $value < 1 || $value > $now) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function count(mixed $value, string $label): int
    {
        if (!is_int($value) || $value < 0 || $value > 1_000_000) {
            throw new InvalidArgumentException($label . ' count invalid.');
        }
        return $value;
    }
}
