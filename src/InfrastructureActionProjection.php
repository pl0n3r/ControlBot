<?php
declare(strict_types=1);

namespace ControlBot\Infrastructure;

use InvalidArgumentException;

final class InfrastructureActionProjection
{
    private const STATUSES = ['planned', 'owner_decision_required', 'denied'];

    public static function project(
        array $intentRaw,
        mixed $authorityProjection,
        ?array $capitalInput,
        int $now,
    ): array {
        if (func_num_args() !== 4) {
            throw new InvalidArgumentException('Infrastructure action projection input invalid.');
        }
        return self::fromGovernedPlan(
            InfrastructureIntent::plan($intentRaw, $authorityProjection, $capitalInput, $now),
        );
    }

    private static function fromGovernedPlan(array $plan): array
    {
        $status = $plan['status'] ?? null;
        if (($plan['version'] ?? null) !== 1
            || ($plan['execution'] ?? null) !== false
            || !is_string($status)
            || !in_array($status, self::STATUSES, true)
            || !is_array($plan['intent'] ?? null)
            || !is_array($plan['authority'] ?? null)
            || !is_array($plan['reasons'] ?? null)
        ) {
            throw new InvalidArgumentException('Governed infrastructure plan invalid.');
        }

        $intentId = $plan['intent']['intent_id'] ?? null;
        $scope = $plan['intent']['scope'] ?? null;
        $authorityDecision = $plan['authority']['decision'] ?? null;
        if (!is_string($intentId) || $intentId === ''
            || !is_string($scope) || $scope === ''
            || !is_string($authorityDecision) || $authorityDecision === ''
            || !array_is_list($plan['reasons'])
            || !array_reduce(
                $plan['reasons'],
                static fn(bool $ok, mixed $reason): bool =>
                    $ok && is_string($reason) && $reason !== '',
                true,
            )
        ) {
            throw new InvalidArgumentException('Governed infrastructure provenance invalid.');
        }

        $workItem = $plan['work_item'] ?? null;
        $runnerRequest = $plan['runner_request'] ?? null;
        $ownerGate = $plan['owner_decision_gate'] ?? null;
        $workItemId = null;
        $approvalRef = null;

        if ($status === 'denied') {
            if ($workItem !== null || $runnerRequest !== null || $ownerGate !== null) {
                throw new InvalidArgumentException('Denied infrastructure action is executable.');
            }
        } elseif (!is_array($workItem) || !is_string($workItem['work_id'] ?? null)) {
            throw new InvalidArgumentException('Governed infrastructure work item invalid.');
        } else {
            $workItemId = $workItem['work_id'];
            if ($status === 'planned') {
                if (!is_array($runnerRequest) || $ownerGate !== null) {
                    throw new InvalidArgumentException('Planned infrastructure action invalid.');
                }
            } else {
                $approvalRef = $workItem['approval_ref'] ?? null;
                if ($runnerRequest !== null
                    || !is_string($approvalRef) || $approvalRef === ''
                    || !is_string($ownerGate) || $ownerGate === ''
                ) {
                    throw new InvalidArgumentException('Owner-gated infrastructure action invalid.');
                }
            }
        }

        return [
            'version' => 1,
            'action_ref' => 'controlbot:infrastructure-action/' . $intentId,
            'intent_ref' => 'controlbot:infrastructure-intent/' . $intentId,
            'source_ref' => 'controlbot:infrastructure-intent/' . $intentId,
            'scope' => $scope,
            'status' => $status,
            'authority_decision' => $authorityDecision,
            'reasons' => $plan['reasons'],
            'work_item_id' => $workItemId,
            'approval_ref' => $approvalRef,
            'runner_request_ready' => is_array($runnerRequest),
            'execution' => false,
        ];
    }
}
