<?php
declare(strict_types=1);

namespace ControlBot\Production;

use Closure;

final class HostingerPublicApiRuntime
{
    private const EXECUTOR_ID = 'hostinger-public-api';
    private const INCOMPATIBLE = '__hostinger_runtime_incompatible__';
    private readonly Closure $transport;

    public function __construct(
        private readonly SecretsBroker $secretsBroker,
        callable $transport,
    ) {
        $this->transport = Closure::fromCallable($transport);
    }

    public function websites(
        SecretReference $reference,
        string $project,
        string $environment,
        int $now,
    ): array {
        $transport = $this->transport;
        return $this->withCredential(
            $reference,
            'hostinger.read',
            $project,
            $environment,
            static fn (string $credential): array => HostingerPublicApiAdapter::websites(
                $credential,
                $transport,
                $now,
            ),
        );
    }

    public function cronSnapshot(
        string $username,
        SecretReference $reference,
        string $project,
        string $environment,
        int $now,
    ): array {
        $transport = $this->transport;
        return $this->withCredential(
            $reference,
            'cron.snapshot',
            $project,
            $environment,
            static fn (string $credential): array => HostingerPublicApiAdapter::cronSnapshot(
                $username,
                $credential,
                $transport,
                $now,
            ),
        );
    }

    public function cronOutput(
        string $username,
        string $uid,
        SecretReference $reference,
        string $project,
        string $environment,
        int $now,
    ): array {
        $transport = $this->transport;
        return $this->withCredential(
            $reference,
            'cron.snapshot',
            $project,
            $environment,
            static fn (string $credential): array => HostingerPublicApiAdapter::cronOutput(
                $username,
                $uid,
                $credential,
                $transport,
                $now,
            ),
        );
    }

    public function planCronWrite(
        CapabilityGrant $grant,
        array $scope,
        string $username,
        array $cron,
        int $now,
    ): array {
        return HostingerPublicApiAdapter::planCronWrite(
            $grant,
            $scope,
            $username,
            $cron,
            $now,
        );
    }

    private function withCredential(
        SecretReference $reference,
        string $capability,
        string $project,
        string $environment,
        callable $operation,
    ): array {
        $handle = $reference->publicMetadata();

        $result = $this->secretsBroker->execute([
            'executor_id' => self::EXECUTOR_ID,
            'reference_id' => $handle['reference_id'],
            'capability' => $capability,
            'project' => $project,
            'environment' => $environment,
            'generation' => $handle['generation'],
        ], static function (
            string $credential,
            array $resolvedMetadata,
        ) use ($capability, $operation): array {
            if (
                ($resolvedMetadata['provider'] ?? null) !== 'hostinger'
                || ($resolvedMetadata['secret_kind'] ?? null) !== 'api_token'
                || ($resolvedMetadata['capability'] ?? null) !== $capability
            ) {
                return [self::INCOMPATIBLE => true];
            }

            return ['runtime_result' => $operation($credential)];
        });

        if (!($result['ok'] ?? false)) {
            return $result;
        }

        $resolved = $result['result'] ?? null;
        if ($resolved === [self::INCOMPATIBLE => true]) {
            return self::deny('secret_reference_incompatible');
        }
        if (!is_array($resolved) || array_keys($resolved) !== ['runtime_result']) {
            return self::deny('runtime_result_invalid');
        }

        $result['result'] = $resolved['runtime_result'];
        return $result;
    }

    private static function deny(string $reason): array
    {
        return [
            'ok' => false,
            'reason' => $reason,
            'result' => null,
            'secret' => null,
        ];
    }
}
