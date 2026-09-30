<?php
declare(strict_types=1);

require __DIR__ . '/../src/DisasterRecoveryPolicy.php';
use ControlBot\Infrastructure\DisasterRecoveryPolicy as DR;

function policy(array $overrides = []): array
{
    return array_replace_recursive([
        'version' => 1, 'project_id' => 'controlbot', 'rpo_seconds' => 900, 'rto_seconds' => 3600,
        'retention' => ['recent' => 24, 'daily' => 7, 'weekly' => 8, 'monthly' => 12],
        'strategies' => ['database' => 'consistent_backup', 'media' => 'versioned_backup', 'code' => 'repository_mirror', 'secrets' => 'vault_reference_only'],
        'capabilities' => ['offsite' => true, 'versioned_or_immutable' => true, 'checksum_required' => true, 'freshness_required' => true, 'restore_drill_required' => true],
        'destinations' => [
            ['provider' => 'object_store', 'role' => 'versioned_or_immutable_copy'],
            ['provider' => 'google_drive', 'role' => 'offsite_encrypted_copy'],
        ],
        'policy_ref' => 'controlbot:dr-policy/controlbot/v1',
        'provenance_refs' => ['controlbot:source/aegis-182', 'controlbot:source/factory-305'],
    ], $overrides);
}

function blocked(array $raw): bool
{
    try { DR::normalize($raw); return false; } catch (InvalidArgumentException) { return true; }
}

$case = $argv[1] ?? '';
if ($case === 'valid') {
    $raw = policy();
    $out = ['policy' => DR::normalize($raw), 'fingerprint' => DR::fingerprint($raw)];
} elseif ($case === 'evidence') {
    $status = static fn(string $state, string $freshness, bool $complete): string => DR::evidenceStatus(compact('state', 'freshness', 'complete'));
    $out = [
        'missing' => DR::evidenceStatus(null), 'stale_healthy' => $status('healthy', 'stale', true),
        'incomplete_healthy' => $status('healthy', 'fresh', false), 'unknown' => $status('unknown', 'unknown', false),
        'blocked' => $status('blocked', 'stale', false), 'healthy' => $status('healthy', 'fresh', true),
    ];
} elseif ($case === 'permuted') {
    $raw = policy();
    $other = [
        'provenance_refs' => array_reverse($raw['provenance_refs']), 'policy_ref' => $raw['policy_ref'],
        'destinations' => array_map('array_reverse', array_reverse($raw['destinations'])),
        'capabilities' => array_reverse($raw['capabilities']), 'strategies' => array_reverse($raw['strategies']),
        'retention' => array_reverse($raw['retention']), 'rto_seconds' => 3600, 'rpo_seconds' => 900,
        'project_id' => 'controlbot', 'version' => 1,
    ];
    $out = ['base' => DR::fingerprint($raw), 'permuted' => DR::fingerprint($other)];
} else {
    $raw = policy();
    switch ($case) {
        case 'bad-rpo': $raw['rpo_seconds'] = 0; break;
        case 'bad-rto': $raw['rto_seconds'] = -1; break;
        case 'empty-retention': $raw['retention'] = ['recent'=>0,'daily'=>0,'weekly'=>0,'monthly'=>0]; break;
        case 'missing-field': unset($raw['rpo_seconds']); break;
        case 'extra-field': $raw['extra'] = true; break;
        case 'bad-secret-strategy': $raw['strategies']['secrets'] = 'consistent_backup'; break;
        case 'drive-primary': $raw['destinations'] = [['provider'=>'google_drive','role'=>'primary_runtime_storage']]; break;
        case 'drive-server': $raw['destinations'] = [['provider'=>'google_drive','role'=>'server_automation_dependency']]; break;
        case 'icloud-primary': $raw['destinations'] = [['provider'=>'icloud','role'=>'primary_runtime_storage']]; break;
        case 'icloud-server': $raw['destinations'] = [['provider'=>'icloud','role'=>'server_automation_dependency']]; break;
        case 'duplicate-destination': $raw['destinations'] = array_fill(0, 2, ['provider'=>'google_drive','role'=>'offsite_encrypted_copy']); break;
        case 'duplicate-provenance': $raw['provenance_refs'] = array_fill(0, 2, 'controlbot:source/aegis-182'); break;
        case 'sensitive-ref': $raw['provenance_refs'] = ['controlbot:source/token=abc123']; break;
        case 'bad-policy-project': $raw['policy_ref'] = 'controlbot:dr-policy/other/v1'; break;
        default: throw new InvalidArgumentException('scenario invalid');
    }
    $out = ['blocked' => blocked($raw)];
}
echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
