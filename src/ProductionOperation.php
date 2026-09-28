<?php
declare(strict_types=1);

namespace ControlBot\Production;

use InvalidArgumentException;

final class ProductionOperation
{
    private const CATALOG = [
        'hostinger.read' => ['hostinger.read', 'read', true, 5_000, 500],
        'deploy.status' => ['deploy.status', 'read', true, 5_000, 500],
        'ssh.readonly' => ['ssh.readonly', 'read', true, 8_000, 500],
        'database.backup' => ['database.backup', 'write', false, 30_000, 500],
        'migration.status' => ['migration.status', 'read', true, 8_000, 500],
        'migration.registry.reconcile' => ['migration.registry.reconcile', 'write', false, 20_000, 500],
        'migration.verify' => ['migration.verify', 'read', true, 10_000, 500],
        'health.check' => ['health.check', 'read', true, 5_000, 500],
        'smoke.run' => ['smoke.run', 'read', true, 15_000, 500],
    ];

    private function __construct(
        private readonly string $operationId,
        private readonly string $capability,
        private readonly string $effect,
        private readonly bool $retrySafe,
        private readonly int $timeoutMs,
        private readonly int $summaryLimit,
    ) {
    }

    public static function fromId(string $operationId): self
    {
        if (!isset(self::CATALOG[$operationId])) {
            throw new InvalidArgumentException('Operación de producción no permitida.');
        }

        [$capability, $effect, $retrySafe, $timeoutMs, $summaryLimit] = self::CATALOG[$operationId];

        return new self(
            $operationId,
            $capability,
            $effect,
            $retrySafe,
            $timeoutMs,
            $summaryLimit,
        );
    }

    public function operationId(): string
    {
        return $this->operationId;
    }

    public function capability(): string
    {
        return $this->capability;
    }

    public function effect(): string
    {
        return $this->effect;
    }

    public function retrySafe(): bool
    {
        return $this->retrySafe;
    }

    public function timeoutMs(): int
    {
        return $this->timeoutMs;
    }

    public function summaryLimit(): int
    {
        return $this->summaryLimit;
    }

    public function transportDescriptor(string $destination, array $request): array
    {
        return [
            'operation_id' => $this->operationId,
            'capability' => $this->capability,
            'effect' => $this->effect,
            'timeout_ms' => $this->timeoutMs,
            'destination' => $destination,
            'project' => $request['project'],
            'environment' => $request['environment'],
            'resource' => $request['resource'],
            'run_id' => $request['run_id'],
        ];
    }
}
