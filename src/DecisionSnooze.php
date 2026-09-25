<?php
declare(strict_types=1);

namespace ControlBot\Decisions;

use ControlBot\Approvals\AppendOnlyAuditLog;
use ControlBot\Approvals\OwnerContext;
use InvalidArgumentException;
use RuntimeException;

final class DecisionSnooze
{
    private const DURATIONS = [
        'tomorrow' => 86400,
        'week' => 604800,
    ];

    public function __construct(private readonly AppendOnlyAuditLog $audit) {}

    public function snooze(
        array $decisions,
        string $repository,
        int $issue,
        string $duration,
        OwnerContext $owner,
        int $now,
    ): array {
        if (!isset(self::DURATIONS[$duration]) || $issue < 1 || $now < 1) {
            throw new InvalidArgumentException('Recordatorio inválido.');
        }
        $owner->assertFresh($now);

        $matches = array_values(array_filter(
            $decisions,
            static fn (mixed $decision): bool => is_array($decision)
                && ($decision['repository'] ?? null) === $repository
                && ($decision['issue'] ?? null) === $issue,
        ));
        if (count($matches) !== 1) {
            throw new RuntimeException('La decisión no está disponible para posponer.');
        }

        $decision = $matches[0];
        $category = $decision['category'] ?? null;
        if (!is_string($category) || $category === '') {
            throw new RuntimeException('Categoría de decisión inválida.');
        }

        $until = $now + self::DURATIONS[$duration];
        $this->audit->record([
            'actor' => $owner->login,
            'action' => 'snooze',
            'repository' => $repository,
            'issue' => $issue,
            'category' => $category,
            'option' => 'S',
            'sha' => null,
            'result' => 'success',
            'evidence' => null,
            'at' => $now,
            'snoozed_until' => $until,
        ]);

        return [
            'repository' => $repository,
            'issue' => $issue,
            'duration' => $duration,
            'snoozed_until' => $until,
        ];
    }

    public function visible(array $decisions, int $now): array
    {
        if ($now < 1) {
            throw new InvalidArgumentException('Tiempo de recordatorio inválido.');
        }

        $latest = [];
        foreach ($this->audit->entries() as $entry) {
            if (($entry['action'] ?? null) !== 'snooze') {
                continue;
            }
            $repository = $entry['repository'] ?? null;
            $issue = $entry['issue'] ?? null;
            $at = $entry['at'] ?? null;
            $until = $entry['snoozed_until'] ?? null;
            if (
                !is_string($repository)
                || !is_int($issue) || $issue < 1
                || !is_int($at) || $at < 1
                || !is_int($until) || $until <= $at
                || ($entry['option'] ?? null) !== 'S'
                || ($entry['result'] ?? null) !== 'success'
            ) {
                throw new RuntimeException('Entrada de recordatorio inválida.');
            }

            $key = $repository . '#' . $issue;
            if (!isset($latest[$key]) || $at > $latest[$key]['at']) {
                $latest[$key] = ['at' => $at, 'until' => $until];
            }
        }

        return array_values(array_filter(
            $decisions,
            static function (mixed $decision) use ($latest, $now): bool {
                if (
                    !is_array($decision)
                    || !is_string($decision['repository'] ?? null)
                    || !is_int($decision['issue'] ?? null)
                ) {
                    throw new RuntimeException('Decisión de inbox inválida.');
                }
                $key = $decision['repository'] . '#' . $decision['issue'];
                return !isset($latest[$key]) || $latest[$key]['until'] <= $now;
            },
        ));
    }
}
