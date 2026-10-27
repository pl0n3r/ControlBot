<?php
declare(strict_types=1);

require __DIR__ . '/../src/BackupIntegrityVerifier.php';
use ControlBot\Recovery\BackupIntegrityVerifier;

const SAMPLE = 'fixture-cb182-solo-sintetico';
const CREATED = 1790000000;
const VERIFIED = 1790000060;
const CHECKED = 1790000120;

function fixture(array $overrides = []): array
{
    return array_replace([
        'version' => 1,
        'artifact_ref' => 'artifact:synthetic:cb182-backup',
        'environment' => 'testing',
        'algorithm' => 'sha256',
        'checksum' => hash('sha256', SAMPLE),
        'size_bytes' => strlen(SAMPLE),
        'created_at' => CREATED,
        'verified_at' => VERIFIED,
    ], $overrides);
}
function check(array $changes = [], string $sample = SAMPLE, int $time = CHECKED): array
{
    return BackupIntegrityVerifier::verify(fixture($changes), $sample, $time);
}
function without(string $field): array
{
    $manifest = fixture();
    unset($manifest[$field]);
    return BackupIntegrityVerifier::verify($manifest, SAMPLE, CHECKED);
}

$out = match ($argv[1] ?? '') {
    'principal' => [
        'ok' => check(),
        'uppercase' => check(['checksum' => strtoupper(hash('sha256', SAMPLE))]),
        'tamper' => check([], 'fixture-cb182-solo-sinteticO'),
        'truncated' => check([], 'fixture-cb182'),
    ],
    'falla_cerrado' => [
        'empty' => BackupIntegrityVerifier::verify([], SAMPLE, CHECKED),
        'missing' => without('checksum'),
        'null' => check(['checksum' => null]),
        'extra' => check(['untrusted' => 'flag']),
        'wrong_version' => check(['version' => 2]),
        'wrong_environment' => check(['environment' => 'production']),
        'bad_digest' => check(['checksum' => 'abc']),
        'zero_size' => check(['size_bytes' => 0]),
        'empty_sample' => check([], ''),
    ],
    'privacidad' => [
        'probe' => check([], 'SENSITIVE_FAKE_FIXTURE_ONLY'),
        'source' => basename(__DIR__ . '/../src/BackupIntegrityVerifier.php'),
    ],
    'unknown' => [
        'future' => check(['verified_at' => CHECKED + 1]),
        'reversed' => check(['created_at' => VERIFIED + 1]),
        'stale' => check([], SAMPLE, VERIFIED + 86401),
        'invalid_now' => check([], SAMPLE, -1),
        'string_timestamp' => check(['created_at' => (string) CREATED]),
        'oversized' => check([], str_repeat('x', 2097153)),
        'bad_ref' => check(['artifact_ref' => 'https://remote.invalid/file']),
        'boundary_fresh' => check([], SAMPLE, VERIFIED + 86400),
    ],
    default => throw new InvalidArgumentException('Unknown synthetic scenario'),
};
echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
