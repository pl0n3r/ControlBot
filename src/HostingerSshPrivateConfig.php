<?php
declare(strict_types=1);

namespace ControlBot\Production;

use InvalidArgumentException;

final class HostingerSshPrivateConfig
{
    private const FIELDS = [
        'profile', 'secret_reference', 'private_key', 'identity', 'username',
    ];

    private function __construct(
        private readonly HostingerSshRuntime $runtime,
        private readonly ConnectionProfile $profile,
        private readonly SecretReference $secretReference,
    ) {
    }

    public static function fromRecord(
        array $record,
        ?OpenSshClient $sshClient = null,
    ): self {
        self::fields($record);

        foreach (['profile', 'secret_reference', 'identity'] as $field) {
            if (!is_array($record[$field])) {
                throw new InvalidArgumentException('Private SSH configuration invalid.');
            }
        }
        if (!is_string($record['private_key']) || !is_string($record['username'])) {
            throw new InvalidArgumentException('Private SSH material invalid.');
        }

        $profile = ConnectionProfile::fromServerRecord($record['profile']);
        $secret = SecretReference::fromRecord($record['secret_reference']);
        $profileRecord = $profile->toServerRecord();
        $secretRecord = $secret->record();
        $identity = $record['identity'];

        if ($secretRecord['capability'] !== 'ssh.readonly'
            || $secretRecord['secret_kind'] !== 'private_key'
            || $secretRecord['provider'] !== 'hostinger'
            || $profileRecord['project'] !== $secretRecord['project']
            || $profileRecord['environment'] !== $secretRecord['environment']
            || $profileRecord['secret_ref'] !== $secret->referenceId()
            || ($identity['provider'] ?? null) !== 'hostinger'
            || ($identity['project'] ?? null) !== $profileRecord['project']
            || ($identity['environment'] ?? null) !== $profileRecord['environment']
            || ($identity['username_ref'] ?? null) !== $profileRecord['username_ref']) {
            throw new InvalidArgumentException('Private SSH configuration scope mismatch.');
        }

        $secrets = new SecretsBroker(['hostinger-executor']);
        $secrets->register($secret, $record['private_key']);

        $identities = new ConnectionIdentityBroker(['hostinger-executor']);
        $identities->register($identity, $record['username']);

        return new self(
            new HostingerSshRuntime($secrets, $identities, $sshClient),
            $profile,
            $secret,
        );
    }

    public function execute(
        ProductionOperation $operation,
        ?CapabilityGrant $grant,
        array $request,
    ): array {
        if ($operation->operationId() !== 'ssh.readonly') {
            throw new InvalidArgumentException('Private SSH runtime only supports ssh.readonly.');
        }
        return $this->runtime->execute(
            $operation,
            $grant,
            $this->profile,
            $this->secretReference,
            $request,
        );
    }

    public function safeSnapshot(): array
    {
        return [
            'version' => 1,
            'profile' => $this->profile->safeSnapshot(),
            'capability' => 'ssh.readonly',
            'secret_kind' => 'private_key',
            'private_key' => null,
            'username' => null,
        ];
    }

    private static function fields(array $record): void
    {
        if (array_is_list($record)
            || count($record) !== count(self::FIELDS)
            || array_diff(self::FIELDS, array_keys($record)) !== []
            || array_diff(array_keys($record), self::FIELDS) !== []) {
            throw new InvalidArgumentException('Private SSH configuration fields invalid.');
        }
    }
}
