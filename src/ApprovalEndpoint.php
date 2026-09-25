<?php
declare(strict_types=1);

namespace ControlBot\Approvals;

use ControlBot\GitHub\HumanGateSource;
use ControlBot\Security\OwnerSessionService;
use InvalidArgumentException;
use RuntimeException;

final class ApprovalEndpoint
{
    public function __construct(
        private readonly OwnerSessionService $sessions,
        private readonly AppendOnlyAuditLog $audit,
        private readonly \Closure $githubFactory,
    ) {}

    public function execute(array $session, array $request, int $now): array
    {
        $repository = $request['repository'] ?? null;
        $issue = $request['issue'] ?? null;
        $option = $request['option'] ?? null;
        $displayedSha = $request['displayed_sha'] ?? null;

        if (
            !is_string($repository)
            || (!is_int($issue) && !(is_string($issue) && ctype_digit($issue)))
            || !is_string($option)
            || !is_string($displayedSha)
        ) {
            throw new InvalidArgumentException('Solicitud de aprobación inválida.');
        }
        $issueNumber = (int) $issue;
        if ($issueNumber < 1 || preg_match('/^[A-D]$/', $option) !== 1) {
            throw new InvalidArgumentException('Solicitud de aprobación inválida.');
        }

        $owner = $this->sessions->contextFromRequest($session, $request, $now);
        $token = $this->sessions->githubToken($session);
        $components = ($this->githubFactory)($token);
        if (
            !is_array($components)
            || !($components['gateway'] ?? null) instanceof GitHubGateway
            || !($components['source'] ?? null) instanceof HumanGateSource
        ) {
            throw new RuntimeException('Integración GitHub inválida.');
        }

        $gate = $components['source']->load($repository, $issueNumber);
        $service = new OwnerApprovalService($components['gateway'], $this->audit);
        return $service->approve(
            $gate,
            $option,
            $repository,
            $issueNumber,
            $displayedSha !== '' ? $displayedSha : null,
            $owner,
            $now,
        );
    }
}
