<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/ReadinessEvidenceLedger.php';

use ControlBot\Readiness\ReadinessEvidenceLedger;

$now = 1800000000;
$record = static fn (string $area): array => [
    'area' => $area,
    'source_ref' => 'https://github.com/pl0n3r/ControlBot/actions/runs/123',
    'revision' => str_repeat('a', 40),
    'observed_at' => $now - 40,
    'valid_for_seconds' => 300,
    'outcome' => 'pass',
];
$input = match ($argv[1] ?? '') {
    'separate' => [$record('smoke'), $record('deploy'), $record('health')],
    'stale' => [array_replace($record('smoke'), ['observed_at' => $now - 301])],
    'unknown' => [[
        'area' => 'smoke', 'source_ref' => null, 'revision' => null,
        'observed_at' => null, 'valid_for_seconds' => null, 'outcome' => null,
    ]],
    'revision' => [array_replace($record('deploy'), ['revision' => 'v1.2.3'])],
    'secret' => [array_replace($record('health'), ['source_ref' => 'https://github.com/pl0n3r/ControlBot/issues/42?token=SECRET_VALUE'])],
    'pii' => [array_replace($record('health'), ['revision' => 'alice@example.com'])],
    'duplicate' => [$record('health'), $record('health')],
    'invalid' => [array_replace($record('deploy'), ['other' => 'SECRET_VALUE'])],
    default => throw new InvalidArgumentException('Unsupported fixture.'),
};
try {
    echo json_encode(ReadinessEvidenceLedger::project($input, $now), JSON_THROW_ON_ERROR), PHP_EOL;
} catch (InvalidArgumentException $exception) {
    echo 'blocked', PHP_EOL;
}
