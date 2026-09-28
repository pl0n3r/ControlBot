<?php
declare(strict_types=1);

require __DIR__ . '/../src/LexWatch.php';

use ControlBot\Legal\LexWatch;

function watchSignal(string $id, array $overrides = []): array
{
    return array_replace([
        'signal_id' => $id,
        'jurisdiction' => 'CO',
        'regulation_ref' => 'controlbot:lex/regulation/example',
        'change_ref' => 'controlbot:lex/change/example-v1',
        'source_state' => 'verified',
        'source_refs' => ['controlbot:lex/source/example'],
        'evidence_refs' => ['controlbot:lex/evidence/example'],
        'impact_state' => 'plausible',
        'impact_refs' => ['controlbot:venture/condor'],
        'published_at' => 1000,
        'effective_at' => 1100,
        'observed_at' => 1050,
        'freshness' => 'fresh',
    ], $overrides);
}

function watchInput(array $signals): array
{
    return ['version' => 1, 'signals' => $signals];
}

function watchRejected(callable $call): string
{
    try {
        $call();
        return 'accepted';
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

$name = $argv[1] ?? '';
$now = 1200;

if ($name === 'publication') {
    $out = LexWatch::normalize(watchInput([
        watchSignal('signal-effective'),
        watchSignal('signal-future', [
            'change_ref' => 'controlbot:lex/change/example-v2',
            'effective_at' => 1400,
        ]),
    ]), $now);
} elseif ($name === 'fail_closed') {
    $out = LexWatch::normalize(watchInput([
        watchSignal('signal-rumor', [
            'change_ref' => 'controlbot:lex/change/rumor',
            'source_state' => 'rumor',
            'evidence_refs' => [],
            'published_at' => null,
            'effective_at' => null,
        ]),
        watchSignal('signal-unknown', [
            'change_ref' => 'controlbot:lex/change/unknown',
            'source_state' => 'unknown',
            'evidence_refs' => [],
            'published_at' => null,
            'effective_at' => null,
        ]),
        watchSignal('signal-stale', [
            'change_ref' => 'controlbot:lex/change/stale',
            'freshness' => 'stale',
        ]),
    ]), $now);
    $out['future_observed'] = watchRejected(
        fn() => LexWatch::normalize(watchInput([
            watchSignal('signal-future-observed', ['observed_at' => 1300]),
        ]), $now)
    );
} elseif ($name === 'dedupe') {
    $first = watchSignal('signal-b', [
        'source_refs' => ['controlbot:lex/source/b'],
        'evidence_refs' => ['controlbot:lex/evidence/b'],
        'observed_at' => 1060,
    ]);
    $second = watchSignal('signal-a', [
        'source_refs' => ['controlbot:lex/source/a'],
        'evidence_refs' => ['controlbot:lex/evidence/a'],
        'observed_at' => 1070,
    ]);
    $firstResult = LexWatch::normalize(watchInput([$first, $second]), $now);
    $secondResult = LexWatch::normalize(watchInput([$second, $first]), $now);
    $out = [
        'first' => $firstResult,
        'second' => $secondResult,
        'same' => $firstResult === $secondResult,
    ];
} elseif ($name === 'impact') {
    $out = LexWatch::normalize(watchInput([
        watchSignal('signal-traceable'),
        watchSignal('signal-none', [
            'change_ref' => 'controlbot:lex/change/no-impact',
            'impact_state' => 'none',
            'impact_refs' => [],
        ]),
        watchSignal('signal-unknown-impact', [
            'change_ref' => 'controlbot:lex/change/unknown-impact',
            'impact_state' => 'unknown',
            'impact_refs' => ['controlbot:venture/condor'],
        ]),
    ]), $now);
    $out['sensitive'] = watchRejected(
        fn() => LexWatch::normalize(watchInput([
            watchSignal('signal-sensitive', [
                'source_refs' => ['controlbot:lex/source/api-token-value'],
            ]),
        ]), $now)
    );
} else {
    fwrite(STDERR, "scenario invalid\n");
    exit(2);
}

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
