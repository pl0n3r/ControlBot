<?php
declare(strict_types=1);

require __DIR__ . '/../src/ConnectionProfile.php';
require __DIR__ . '/../src/HostingerConnectionHealth.php';

use ControlBot\Production\ConnectionProfile;
use ControlBot\Production\HostingerConnectionHealth;

$scenario = $argv[1] ?? '';
$now = 1_800_000_000;
$profileId = '11111111-2222-4333-8444-555555555555';
$expectedFingerprint = 'SHA256:' . str_repeat('A', 43);
$otherFingerprint = 'SHA256:' . str_repeat('B', 43);
$secretRef = 'vault:hostinger/brvtal-prod';

$profile = static function (string $secret = 'vault:hostinger/brvtal-prod') use (
    $profileId,
    $expectedFingerprint,
): ConnectionProfile {
    return ConnectionProfile::bootstrap(
        $profileId,
        'brvtal',
        'production',
        'srv123.hostinger.com',
        22,
        'ssh-user:brvtal',
        $secret,
        $expectedFingerprint,
    );
};

$service = static function (string $fingerprint, array &$requests, bool $reachable = true): HostingerConnectionHealth {
    return new HostingerConnectionHealth(
        static function (array $request) use ($fingerprint, &$requests, $reachable): array {
            $requests[] = $request;
            return ['reachable' => $reachable, 'fingerprint' => $fingerprint];
        }
    );
};

$out = [];

if ($scenario === 'reuse') {
    $requests = [];
    $checked = $service($expectedFingerprint, $requests)->verify($profile(), $now);
    $record = $checked['profile']->toServerRecord();
    $newSessionProfile = ConnectionProfile::fromServerRecord($record);
    $out = [
        'status' => $newSessionProfile->status(),
        'read_allowed' => $newSessionProfile->allowsReadOnlyDiagnosis(),
        'same_profile' => $newSessionProfile->safeSnapshot()['profile_id'] === $profileId,
        'probe_count' => count($requests),
    ];
} elseif ($scenario === 'secret') {
    $requests = [];
    $checked = $service($expectedFingerprint, $requests)->verify($profile(), $now);
    $public = json_encode(
        ['snapshot' => $checked['profile']->safeSnapshot(), 'health' => $checked['connection'], 'request' => $requests[0]],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
    );
    $out = [
        'public_contains_secret_ref' => str_contains($public, $secretRef),
        'public_contains_secret_key' => str_contains($public, 'secret_ref'),
        'server_record_has_opaque_ref' => $checked['profile']->toServerRecord()['secret_ref'] === $secretRef,
    ];
} elseif ($scenario === 'fingerprint') {
    $requests = [];
    $mismatch = $service($otherFingerprint, $requests)->verify($profile(), $now);
    $missingRequests = [];
    $missing = (new HostingerConnectionHealth(
        static function (array $request) use (&$missingRequests): array {
            $missingRequests[] = $request;
            return ['reachable' => true];
        }
    ))->verify($profile(), $now);
    $out = [
        'mismatch_status' => $mismatch['profile']->status(),
        'mismatch_healthy' => $mismatch['connection']['healthy'],
        'mismatch_code' => $mismatch['connection']['code'],
        'missing_status' => $missing['profile']->status(),
        'missing_healthy' => $missing['connection']['healthy'],
        'probe_count' => count($requests) + count($missingRequests),
    ];
} elseif ($scenario === 'readonly') {
    $requests = [];
    $checked = $service($expectedFingerprint, $requests)->verify($profile(), $now);
    $request = $requests[0];
    $out = [
        'operation' => $request['operation'],
        'effect' => $request['effect'],
        'request_keys' => array_keys($request),
        'identity_confirmed' => $checked['connection']['identity_confirmed'],
        'destination' => $checked['connection']['destination'],
        'status' => $checked['profile']->status(),
    ];
} elseif ($scenario === 'revoked') {
    $calls = 0;
    $revoked = $profile()->revoke($now);
    $health = new HostingerConnectionHealth(
        static function (array $request) use (&$calls): array {
            $calls++;
            return ['reachable' => true, 'fingerprint' => $request['expected_fingerprint']];
        }
    );
    try {
        $health->verify($revoked, $now + 10);
    } catch (Throwable $e) {
        $out['error'] = $e->getMessage();
    }
    $out += [
        'status' => $revoked->status(),
        'read_allowed' => $revoked->allowsReadOnlyDiagnosis(),
        'probe_calls' => $calls,
    ];
} elseif ($scenario === 'rotation') {
    $requests = [];
    $connected = $service($expectedFingerprint, $requests)->verify($profile(), $now)['profile'];
    $scope = $connected->authorityScope();
    $rotated = $connected->rotateSecretRef('vault:hostinger/brvtal-prod-v2');
    $afterRotation = $service($expectedFingerprint, $requests)->verify($rotated, $now + 30)['profile'];
    $out = [
        'scope_stable' => $scope === $rotated->authorityScope(),
        'profile_stable' => $rotated->safeSnapshot()['profile_id'] === $profileId,
        'rotation_requires_verify' => $rotated->status() === 'verifying',
        'reconnected' => $afterRotation->status() === 'connected',
        'server_ref_rotated' => $rotated->toServerRecord()['secret_ref'] === 'vault:hostinger/brvtal-prod-v2',
        'public_has_secret_ref' => array_key_exists('secret_ref', $rotated->safeSnapshot()),
    ];
} else {
    fwrite(STDERR, "scenario inválido\n");
    exit(2);
}

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
