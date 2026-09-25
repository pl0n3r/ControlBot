<?php
declare(strict_types=1);

namespace ControlBot\Decisions;

use Throwable;

final class DecisionBatch
{
    private const EXCLUDED_CATEGORIES = ['money', 'legal', 'go-live', 'factory-release'];

    public static function eligible(array $decisions): array
    {
        $eligible = [];
        foreach ($decisions as $decision) {
            if (
                !is_array($decision)
                || !is_string($decision['repository'] ?? null)
                || !is_int($decision['issue'] ?? null)
                || ($decision['issue'] ?? 0) < 1
                || !is_string($decision['title'] ?? null)
                || !is_string($decision['category'] ?? null)
                || in_array($decision['category'], self::EXCLUDED_CATEGORIES, true)
                || !is_string($decision['recommendation'] ?? null)
                || !is_array($decision['options'] ?? null)
            ) {
                continue;
            }

            $recommended = null;
            foreach ($decision['options'] as $option) {
                if (
                    is_array($option)
                    && ($option['id'] ?? null) === $decision['recommendation']
                    && ($option['risk'] ?? null) === 'low'
                ) {
                    $recommended = $option;
                    break;
                }
            }
            if ($recommended === null || !is_string($recommended['id'] ?? null)) {
                continue;
            }

            $title = isset($decision['title_simple']) && is_string($decision['title_simple'])
                && trim($decision['title_simple']) !== ''
                ? trim($decision['title_simple'])
                : $decision['title'];

            $eligible[] = [
                'repository' => $decision['repository'],
                'issue' => $decision['issue'],
                'category' => $decision['category'],
                'option' => $recommended['id'],
                'displayed_sha' => is_string($decision['sha'] ?? null) ? $decision['sha'] : '',
                'title' => $title,
            ];
        }
        return $eligible;
    }

    public static function execute(array $decisions, callable $approve): array
    {
        $eligible = self::eligible($decisions);
        if ($eligible === []) {
            return ['state' => 'empty', 'completed' => [], 'failed' => null];
        }

        $completed = [];
        foreach ($eligible as $entry) {
            try {
                $result = $approve($entry);
                if (!is_array($result)) {
                    throw new \RuntimeException('Resultado de aprobación inválido.');
                }
            } catch (Throwable) {
                return [
                    'state' => 'blocked',
                    'completed' => $completed,
                    'failed' => [
                        'repository' => $entry['repository'],
                        'issue' => $entry['issue'],
                        'option' => $entry['option'],
                        'error' => 'approval-failed',
                    ],
                ];
            }

            $completed[] = [
                'repository' => $entry['repository'],
                'issue' => $entry['issue'],
                'option' => $entry['option'],
                'result' => $result,
            ];
        }

        return ['state' => 'success', 'completed' => $completed, 'failed' => null];
    }
}
