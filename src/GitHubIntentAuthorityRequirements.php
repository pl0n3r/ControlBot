<?php
declare(strict_types=1);
namespace ControlBot\GitHub;
require_once __DIR__ . '/GitHubIntentEnvelope.php';

/** Offline-only requirements: never issue grants, approvals or execute adapters. */
final class GitHubIntentAuthorityRequirements
{
    private const CAPABILITIES = [
        'issue.create'=>'github.issue.write',
        'issue.update'=>'github.issue.write',
        'issue.close'=>'github.issue.write',
        'issue.reserve'=>'github.issue.write',
        'issue.release'=>'github.issue.write',
        'pr.review'=>'github.pr.review',
        'pr.merge'=>'github.pr.merge',
        'workflow.dispatch'=>'github.workflow.dispatch',
        'release.approve'=>'github.release.approve',
        'project.freeze'=>'github.project.write',
        'project.unfreeze'=>'github.project.write',
    ];

    public static function describe(array $rawEnvelope, string $expectedProjectRef, string $expectedRepositoryRef): array
    {
        try {
            // Caller-provided execution flags are not evidence of authority.
            if (array_key_exists('execution', $rawEnvelope)) return self::unknown('invalid_intent');
            $intent = GitHubIntentEnvelope::envelope($rawEnvelope);
            if ($intent['project_ref'] !== $expectedProjectRef || $intent['repository_ref'] !== $expectedRepositoryRef)
                return self::unknown('scope_mismatch');
            [$owner,$name] = explode('/', $expectedRepositoryRef, 2);
            if (in_array($owner,['.','..'],true) || in_array($name,['.','..'],true))
                return self::unknown('invalid_scope');
            $type = $intent['type'];
            if (!isset(self::CAPABILITIES[$type])) return self::unknown('unknown_intent');
            $scope = in_array($type,['project.freeze','project.unfreeze'],true) ? 'project' : 'repository';
            $scopeRef = $scope === 'project' ? $expectedProjectRef : $expectedRepositoryRef;
            return [
                'version'=>1, 'state'=>'requirements', 'type'=>$type,
                'capability'=>self::CAPABILITIES[$type],
                'scope'=>$scope, 'scope_ref'=>$scopeRef,
                'scope_parameters'=>[$scope.'_ref'=>$scopeRef],
                'approval_class'=>'owner_required',
                'authorized'=>false, 'grant_issued'=>false,
                'approval_issued'=>false, 'approval_verified'=>false, 'execution'=>false,
            ];
        } catch (\Throwable) {
            return self::unknown('invalid_intent_or_scope');
        }
    }

    private static function unknown(string $reason): array
    {
        return [
            'version'=>1,'state'=>'unknown','reason'=>$reason,
            'authorized'=>false,'grant_issued'=>false,
            'approval_issued'=>false,'approval_verified'=>false,'execution'=>false,
        ];
    }
}
