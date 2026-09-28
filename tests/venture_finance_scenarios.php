<?php
declare(strict_types=1);

require __DIR__ . '/../src/VentureFinancialSnapshot.php';

use ControlBot\Business\VentureFinancialSnapshot;

function baseSnapshot(array $replace = []): array
{
    return array_replace_recursive([
        'version' => 1,
        'venture_id' => 'condor',
        'period' => '2026-09',
        'currency' => 'COP',
        'revenue' => 10_000_000,
        'refunds' => 500_000,
        'direct_costs' => 2_000_000,
        'operating_costs' => 1_500_000,
        'infrastructure_costs' => 300_000,
        'ai_costs' => 200_000,
        'cash_in' => 9_500_000,
        'cash_out' => 4_000_000,
        'customers' => 120,
        'transactions' => 180,
        'revenue_streams' => [
            ['stream_id' => 'addons', 'revenue' => 2_000_000, 'refunds' => 100_000, 'customers' => 30, 'transactions' => 40],
            ['stream_id' => 'subscriptions', 'revenue' => 8_000_000, 'refunds' => 400_000, 'customers' => 90, 'transactions' => 140],
        ],
        'source_ref' => 'venture:condor:finance:2026-09',
        'observed_at' => '2026-09-28T12:00:00Z',
        'freshness' => 'fresh',
        'confidence' => 'verified',
    ], $replace);
}

$name = $argv[1] ?? '';

if ($name === 'base') {
    $out = VentureFinancialSnapshot::normalize(baseSnapshot());
} elseif ($name === 'derived') {
    $out = VentureFinancialSnapshot::normalize(baseSnapshot());
    try {
        VentureFinancialSnapshot::normalize(baseSnapshot(['net_revenue' => 123]));
        $out['client_derived_rejected'] = 'accepted';
    } catch (Throwable $e) {
        $out['client_derived_rejected'] = $e->getMessage();
    }
} elseif ($name === 'streams') {
    $out = [];
    $cases = [
        'duplicate' => ['revenue_streams' => [
            ['stream_id' => 'subscriptions', 'revenue' => 8_000_000, 'refunds' => 400_000, 'customers' => 90, 'transactions' => 140],
            ['stream_id' => 'subscriptions', 'revenue' => 2_000_000, 'refunds' => 100_000, 'customers' => 30, 'transactions' => 40],
        ]],
        'pii' => ['revenue_streams' => [
            ['stream_id' => 'customer_id', 'revenue' => 10_000_000, 'refunds' => 500_000, 'customers' => 120, 'transactions' => 180],
        ]],
        'extra_ref' => ['revenue_streams' => [
            ['stream_id' => 'subscriptions', 'revenue' => 10_000_000, 'refunds' => 500_000, 'customers' => 120, 'transactions' => 180, 'order_ref' => 'o-1'],
        ]],
    ];
    foreach ($cases as $key => $replace) {
        try {
            VentureFinancialSnapshot::normalize(baseSnapshot($replace));
            $out[$key] = 'accepted';
        } catch (Throwable $e) {
            $out[$key] = $e->getMessage();
        }
    }
} elseif ($name === 'freshness') {
    $out = [];
    foreach ([
        'stale_verified' => ['freshness' => 'stale', 'confidence' => 'verified'],
        'unknown_verified' => ['freshness' => 'unknown', 'confidence' => 'verified'],
        'stale_unknown' => ['freshness' => 'stale', 'confidence' => 'unknown'],
    ] as $key => $replace) {
        try {
            $out[$key] = VentureFinancialSnapshot::normalize(baseSnapshot($replace));
        } catch (Throwable $e) {
            $out[$key] = $e->getMessage();
        }
    }
} elseif ($name === 'provenance') {
    $out = [];
    foreach ([
        'bad_source' => ['source_ref' => 'token:supersecretvalue'],
        'bad_time' => ['observed_at' => '2026-99-99T99:99:99Z'],
    ] as $key => $replace) {
        try {
            VentureFinancialSnapshot::normalize(baseSnapshot($replace));
            $out[$key] = 'accepted';
        } catch (Throwable $e) {
            $out[$key] = $e->getMessage();
        }
    }
} elseif ($name === 'invalid') {
    $out = [];
    foreach ([
        'float_money' => ['revenue' => 10.5],
        'negative_money' => ['cash_out' => -1],
        'bad_currency' => ['currency' => 'ZZZ'],
        'bad_period' => ['period' => '2026-13'],
        'extra' => ['customer_list' => ['personal-record']],
    ] as $key => $replace) {
        try {
            VentureFinancialSnapshot::normalize(baseSnapshot($replace));
            $out[$key] = 'accepted';
        } catch (Throwable $e) {
            $out[$key] = $e->getMessage();
        }
    }
} elseif ($name === 'deterministic') {
    $a = baseSnapshot();
    $a['revenue_streams'] = array_reverse($a['revenue_streams']);
    $out = [
        'a' => VentureFinancialSnapshot::normalize($a),
        'b' => VentureFinancialSnapshot::normalize(baseSnapshot()),
    ];
} else {
    fwrite(STDERR, "scenario inválido\n");
    exit(2);
}

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
