<?php
declare(strict_types=1);

namespace ControlBot\Production;

use Closure;

final class HostingerPublicApiRuntime
{
    private const EXECUTOR_ID = 'hostinger-public-api';
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
        $metadata = $reference->publicMetadata();
        if (
            ($metadata['provider'] ?? null) !== 'hostinger'
            || ($metadata['secret_kind'] ?? null) !== 'api_token'
            || ($metadata['capability'] ?? null) !== $capability
        ) {
            return self::deny('secret_reference_incompatible');
        }

        return $this->secretsBroker->execute([
            'executor_id' => self::EXECUTOR_ID,
            'reference_id' => $metadata['reference_id'],
            'capability' => $capability,
            'project' => $project,
            'environment' => $environment,
            'generation' => $metadata['generation'],
        ], $operation);
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
