<?php
declare(strict_types=1);

require __DIR__ . '/../src/SecretReference.php';
require __DIR__ . '/../src/SecretsBroker.php';

use ControlBot\Production\SecretReference;
use ControlBot\Production\SecretsBroker;

const SECRET = 'fixture-value-ALPHA-938271';

function reference(array $replace = []): SecretReference
{
    return SecretReference::fromRecord(array_replace([
        'version' => 1,
        'reference_id' => '11111111-2222-4333-8444-555555555555',
        'capability' => 'ssh.readonly',
        'project' => 'brvtal',
        'environment' => 'production',
        'provider' => 'hostinger',
        'secret_kind' => 'password',
        'generation' => 1,
        'issued_at' => '2027-01-15T07:00:00Z',
        'revoked_at' => null,
    ], $replace));
}

function context(array $replace = []): array
{
    return array_replace([
        'executor_id' => 'hostinger-executor',
        'reference_id' => '11111111-2222-4333-8444-555555555555',
        'capability' => 'ssh.readonly',
        'project' => 'brvtal',
        'environment' => 'production',
        'generation' => 1,
    ], $replace);
}

$name = $argv[1] ?? '';

if ($name === 'surface') {
    $ref = reference();
    $broker = new SecretsBroker(['hostinger-executor']);
    $broker->register($ref, SECRET);
    $encoded = json_encode($broker->agentSurface($ref), JSON_THROW_ON_ERROR);
    $out = [
        'surface' => $broker->agentSurface($ref),
        'contains_secret' => str_contains($encoded, SECRET),
    ];
} elseif ($name === 'redaction') {
    $ref = reference();
    $broker = new SecretsBroker(['hostinger-executor']);
    $broker->register($ref, SECRET);
    $result = $broker->execute(context(), static function (string $secret): array {
        return [
            'stdout' => 'connected password=' . $secret,
            'token' => $secret,
            'nested' => ['dsn' => 'mysql://' . $secret, 'safe' => 'ok'],
        ];
    });
    $error = $broker->execute(context(), static function (string $secret): never {
        throw new RuntimeException('authorization=' . $secret);
    });
    $encoded = json_encode([$result, $error], JSON_THROW_ON_ERROR);
    $out = [
        'result' => $result,
        'error' => $error,
        'contains_secret' => str_contains($encoded, SECRET),
    ];
} elseif ($name === 'context') {
    $ref = reference();
    $broker = new SecretsBroker(['hostinger-executor']);
    $broker->register($ref, SECRET);
    $cases = [
        'executor' => $broker->execute(context(['executor_id' => 'rogue-executor']), static fn (): string => 'never'),
        'capability' => $broker->execute(context(['capability' => 'database.backup']), static fn (): string => 'never'),
        'project' => $broker->execute(context(['project' => 'condor']), static fn (): string => 'never'),
        'environment' => $broker->execute(context(['environment' => 'staging']), static fn (): string => 'never'),
        'generation' => $broker->execute(context(['generation' => 2]), static fn (): string => 'never'),
    ];
    $allowed = $broker->execute(context(), static fn (string $secret): string => 'used:' . strlen($secret));
    $out = ['cases' => $cases, 'allowed' => $allowed];
} elseif ($name === 'lifecycle') {
    $ref = reference();
    $broker = new SecretsBroker(['hostinger-executor']);
    $broker->register($ref, SECRET);

    $broker->revoke($ref->referenceId(), '2027-01-15T07:05:00Z');
    $revoked = $broker->execute(context(), static fn (): string => 'never');

    $ref2 = reference([
        'reference_id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
        'issued_at' => '2027-01-15T08:00:00Z',
    ]);
    $broker2 = new SecretsBroker(['hostinger-executor']);
    $broker2->register($ref2, SECRET);
    $new = $broker2->rotate(
        $ref2->referenceId(),
        'bbbbbbbb-cccc-4ddd-8eee-ffffffffffff',
        '2027-01-15T08:05:00Z',
        'fixture-value-BETA-827364',
    );

    $oldContext = [
        'executor_id' => 'hostinger-executor',
        'reference_id' => $ref2->referenceId(),
        'capability' => 'ssh.readonly',
        'project' => 'brvtal',
        'environment' => 'production',
        'generation' => 1,
    ];
    $newContext = [
        'executor_id' => 'hostinger-executor',
        'reference_id' => $new->referenceId(),
        'capability' => 'ssh.readonly',
        'project' => 'brvtal',
        'environment' => 'production',
        'generation' => 2,
    ];

    $out = [
        'revoked' => $revoked,
        'old_after_rotation' => $broker2->execute($oldContext, static fn (): string => 'never'),
        'new_after_rotation' => $broker2->execute($newContext, static fn (string $secret): string => 'used:' . strlen($secret)),
        'capability_stable' => $new->capability() === $ref2->capability(),
    ];
} else {
    fwrite(STDERR, "scenario inválido\n");
    exit(2);
}

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
