<?php
declare(strict_types=1);

foreach ([
    'BudgetGuard', 'VentureIdentity', 'DecisionRights', 'CapitalPolicy',
    'CapabilityPolicy', 'RunnerGateway', 'InfrastructureIntent',
    'InfrastructureActionProjection',
] as $file) {
    require __DIR__ . '/../src/' . $file . '.php';
}

use ControlBot\Infrastructure\InfrastructureActionProjection;
use InvalidArgumentException;

const NOW = 2050;

function rejected205(callable $fn): bool
{
    try {
        $fn();
        return false;
    } catch (InvalidArgumentException) {
        return true;
    }
}

function intent205(array $replace = []): array
{
    $base = json_decode(
        <<<'JSON'
{"version":1,"intent_id":"intent-205","origin_mode":"directed","group_id":"pl0n3r","venture_id":"platform","project_id":"controlbot","repository_ref":"pl0n3r/ControlBot","intent_type":"read","priority":"high","scope":"project:controlbot","blast_radius":"low","capability":"hostinger.read","depends_on":["pl0n3r/ControlBot#190"],"claims":["infra:controlbot-main"],"policy_ref":"controlbot:policy/infrastructure-v1","evidence_refs":["pl0n3r/ControlBot#205"],"idempotency_key":"infra-action-205","authority_level":"l2_venture_admin","budget_ref":null,"approval_ref":null,"instruction_ref":"controlbot:infrastructure/intent-205","cost_applicable":false,"cost_ref":null,"evidence":{"plan_ref":null,"impact_ref":null,"rollback_ref":null,"safe_point_ref":null,"verify_ref":null,"irreversible":false}}
JSON,
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    return array_replace($base, $replace);
}

function authority205(string $decision = 'allow'): array
{
    return [
        'decision' => $decision,
        'reason_code' => match ($decision) {
            'allow' => 'venture_access_allow',
            'deny' => 'venture_access_deny',
            default => 'venture_access_owner_required',
        },
        'scope' => 'project:controlbot',
        'evidence_ref' => 'controlbot:venture-access/issue-205',
        'policy_restrictions' => [],
    ];
}

$planned = InfrastructureActionProjection::project(
    intent205(),
    authority205(),
    null,
    NOW,
);
$owner = InfrastructureActionProjection::project(
    intent205(['intent_id' => 'intent-owner', 'blast_radius' => 'high']),
    authority205(),
    null,
    NOW,
);
$denied = InfrastructureActionProjection::project(
    intent205(['intent_id' => 'intent-denied']),
    authority205('deny'),
    null,
    NOW,
);

$fakePlan = [
    'version' => 1,
    'status' => 'planned',
    'execution' => false,
    'authority' => ['decision' => 'allow'],
];

$secret = intent205([
    'intent_id' => 'intent-secret',
    'instruction_ref' => 'controlbot:token:supersecret',
]);

$out = [
    'planned' => $planned,
    'owner' => $owner,
    'denied' => $denied,
    'fake_plan_rejected' => rejected205(
        fn() => InfrastructureActionProjection::project(
            $fakePlan,
            authority205(),
            null,
            NOW,
        )
    ),
    'caller_status_rejected' => rejected205(
        fn() => InfrastructureActionProjection::project(
            intent205(),
            authority205(),
            null,
            NOW,
            ['status' => 'planned', 'authority_decision' => 'allow'],
        )
    ),
    'secret_rejected' => rejected205(
        fn() => InfrastructureActionProjection::project(
            $secret,
            authority205(),
            null,
            NOW,
        )
    ),
];

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
