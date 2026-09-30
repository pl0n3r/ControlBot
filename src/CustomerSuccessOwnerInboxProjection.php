<?php
declare(strict_types=1);

namespace ControlBot\CustomerSuccess;

use ControlBot\Business\OwnerInbox;
use InvalidArgumentException;

final class CustomerSuccessOwnerInboxProjection
{
    public static function fromSupport(
        array $supportRaw,
        string $expectedVentureId,
        array $entryRaw,
    ): array {
        self::entryFields($entryRaw);
        $signal = CustomerSuccessCore::supportSignal($supportRaw, $expectedVentureId);

        return self::ownerEntry(
            ventureId: $signal['venture_id'],
            kind: 'support',
            identity: $signal['signal_id'],
            freshness: $signal['freshness'],
            observedAt: $signal['observed_at'],
            evidenceRef: $signal['evidence_ref'],
            entryRaw: $entryRaw,
        );
    }

    public static function fromSnapshotDimension(
        array $snapshotRaw,
        string $expectedVentureId,
        string $dimensionName,
        array $entryRaw,
    ): array {
        self::entryFields($entryRaw);
        $snapshot = CustomerSuccessCore::snapshot($snapshotRaw, $expectedVentureId);
        $dimension = null;
        foreach ($snapshot['dimensions'] as $candidate) {
            if (($candidate['name'] ?? null) === $dimensionName) {
                $dimension = $candidate;
                break;
            }
        }
        if ($dimension === null) {
            throw new InvalidArgumentException('Customer success dimension not found.');
        }

        return self::ownerEntry(
            ventureId: $snapshot['venture_id'],
            kind: 'health/'.$dimensionName,
            identity: $snapshot['snapshot_id'].'|'.$dimensionName,
            freshness: $dimension['freshness'],
            observedAt: $dimension['freshness'] === 'unknown' ? null : $snapshot['observed_at'],
            evidenceRef: $dimension['evidence_ref'],
            entryRaw: $entryRaw,
        );
    }

    private static function ownerEntry(
        string $ventureId,
        string $kind,
        string $identity,
        string $freshness,
        ?int $observedAt,
        ?string $evidenceRef,
        array $entryRaw,
    ): array {
        $mappedFreshness = match ($freshness) {
            'fresh' => 'current',
            'stale' => 'stale',
            'unknown' => 'unknown',
            default => throw new InvalidArgumentException('Customer success freshness invalid.'),
        };

        $sourceRef = null;
        $evidence = [];
        if ($mappedFreshness !== 'unknown') {
            if ($observedAt === null || $observedAt < 1) {
                throw new InvalidArgumentException('Customer success provenance invalid.');
            }
            $sourceRef = 'controlbot:customer-success/source/'.self::digest($identity);
            if ($evidenceRef !== null) {
                $evidence[] = 'controlbot:evidence/customer-success/'.self::digest($evidenceRef);
            }
        } elseif ($observedAt !== null || $evidenceRef !== null) {
            throw new InvalidArgumentException('Unknown customer success signal cannot carry observed provenance.');
        }

        return OwnerInbox::entry([
            'version' => 1,
            'entry_ref' => 'controlbot:customer-success/inbox/'.self::digest($kind.'|'.$identity),
            'class' => $entryRaw['class'],
            'scope' => ['kind' => 'venture', 'ref' => 'controlbot:venture/'.$ventureId],
            'title' => $entryRaw['title'],
            'summary' => $entryRaw['summary'],
            'impact' => $entryRaw['impact'],
            'actor_ref' => $entryRaw['actor_ref'],
            'required_authority_level' => $entryRaw['required_authority_level'],
            'decision_ref' => $entryRaw['decision_ref'],
            'options_ref' => $entryRaw['options_ref'],
            'deadline_at' => $entryRaw['deadline_at'],
            'source_ref' => $sourceRef,
            'evidence_refs' => $evidence,
            'observed_at' => $mappedFreshness === 'unknown' ? null : $observedAt,
            'freshness' => $mappedFreshness,
        ]);
    }

    private static function digest(string $value): string
    {
        return implode('h', str_split(hash('sha256', $value), 4));
    }

    private static function entryFields(array $entryRaw): void
    {
        $expected = [
            'class','title','summary','impact','actor_ref',
            'required_authority_level','decision_ref','options_ref','deadline_at',
        ];
        $actual = array_keys($entryRaw);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException('Customer success Owner Inbox input fields invalid.');
        }
    }
}
