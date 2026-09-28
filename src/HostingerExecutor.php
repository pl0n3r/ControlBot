<?php
declare(strict_types=1);

namespace ControlBot\Production;

use Closure;
use InvalidArgumentException;

final class HostingerExecutor
{
    private const REQUEST_FIELDS = [
        'project', 'environment', 'resource', 'issue', 'run_id',
        'subject', 'idempotency_key', 'now',
    ];

    private const TERMINAL = ['success', 'failed', 'cancelled', 'timed_out', 'partial'];

    /** @var array<string,true> */
    private array $writeLedger = [];

    private readonly Closure $transport;

    public function __construct(
        private readonly SecretsBroker $broker,
        callable $transport,
        private readonly string $executorId = 'hostinger-executor',
    ) {
        if (preg_match('/^[a-z][a-z0-9-]{1,79}$/D', $executorId) !== 1) {
            throw new InvalidArgumentException('Executor id inválido.');
        }

        $this->transport = Closure::fromCallable($transport);
    }

    public function execute(
        ProductionOperation $operation,
        ?CapabilityGrant $grant,
        ConnectionProfile $profile,
        SecretReference $secretReference,
        array $request,
    ): array {
        self::validateRequest($request);

        if ($grant === null) {
            return self::deny('grant_required');
        }

        $profileRecord = $profile->toServerRecord();
        if ($profileRecord['project'] !== $request['project']
            || $profileRecord['environment'] !== $request['environment']) {
            return self::deny('profile_scope_mismatch');
        }

        if ($operation->effect() === 'read') {
            if (!$profile->allowsReadOnlyDiagnosis()) {
                return self::deny('profile_not_readable');
            }
        } elseif ($profile->status() !== 'connected') {
            return self::deny('profile_not_writable');
        }

        if ($profileRecord['secret_ref'] !== $secretReference->referenceId()) {
            return self::deny('profile_secret_reference_mismatch');
        }

        $grantRecord = $grant->safeRecord();
        if ($grantRecord['idempotency_key'] !== $request['idempotency_key']) {
            return self::deny('idempotency_key_mismatch');
        }

        $scope = [
            'capability' => $operation->capability(),
            'project' => $request['project'],
            'environment' => $request['environment'],
            'resource' => $request['resource'],
            'operation' => $operation->operationId(),
            'issue' => $request['issue'],
            'run_id' => $request['run_id'],
            'subject' => $request['subject'],
        ];

        $authorization = $grant->authorize($scope, $request['now']);
        if (!($authorization['authorized'] ?? false)) {
            return self::deny((string) ($authorization['reason'] ?? 'grant_denied'));
        }

        $ledgerKey = $grantRecord['grant_id'] . ':' . $request['idempotency_key'];
        if ($operation->effect() === 'write'
            && !$operation->retrySafe()
            && isset($this->writeLedger[$ledgerKey])) {
            return self::deny('non_idempotent_replay');
        }

        $secretContext = [
            'executor_id' => $this->executorId,
            'reference_id' => $secretReference->referenceId(),
            'capability' => $operation->capability(),
            'project' => $request['project'],
            'environment' => $request['environment'],
            'generation' => $secretReference->generation(),
        ];

        $brokerResult = $this->broker->execute(
            $secretContext,
            function (string $secret) use ($operation, $profile, $request, $ledgerKey): mixed {
                if ($operation->effect() === 'write' && !$operation->retrySafe()) {
                    $this->writeLedger[$ledgerKey] = true;
                }

                return ($this->transport)(
                    $operation->transportDescriptor($profile->destination(), $request),
                    $secret,
                );
            },
        );

        if (!($brokerResult['ok'] ?? false)) {
            return self::deny((string) ($brokerResult['reason'] ?? 'transport_denied'));
        }

        $transportResult = $brokerResult['result'] ?? null;
        if (!is_array($transportResult)) {
            return self::deny('invalid_transport_result');
        }

        return $this->operationResult(
            $operation,
            $grantRecord,
            $request,
            $transportResult,
        );
    }

