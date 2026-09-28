<?php
declare(strict_types=1);

require __DIR__ . '/../src/LexJurisdiction.php';

use ControlBot\Legal\LexJurisdiction;

function coPack(): array
{
    $raw = file_get_contents(__DIR__ . '/../config/lex/jurisdictions/CO.json');
    if ($raw === false) {
        throw new RuntimeException('CO fixture missing.');
    }
    return json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
}

function syntheticPack(string $jurisdiction = 'country:MX'): array
{
    return [
        'schema_version' => 1,
        'pack_id' => 'pack-mx-fixture-v1',
        'jurisdiction' => $jurisdiction,
        'kind' => 'country',
        'pack_version' => '1.0.0',
        'state' => 'active',
        'freshness' => 'fresh',
        'reviewed_at' => 1000,
        'expires_at' => 2000,
        'responsible_refs' => ['controlbot:role/legal-privacy'],
        'sources' => [[
            'source_id' => 'fixture-source',
            'authority' => 'Synthetic fixture authority',
            'title' => 'Synthetic fixture source; not legal guidance',
            'uri' => 'https://example.invalid/legal-source',
            'reviewed_at' => 1000,
        ]],
        'controls' => [[
            'control_id' => 'fixture-privacy-control',
            'domain' => 'privacy',
            'requirement_ref' => 'controlbot:lex/requirement/fixture/privacy',
            'source_refs' => ['fixture-source'],
            'human_review_required' => true,
        ]],
        'assumption_refs' => [],
        'exclusion_refs' => [],
        'compatibility' => ['lex_core_contract' => 1, 'deprecated_by' => null],
    ];
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

if ($name === 'co') {
    $out = LexJurisdiction::normalize(coPack(), 1790557200);
} elseif ($name === 'freshness') {
    $stale = coPack();
    $stale['freshness'] = 'stale';
    $unknown = coPack();
    $unknown['freshness'] = 'unknown';
    $expired = coPack();
    $out = [
        'stale' => LexJurisdiction::normalize($stale, 1790557200),
        'unknown' => LexJurisdiction::normalize($unknown, 1790557200),
        'expired' => LexJurisdiction::normalize($expired, 1822089600),
    ];
} elseif ($name === 'generic') {
    $out = LexJurisdiction::normalize(syntheticPack(), 1200);
} elseif ($name === 'invalid') {
    $missing = coPack();
    unset($missing['assumption_refs']);
    $unknownSource = coPack();
    $unknownSource['controls'][0]['source_refs'][] = 'missing-source';
    $future = coPack();
    $future['reviewed_at'] = 1900000000;
    $http = coPack();
    $http['sources'][0]['uri'] = 'http://example.invalid/source';
    $out = [
        'missing_field' => rejected(fn() => LexJurisdiction::normalize($missing, 1790557200)),
        'unknown_source' => rejected(fn() => LexJurisdiction::normalize($unknownSource, 1790557200)),
        'future_review' => rejected(fn() => LexJurisdiction::normalize($future, 1790557200)),
        'non_https_source' => rejected(fn() => LexJurisdiction::normalize($http, 1790557200)),
    ];
} else {
    fwrite(STDERR, "scenario invalid\n");
    exit(2);
}

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
