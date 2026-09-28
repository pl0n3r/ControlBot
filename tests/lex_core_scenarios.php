<?php
declare(strict_types=1);

require __DIR__ . '/../src/LexCore.php';

use ControlBot\Legal\LexCore;

function obligation(string $id, array $overrides = []): array
{
    return array_replace([
        'obligation_id' => $id,
        'scope' => 'venture:condor',
        'market_id' => null,
        'jurisdiction_pack_id' => null,
        'domain' => 'privacy',
        'requirement_ref' => 'controlbot:lex/requirement/privacy-notice',
        'reported_status' => 'compliant',
        'source_refs' => ['controlbot:lex/source/privacy-policy'],
        'evidence_refs' => ['controlbot:lex/evidence/privacy-notice-v1'],
        'observed_at' => 1000,
        'freshness' => 'fresh',
        'responsible_ref' => 'controlbot:identity/legal-owner',
        'reviewed_at' => 1100,
        'expires_at' => 2000,
        'next_review_at' => 1800,
        'severity' => 'medium',
        'human_review_required' => false,
        'justification_ref' => null,
        'assumption_refs' => [],
    ], $overrides);
}

function registry(array $rows): array
{
    return ['version' => 1, 'scope' => 'venture:condor', 'obligations' => $rows];
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
$now = 1200;

if ($name === 'states') {
    $rows = [
        obligation('ob-compliant'),
        obligation('ob-gap', ['reported_status' => 'gap', 'evidence_refs' => []]),
        obligation('ob-unknown', ['reported_status' => 'unknown', 'evidence_refs' => []]),
        obligation('ob-na', [
            'reported_status' => 'not_applicable',
            'evidence_refs' => [],
            'justification_ref' => 'controlbot:lex/justification/not-applicable',
        ]),
    ];
    $out = LexCore::registry(registry($rows), $now);
} elseif ($name === 'freshness') {
    $rows = [
        obligation('ob-stale', ['freshness' => 'stale']),
        obligation('ob-unknown-freshness', ['freshness' => 'unknown']),
        obligation('ob-expired', ['expires_at' => 1150]),
    ];
    $out = LexCore::registry(registry($rows), $now);
} elseif ($name === 'registry') {
    $out = LexCore::registry(registry([
        obligation('ob-registry', [
            'market_id' => 'market-co',
            'jurisdiction_pack_id' => 'pack-co-v1',
            'source_refs' => ['controlbot:lex/source/b', 'controlbot:lex/source/a'],
            'evidence_refs' => ['controlbot:lex/evidence/b', 'controlbot:lex/evidence/a'],
            'assumption_refs' => ['controlbot:lex/assumption/b', 'controlbot:lex/assumption/a'],
            'human_review_required' => true,
        ]),
    ]), $now);
} elseif ($name === 'invalid') {
    $extra = registry([obligation('ob-extra')]);
    $extra['obligations'][0]['country'] = 'CO';

    $sensitive = registry([obligation('ob-sensitive', [
        'evidence_refs' => ['controlbot:lex/evidence/api-token-value'],
    ])]);

    $noEvidence = registry([obligation('ob-no-evidence', ['evidence_refs' => []])]);

    $noJustification = registry([obligation('ob-no-justification', [
        'reported_status' => 'not_applicable',
        'evidence_refs' => [],
    ])]);

    $out = [
        'extra' => rejected(fn() => LexCore::registry($extra, $now)),
        'sensitive' => rejected(fn() => LexCore::registry($sensitive, $now)),
        'compliant_without_evidence' => rejected(fn() => LexCore::registry($noEvidence, $now)),
        'not_applicable_without_justification' => rejected(fn() => LexCore::registry($noJustification, $now)),
    ];
} elseif ($name === 'deterministic') {
    $first = registry([
        obligation('ob-zeta', [
            'source_refs' => ['controlbot:lex/source/z', 'controlbot:lex/source/a'],
            'evidence_refs' => ['controlbot:lex/evidence/z', 'controlbot:lex/evidence/a'],
        ]),
        obligation('ob-alpha', ['reported_status' => 'gap', 'evidence_refs' => []]),
    ]);
    $second = registry(array_reverse($first['obligations']));
    $second['obligations'][0]['source_refs'] = array_reverse($second['obligations'][0]['source_refs']);
    $second['obligations'][0]['evidence_refs'] = array_reverse($second['obligations'][0]['evidence_refs']);
    $out = [
        'first' => LexCore::registry($first, $now),
        'second' => LexCore::registry($second, $now),
    ];
    $out['same'] = $out['first'] === $out['second'];
} else {
    fwrite(STDERR, "scenario invalid\n");
    exit(2);
}

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
