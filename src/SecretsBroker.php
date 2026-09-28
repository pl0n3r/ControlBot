<?php
declare(strict_types=1);

namespace ControlBot\Production;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class SecretsBroker
{
    /** @var array<string,array{reference:SecretReference,value:string}> */
    private array $entries = [];

    /** @var array<string,true> */
    private array $executors = [];

    /**
     * @param list<string> $authorizedExecutors
     */
    public function __construct(array $authorizedExecutors)
    {
        if (!array_is_list($authorizedExecutors) || $authorizedExecutors === []) {
            throw new InvalidArgumentException('Executors autorizados requeridos.');
        }

        foreach ($authorizedExecutors as $executor) {
            if (!is_string($executor)
                || preg_match('/^[a-z][a-z0-9-]{1,79}$/D', $executor) !== 1) {
                throw new InvalidArgumentException('Executor inválido.');
            }
            $this->executors[$executor] = true;
        }
    }

    public function register(SecretReference $reference, string $secretValue): void
    {
        self::secretValue($secretValue);

        $id = $reference->referenceId();
        if (isset($this->entries[$id])) {
            throw new RuntimeException('SecretReference ya registrada.');
        }

        $this->entries[$id] = [
            'reference' => $reference,
            'value' => $secretValue,
        ];
    }

    /**
     * The raw secret exists only inside this callback boundary.
     * The callback result is sanitized before leaving the broker.
     */
    public function execute(array $context, callable $executor): array
    {
        $executorId = $context['executor_id'] ?? null;
        if (!is_string($executorId) || !isset($this->executors[$executorId])) {
            return self::deny('executor_not_authorized');
        }

        $referenceId = $context['reference_id'] ?? null;
        if (!is_string($referenceId) || !isset($this->entries[$referenceId])) {
            return self::deny('secret_reference_unknown');
        }

        $entry = $this->entries[$referenceId];
        $reference = $entry['reference'];

        try {
            if ($reference->isRevoked()) {
                return self::deny('secret_reference_revoked');
            }
            if (!$reference->matchesContext($context)) {
                return self::deny('secret_scope_mismatch');
            }

            $raw = $executor($entry['value'], $reference->publicMetadata());
            return [
                'ok' => true,
                'reason' => 'executed',
                'result' => self::sanitize($raw, $entry['value']),
                'secret' => null,
            ];
        } catch (Throwable $error) {
            return [
                'ok' => false,
                'reason' => 'executor_failed',
                'error' => self::sanitize($error->getMessage(), $entry['value']),
                'secret' => null,
            ];
        }
    }

    public function revoke(string $referenceId, string $revokedAt): void
    {
        if (!isset($this->entries[$referenceId])) {
            throw new RuntimeException('SecretReference desconocida.');
        }
        $this->entries[$referenceId]['reference'] = $this->entries[$referenceId]['reference']->revoke($revokedAt);
    }

    public function rotate(
        string $referenceId,
        string $newReferenceId,
        string $issuedAt,
        string $newSecretValue,
    ): SecretReference {
        if (!isset($this->entries[$referenceId])) {
            throw new RuntimeException('SecretReference desconocida.');
        }
        self::secretValue($newSecretValue);
        if (isset($this->entries[$newReferenceId])) {
            throw new RuntimeException('SecretReference destino ya existe.');
        }

        $old = $this->entries[$referenceId]['reference'];
        $this->entries[$referenceId]['reference'] = $old->revoke($issuedAt);

        $new = $old->rotate($newReferenceId, $issuedAt);
        $this->entries[$newReferenceId] = [
            'reference' => $new,
            'value' => $newSecretValue,
        ];

        return $new;
    }

    public function agentSurface(SecretReference $reference): array
    {
        return [
            'reference' => $reference->publicMetadata(),
            'secret' => null,
            'resolvable' => false,
        ];
    }

    private static function sanitize(mixed $value, string $secret): mixed
    {
        if (is_string($value)) {
            $clean = str_replace($secret, '[REDACTED]', $value);
            $clean = preg_replace(
                '/(?i)(password|passwd|token|bearer|authorization|cookie|private[_ -]?key|dsn)\s*[:=]\s*[^\s,;]+/',
                '$1=[REDACTED]',
                $clean,
            );
            return substr((string) $clean, 0, 2_000);
        }

        if (is_array($value)) {
            $out = [];
            $count = 0;
            foreach ($value as $key => $item) {
                if (++$count > 50) {
                    break;
                }
                $safeKey = is_string($key) ? substr($key, 0, 80) : $key;
                if (is_string($safeKey)
                    && preg_match('/(?i)password|passwd|token|bearer|authorization|cookie|private[_ -]?key|dsn/', $safeKey) === 1) {
                    $out[$safeKey] = '[REDACTED]';
                    continue;
                }
                $out[$safeKey] = self::sanitize($item, $secret);
            }
            return $out;
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return '[REDACTED]';
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

    private static function secretValue(string $value): void
    {
        if (strlen($value) < 12 || strlen($value) > 4_096
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) === 1) {
            throw new InvalidArgumentException('Valor secreto inválido.');
        }
    }
}
