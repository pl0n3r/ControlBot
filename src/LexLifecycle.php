<?php
declare(strict_types=1);

namespace ControlBot\Legal;

use InvalidArgumentException;

final class LexLifecycle
{
    private const LEGAL_STATES = ['compliant', 'gap', 'unknown', 'not_applicable'];
    private const STAGES = ['construction', 'live'];
    private const REVIEWS = ['pending', 'approved', 'rejected'];
    private const SENSITIVE = '/(?:password|passwd|secret|token|cookie|authorization|bearer|private[_ -]?key|api[_ -]?key|credential)/i';

    public static function project(array $input): array
    {
        self::fields($input, [
            'version', 'scope', 'stage', 'legal_state', 'material_risk',
            'reversible_work', 'real_data_requested', 'human_review',
        ], 'lex lifecycle');
        if (($input['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('lex lifecycle version invalid.');
        }

        $scope = self::scope($input['scope'] ?? null);
        $stage = self::enum($input['stage'] ?? null, self::STAGES, 'stage');
        $legalState = self::enum($input['legal_state'] ?? null, self::LEGAL_STATES, 'legal_state');
        $materialRisk = self::boolean($input['material_risk'] ?? null, 'material_risk');
        $reversible = self::boolean($input['reversible_work'] ?? null, 'reversible_work');
        $realData = self::boolean($input['real_data_requested'] ?? null, 'real_data_requested');
        $review = self::review($input['human_review'] ?? null);

        $reviewApproved = $review['state'] === 'approved' && $review['evidence_refs'] !== [];
        $legallyCompatible = in_array($legalState, ['compliant', 'not_applicable'], true);
        $constructionAllowed = $reversible && !$materialRisk && $review['state'] !== 'rejected';

        $constructionStatus = $reviewApproved && $legallyCompatible
            ? 'legally_reviewed'
            : ($materialRisk || $review['state'] === 'rejected'
                ? 'blocked_legal_risk'
                : 'documented_not_legally_approved');

        $reasons = [];
        if (!$legallyCompatible) $reasons[] = 'legal_state_' . $legalState;
        if (!$reviewApproved) $reasons[] = 'human_review_not_approved';
        if ($materialRisk) $reasons[] = 'material_legal_risk';
        if ($review['state'] === 'rejected') $reasons[] = 'human_review_rejected';
        sort($reasons, SORT_STRING);

        $livePass = $legallyCompatible && $reviewApproved && !$materialRisk;
        $liveRequired = $stage === 'live' || $realData;
        $transitionAllowed = $liveRequired ? $livePass : $constructionAllowed;

        return [
            'version' => 1,
            'scope' => $scope,
            'stage' => $stage,
            'legal_state' => $legalState,
            'construction_legal_status' => $constructionStatus,
            'construction_work_allowed' => $constructionAllowed,
            'live_legal_gate' => [
                'state' => $livePass ? 'pass' : 'blocked',
                'required_now' => $liveRequired,
                'human_review_required' => !$livePass,
                'reasons' => $reasons,
                'evidence_refs' => $review['evidence_refs'],
            ],
            'transition_allowed' => $transitionAllowed,
            'authority_effect' => 'none',
            'auto_execute' => false,
        ];
    }

    private static function review(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('human_review invalid.');
        }
        self::fields($value, ['state', 'evidence_refs'], 'human_review');
        $state = self::enum($value['state'] ?? null, self::REVIEWS, 'human_review state');
        $refs = self::refs($value['evidence_refs'] ?? null);
        if ($state === 'approved' && $refs === []) {
            throw new InvalidArgumentException('approved review requires evidence.');
        }
        return ['state' => $state, 'evidence_refs' => $refs];
    }

    private static function refs(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 20) {
            throw new InvalidArgumentException('evidence_refs invalid.');
        }
        $out = [];
        foreach ($value as $ref) {
            if (!is_string($ref) || strlen($ref) < 8 || strlen($ref) > 220
                || preg_match(self::SENSITIVE, $ref) === 1
                || preg_match('#^controlbot:[A-Za-z0-9][A-Za-z0-9._:/\\#-]+$#D', $ref) !== 1) {
                throw new InvalidArgumentException('evidence_ref invalid.');
            }
            $out[$ref] = true;
        }
        $refs = array_keys($out);
        sort($refs, SORT_STRING);
        return $refs;
    }

    private static function scope(mixed $value): string
    {
        if (!is_string($value)
            || preg_match('/^(?:group|venture|project|institution):[a-z][a-z0-9-]{1,63}$/D', $value) !== 1) {
            throw new InvalidArgumentException('scope invalid.');
        }
        return $value;
    }

    private static function enum(mixed $value, array $allowed, string $label): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function boolean(mixed $value, string $label): bool
    {
        if (!is_bool($value)) throw new InvalidArgumentException($label . ' invalid.');
        return $value;
    }

    private static function fields(array $row, array $expected, string $label): void
    {
        $actual = array_keys($row);
        sort($actual, SORT_STRING); sort($expected, SORT_STRING);
        if ($actual !== $expected) throw new InvalidArgumentException($label . ' fields invalid.');
    }
}