    private function operationResult(
        ProductionOperation $operation,
        array $grant,
        array $request,
        array $transport,
    ): array {
        $expected = ['status', 'code', 'summary', 'artifacts', 'duration_ms'];
        if (array_is_list($transport)
            || count($transport) !== count($expected)
            || array_diff($expected, array_keys($transport)) !== []) {
            return self::deny('invalid_transport_result');
        }

        if (!is_string($transport['status'])
            || !in_array($transport['status'], self::TERMINAL, true)
            || !is_string($transport['code'])
            || preg_match('/^[a-z][a-z0-9_.-]{1,79}$/D', $transport['code']) !== 1
            || !is_string($transport['summary'])
            || !is_int($transport['duration_ms'])
            || $transport['duration_ms'] < 0) {
            return self::deny('invalid_transport_result');
        }

        $artifacts = self::artifacts($transport['artifacts']);
        if ($artifacts === null) {
            return self::deny('invalid_transport_result');
        }

        $status = $transport['status'];
        $code = $transport['code'];
        $summary = self::summary($transport['summary'], $operation->summaryLimit());

        if ($transport['duration_ms'] > $operation->timeoutMs()) {
            $status = 'timed_out';
            $code = 'transport_timeout';
            $summary = 'Transport exceeded the operation timeout.';
            $artifacts = [];
        }

        $finished = $request['now'] + (int) ceil($transport['duration_ms'] / 1000);

        return [
            'accepted' => true,
            'reason' => 'executed',
            'result' => [
                'version' => 1,
                'run_id' => $request['run_id'],
                'operation_id' => $operation->operationId(),
                'grant_id' => $grant['grant_id'],
                'project' => $request['project'],
                'environment' => $request['environment'],
                'resource' => $request['resource'],
                'status' => $status,
                'started_at' => gmdate('Y-m-d\TH:i:s\Z', $request['now']),
                'finished_at' => gmdate('Y-m-d\TH:i:s\Z', $finished),
                'effect' => $operation->effect(),
                'evidence' => [
                    'code' => $code,
                    'summary' => $summary,
                    'artifacts' => $artifacts,
                ],
                'retry' => [
                    'safe' => $operation->retrySafe(),
                    'idempotency_key' => $request['idempotency_key'],
                ],
            ],
        ];
    }

    private static function validateRequest(array $request): void
    {
        if (array_is_list($request)
            || count($request) !== count(self::REQUEST_FIELDS)
            || array_diff(self::REQUEST_FIELDS, array_keys($request)) !== []) {
            throw new InvalidArgumentException('Execution request inválido.');
        }

        foreach (['project', 'environment', 'resource', 'issue', 'run_id', 'subject', 'idempotency_key'] as $key) {
            if (!is_string($request[$key]) || $request[$key] === '' || strlen($request[$key]) > 180) {
                throw new InvalidArgumentException('Execution request inválido.');
            }
        }

        if (preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $request['project']) !== 1
            || preg_match('/^[a-z][a-z0-9-]{1,31}$/D', $request['environment']) !== 1
            || preg_match('/^[a-z][a-z0-9._:-]{1,119}$/D', $request['resource']) !== 1
            || preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+#[1-9][0-9]*$~D', $request['issue']) !== 1
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $request['run_id']) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,119}$/D', $request['subject']) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,159}$/D', $request['idempotency_key']) !== 1
            || !is_int($request['now'])
            || $request['now'] < 1) {
            throw new InvalidArgumentException('Execution request inválido.');
        }
    }

    private static function artifacts(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 10) {
            return null;
        }

        $out = [];
        foreach ($value as $artifact) {
            if (!is_string($artifact)
                || strlen($artifact) > 160
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,159}$/D', $artifact) !== 1
                || preg_match('~^(?:https?|ssh|s3)://~i', $artifact) === 1) {
                return null;
            }
            $out[] = $artifact;
        }

        return $out;
    }

    private static function summary(string $value, int $limit): string
    {
        $clean = preg_replace(
            '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i',
            '[REDACTED_PII]',
            $value,
        );
        $clean = preg_replace(
            '/\b(?:SELECT|INSERT|UPDATE|DELETE|ALTER|DROP|TRUNCATE)\b[^;\n]*/i',
            '[REDACTED_SQL]',
            (string) $clean,
        );

        return substr((string) $clean, 0, $limit);
    }

    private static function deny(string $reason): array
    {
        return [
            'accepted' => false,
            'reason' => $reason,
            'result' => null,
        ];
    }
}
