<?php
declare(strict_types=1);

require __DIR__ . '/../src/FinancialPlanning.php';

use ControlBot\Business\FinancialPlanning;

function series(string $kind, int $amount, array $overrides = []): array
{
    return array_replace([
        'kind' => $kind,
        'venture_id' => 'condor',
        'period' => '2026-09',
        'currency' => 'COP',
        'amount_minor' => $amount,
        'source_ref' => 'controlbot:finance/' . $kind . '-condor',
        'observed_at' => 2000,
        'as_of' => 1900,
        'freshness' => 'fresh',
        'confidence' => $kind === 'actual' ? 'verified' : 'estimated',
    ], $overrides);
}

function basePlanning(): array
{
    return [
        'version' => 1,
        'venture_id' => 'condor',
        'period' => '2026-09',
        'currency' => 'COP',
        'actual' => series('actual', 12_000_000),
        'budget' => series('budget', 10_000_000),
        'target' => series('target', 15_000_000),
        'forecast' => series('forecast', 14_000_000),
        'attributions' => [[
            'attribution_id' => 'revenue-core',
            'kind' => 'revenue',
            'currency' => 'COP',
            'amount_minor' => 8_000_000,
            'target_scope' => 'venture:condor',
            'rule_ref' => 'controlbot:finance/rule-direct',
            'provenance_ref' => 'controlbot:finance/source-rollup',
        ]],
    ];
}

$name = $argv[1] ?? '';

if ($name === 'distinct') {
    $out = FinancialPlanning::compare(basePlanning());
} elseif ($name === 'compatible') {
    $input = basePlanning();
    $incompatible = basePlanning();
    $incompatible['budget']['currency'] = 'USD';
    $incompatible['forecast']['period'] = '2026-10';
    $out = [
        'compatible' => FinancialPlanning::compare($input),
        'incompatible' => FinancialPlanning::compare($incompatible),
    ];
} elseif ($name === 'missing-actual') {
    $input = basePlanning();
    $input['actual'] = null;
    $out = FinancialPlanning::compare($input);
} elseif ($name === 'attribution') {
    $input = basePlanning();
    $input['attributions'][] = [
        'attribution_id' => 'shared-platform',
        'kind' => 'shared_cost',
        'currency' => 'COP',
        'amount_minor' => 1_500_000,
        'target_scope' => 'venture:grindflow',
        'rule_ref' => null,
        'provenance_ref' => null,
    ];
    $out = FinancialPlanning::compare($input);
} elseif ($name === 'evidence') {
    $input = basePlanning();
    $input['budget']['source_ref'] = 'controlbot:finance/budget-approved';
    $input['budget']['observed_at'] = 2100;
    $input['budget']['as_of'] = 2050;
    $input['budget']['freshness'] = 'stale';
    $input['budget']['confidence'] = 'estimated';
    $out = FinancialPlanning::compare($input);
} elseif ($name === 'deterministic') {
    $input = basePlanning();
    $unknown = basePlanning();
    $unknown['forecast']['freshness'] = 'unknown';
    $unknown['forecast']['confidence'] = 'unknown';

    $first = FinancialPlanning::compare($input);
    $second = FinancialPlanning::compare($input);
    $out = [
        'first' => $first,
        'second' => $second,
        'same' => $first === $second,
        'unknown' => FinancialPlanning::compare($unknown),
    ];
} else {
    fwrite(STDERR, "scenario inválido\n");
    exit(2);
}

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
