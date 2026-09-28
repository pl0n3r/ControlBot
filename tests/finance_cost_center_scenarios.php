<?php
declare(strict_types=1);

require __DIR__ . '/../src/FinanceCostCenter.php';

use ControlBot\Business\FinanceCostCenter;

function entity(string $kind, string $id, string $scope, string $title, array $overrides = []): array
{
    return array_replace([
        'version' => 1,
        'kind' => $kind,
        'id' => $id,
        'scope' => $scope,
        'title' => $title,
        'source_ref' => 'controlbot:finance/entity-' . $id,
        'observed_at' => '2026-09-28T15:30:00Z',
        'freshness' => 'fresh',
    ], $overrides);
}

function rejected(callable $call): string
{
    try {
        $call();
        return 'accepted';
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

$name = $argv[1] ?? '';

if ($name === 'distinct') {
    $out = [
        'venture' => FinanceCostCenter::normalize(
            entity('venture', 'condor', 'venture:condor', 'Condor')
        ),
        'institution' => FinanceCostCenter::normalize(
            entity('institution_cost_center', 'factory', 'institution:factory', 'Factory')
        ),
    ];
} elseif ($name === 'canonical') {
    $out = FinanceCostCenter::canonicalInstitutions();
} elseif ($name === 'crossovers') {
    $out = [
        'institution_as_venture_scope' => rejected(
            fn() => FinanceCostCenter::normalize(
                entity('institution_cost_center', 'factory', 'venture:factory', 'Factory')
            )
        ),
        'reserved_id_as_venture' => rejected(
            fn() => FinanceCostCenter::normalize(
                entity('venture', 'factory', 'venture:factory', 'Factory venture')
            )
        ),
        'venture_as_institution_scope' => rejected(
            fn() => FinanceCostCenter::normalize(
                entity('venture', 'condor', 'institution:condor', 'Condor')
            )
        ),
    ];
} elseif ($name === 'attribution') {
    $target = entity(
        'institution_cost_center',
        'controlbot',
        'institution:controlbot',
        'ControlBot',
        ['source_ref' => 'controlbot:finance/cost-center-controlbot']
    );
    $out = FinanceCostCenter::normalizeAttribution([
        'version' => 1,
        'attribution_id' => 'shared-ai-controlbot',
        'target' => $target,
        'currency' => 'COP',
        'amount_minor' => 1_500_000,
        'provenance_ref' => 'controlbot:finance/source-shared-ai',
        'source_ref' => 'controlbot:finance/attribution-shared-ai',
        'observed_at' => '2026-09-28T15:31:00Z',
        'freshness' => 'fresh',
    ]);
} elseif ($name === 'unknown') {
    $out = [
        'unknown_institution' => rejected(
            fn() => FinanceCostCenter::normalize(
                entity('institution_cost_center', 'billing', 'institution:billing', 'Billing')
            )
        ),
        'wrong_title' => rejected(
            fn() => FinanceCostCenter::normalize(
                entity('institution_cost_center', 'capital', 'institution:capital', 'Capital')
            )
        ),
        'unknown_kind' => rejected(
            fn() => FinanceCostCenter::normalize(
                entity('cost_center', 'factory', 'institution:factory', 'Factory')
            )
        ),
    ];
} elseif ($name === 'deterministic') {
    $rows = [
        entity('venture', 'grindflow', 'venture:grindflow', 'GrindFlow'),
        entity('institution_cost_center', 'capital', 'institution:capital', 'CAPITAL'),
        entity('venture', 'condor', 'venture:condor', 'Condor'),
    ];
    $first = FinanceCostCenter::normalizeList($rows);
    $second = FinanceCostCenter::normalizeList(array_reverse($rows));
    $out = [
        'first' => $first,
        'second' => $second,
        'same' => $first === $second,
    ];
} else {
    fwrite(STDERR, "scenario inválido\n");
    exit(2);
}

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
