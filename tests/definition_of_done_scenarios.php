<?php
declare(strict_types=1);
require __DIR__ . '/../src/DefinitionOfDoneTracker.php';

use ControlBot\Readiness\DefinitionOfDoneTracker;

const NOW = 2000;

function row(string $id, string $outcome = 'pass', ?int $at = 1900): array
{
    return [
        'id' => $id,
        'outcome' => $outcome,
        'evidence_ref' => $outcome === 'unknown' ? null : 'synthetic:cb750/' . $id,
        'observed_at' => $outcome === 'unknown' ? null : $at,
    ];
}

function envelope(array $rows): array
{
    return ['version' => 1, 'source' => 'fixture', 'criteria' => $rows];
}

function allRows(): array
{
    $ids = array_merge(...array_values(DefinitionOfDoneTracker::criteria()));
    return array_map(static fn (string $id): array => row($id), $ids);
}

function evaluate(array $payload, int $now = NOW): array
{
    return DefinitionOfDoneTracker::evaluate($payload, $now);
}

$scenario = $argv[1] ?? '';
$complete = allRows();
$first = $complete[0];

switch ($scenario) {
    case 'main':
        $failed = $complete;
        $failed[0] = row($first['id'], 'fail');
        $unknown = $complete;
        $unknown[0] = row($first['id'], 'unknown');
        $output = [
            'ids' => DefinitionOfDoneTracker::criteria(),
            'all_pass' => evaluate(envelope($complete)),
            'failed' => evaluate(envelope($failed)),
            'missing' => evaluate(envelope([])),
            'unknown' => evaluate(envelope($unknown)),
        ];
        break;
    case 'invalid':
        $duplicate = [$first, $first];
        $extraneous = envelope([$first]);
        $extraneous['token'] = 'secret-example';
        $badRow = $first;
        $badRow['email'] = 'person@example.com';
        $wrongId = row('unknown_requirement');
        $spoof = $first;
        $spoof['evidence_ref'] = 'https://example.com/real';
        $output = [
            'duplicate' => evaluate(envelope($duplicate)),
            'extra_field' => evaluate($extraneous),
            'personal_field' => evaluate(envelope([$badRow])),
            'unknown_id' => evaluate(envelope([$wrongId])),
            'wrong_ref' => evaluate(envelope([$spoof])),
            'bad_version' => evaluate(['version' => 2, 'source' => 'fixture', 'criteria' => []]),
            'bad_source' => evaluate(['version' => 1, 'source' => 'github', 'criteria' => []]),
            'invalid_time' => evaluate(envelope([$first]), -1),
        ];
        break;
    case 'freshness':
        $stale = row($first['id'], 'pass', 1600);
        $future = row($first['id'], 'pass', 2001);
        $atBoundary = row($first['id'], 'pass', 1700);
        $missing = row($first['id'], 'unknown');
        $invalid = $first;
        $invalid['observed_at'] = '1900';
        $output = [
            'stale' => evaluate(envelope([$stale])),
            'future' => evaluate(envelope([$future])),
            'boundary' => evaluate(envelope([$atBoundary])),
            'unknown' => evaluate(envelope([$missing])),
            'type_confusion' => evaluate(envelope([$invalid])),
        ];
        break;
    default:
        fwrite(STDERR, "Unknown synthetic DoD scenario\n");
        exit(2);
}
$encoded = json_encode($output, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
echo $encoded;
