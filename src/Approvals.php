<?php
declare(strict_types=1);

namespace ControlBot\Approvals;

use InvalidArgumentException;
use RuntimeException;

final class HumanGate
{
    private const CATEGORIES = [
        'product-direction', 'brand', 'money', 'legal',
        'real-customer-data', 'release-1.0.0', 'factory-release', 'go-live',
    ];

    public function __construct(
        public readonly string $category,
        public readonly string $context,
        public readonly array $options,
        public readonly string $recommendation,
        public readonly string $safeDefault,
    ) {}

    public static function fromIssueBody(string $body): self
    {
        if (strlen($body) > 65536 || substr_count($body, 'factory-human-gate') !== 1) {
            throw new InvalidArgumentException('Puerta humana ausente o ambigua.');
        }
        if (preg_match('/<!--\s*factory-human-gate\s+(\{.*?\})\s*-->/s', $body, $match) !== 1) {
            throw new InvalidArgumentException('Marker factory-human-gate inválido.');
        }

        $raw = json_decode($match[1], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($raw)) {
            throw new InvalidArgumentException('Puerta humana inválida.');
        }

        $required = ['category', 'context', 'options', 'recommendation', 'safe_default'];
        $allowed = array_merge($required, ['title_simple', 'summary_simple', 'why_recommended', 'blocks']);
        if (
            array_diff($required, array_keys($raw)) !== []
            || array_diff(array_keys($raw), $allowed) !== []
            || !in_array($raw['category'] ?? null, self::CATEGORIES, true)
        ) {
            throw new InvalidArgumentException('Esquema o categoría de puerta inválidos.');
        }
        self::validateSimpleFields($raw);
        if (!is_string($raw['context']) || trim($raw['context']) === '' || strlen($raw['context']) > 500 || str_contains($raw['context'], "\n")) {
            throw new InvalidArgumentException('Contexto inválido.');
        }
        if (!is_array($raw['options']) || count($raw['options']) < 2 || count($raw['options']) > 4) {
            throw new InvalidArgumentException('Opciones inválidas.');
        }

        $options = [];
        foreach ($raw['options'] as $option) {
            if (!is_array($option)) {
                throw new InvalidArgumentException('Opción inválida.');
            }
            $optionKeys = array_keys($option);
            $allowedOption = ['id', 'label', 'effect', 'pros', 'cons', 'risk', 'cost', 'reversible'];
            if (
                !isset($option['id'], $option['label'])
                || array_diff($optionKeys, $allowedOption) !== []
                || !is_string($option['id'])
                || !is_string($option['label'])
            ) {
                throw new InvalidArgumentException('Opción inválida.');
            }
            self::validateOptionMetadata($option);
            $id = $option['id'];
            $label = trim($option['label']);
            if (preg_match('/^[A-D]$/', $id) !== 1 || isset($options[$id]) || $label === '' || strlen($label) > 240 || str_contains($label, "\n")) {
                throw new InvalidArgumentException('Opción inválida.');
            }
            $options[$id] = $label;
        }

        if (!is_string($raw['recommendation']) || !is_string($raw['safe_default'])) {
            throw new InvalidArgumentException('Recomendación/default inválidos.');
        }
        $recommendation = $raw['recommendation'];
        $safeDefault = $raw['safe_default'];
        if (!isset($options[$recommendation], $options[$safeDefault])) {
            throw new InvalidArgumentException('Recomendación/default no apuntan a una opción.');
        }

        return new self($raw['category'], trim($raw['context']), $options, $recommendation, $safeDefault);
    }

    private static function validateSimpleFields(array $raw): void
    {
        foreach ([
            'title_simple' => [180, 1],
            'summary_simple' => [600, 3],
            'why_recommended' => [300, 1],
            'blocks' => [300, 1],
        ] as $field => [$maxLength, $maxLines]) {
            if (!array_key_exists($field, $raw)) {
                continue;
            }
            $value = $raw[$field];
            if (
                !is_string($value)
                || trim($value) === ''
                || strlen(trim($value)) > $maxLength
                || str_contains($value, "\r")
                || count(explode("\n", trim($value))) > $maxLines
            ) {
                throw new InvalidArgumentException('Campo simple de puerta inválido.');
            }
        }
    }

    private static function validateOptionMetadata(array $option): void
    {
        foreach (['effect' => 300, 'cost' => 120] as $field => $maxLength) {
            if (!array_key_exists($field, $option)) {
                continue;
            }
            if (
                !is_string($option[$field])
                || strlen(trim($option[$field])) > $maxLength
                || ($field === 'effect' && trim($option[$field]) === '')
                || str_contains($option[$field], "\n")
                || str_contains($option[$field], "\r")
            ) {
                throw new InvalidArgumentException('Metadata de opción inválida.');
            }
        }
        foreach (['pros', 'cons'] as $field) {
            if (!array_key_exists($field, $option)) {
                continue;
            }
            if (!is_array($option[$field]) || count($option[$field]) < 1 || count($option[$field]) > 5) {
                throw new InvalidArgumentException('Metadata de opción inválida.');
            }
            foreach ($option[$field] as $item) {
                if (!is_string($item) || trim($item) === '' || strlen(trim($item)) > 180 || str_contains($item, "\n")) {
                    throw new InvalidArgumentException('Metadata de opción inválida.');
                }
            }
        }
        if (
            array_key_exists('risk', $option)
            && (!is_string($option['risk']) || !in_array($option['risk'], ['low', 'medium', 'high'], true))
        ) {
            throw new InvalidArgumentException('Metadata de opción inválida.');
        }
        if (array_key_exists('reversible', $option) && !is_bool($option['reversible'])) {
            throw new InvalidArgumentException('Metadata de opción inválida.');
        }
    }

    public function option(string $id): string
    {
        if (!isset($this->options[$id])) {
            throw new InvalidArgumentException('Opción inexistente.');
        }
        return $this->options[$id];
    }
}

final class OwnerContext
{
    public function __construct(
        public readonly string $login,
        public readonly bool $isOwner,
        public readonly int $reauthenticatedAt,
    ) {}

    public function assertFresh(int $now, int $maxAgeSeconds = 300): void
    {
        $age = $now - $this->reauthenticatedAt;
        if (!$this->isOwner || $age < 0 || $age > $maxAgeSeconds) {
            throw new RuntimeException('Se requiere reautenticación reciente del dueño.');
        }
    }
}

interface GitHubGateway
{
    public function mainSha(string $repository): string;
    public function commentIssue(string $repository, int $issue, string $body): string;
    public function closeIssue(string $repository, int $issue): string;
    public function moveTag(string $repository, string $tag, string $sha): string;
    public function dispatchWorkflow(string $repository, string $workflow, array $inputs): string;
}

final class AppendOnlyAuditLog
{
    private const ALLOWED_FIELDS = [
        'actor', 'action', 'repository', 'issue', 'category', 'option',
        'sha', 'result', 'evidence', 'at', 'snoozed_until',
    ];

    public function __construct(private readonly string $path) {}

    public function record(array $entry): void
    {
        if (array_diff(array_keys($entry), self::ALLOWED_FIELDS) !== []) {
            throw new InvalidArgumentException('Campo de auditoría no permitido.');
        }
        $line = json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        if (file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('No fue posible escribir la bitácora.');
        }
    }

    public function entries(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $size = filesize($this->path);
        if ($size === false || $size > 10 * 1024 * 1024) {
            throw new RuntimeException('Bitácora demasiado grande o ilegible.');
        }
        $handle = fopen($this->path, 'rb');
        if ($handle === false || !flock($handle, LOCK_SH)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('No fue posible leer la bitácora.');
        }
        try {
            $contents = stream_get_contents($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        if ($contents === false || trim($contents) === '') {
            return [];
        }

        $lines = preg_split('/\R/', trim($contents));
        if (!is_array($lines) || count($lines) > 5000) {
            throw new RuntimeException('Bitácora inválida.');
        }
        $entries = [];
        foreach ($lines as $line) {
            if ($line === '' || strlen($line) > 8192) {
                throw new RuntimeException('Entrada de bitácora inválida.');
            }
            $entry = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($entry) || array_diff(array_keys($entry), self::ALLOWED_FIELDS) !== []) {
                throw new RuntimeException('Entrada de bitácora inválida.');
            }
            $entries[] = $entry;
        }
        return $entries;
    }

    public function entriesByAction(string $action): iterable
    {
        if ($action === '' || strlen($action) > 80 || str_contains($action, "\n") || str_contains($action, "\r")) {
            throw new InvalidArgumentException('Acción de auditoría inválida.');
        }
        if (!is_file($this->path)) {
            return;
        }

        $handle = fopen($this->path, 'rb');
        if ($handle === false || !flock($handle, LOCK_SH)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('No fue posible leer la bitácora.');
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line === '' || strlen($line) > 8192) {
                    throw new RuntimeException('Entrada de bitácora inválida.');
                }
                $entry = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($entry) || array_diff(array_keys($entry), self::ALLOWED_FIELDS) !== []) {
                    throw new RuntimeException('Entrada de bitácora inválida.');
                }
                if (($entry['action'] ?? null) === $action) {
                    yield $entry;
                }
            }
            if (!feof($handle)) {
                throw new RuntimeException('No fue posible leer la bitácora.');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}

final class OwnerApprovalService
{
    public function __construct(
        private readonly GitHubGateway $github,
        private readonly AppendOnlyAuditLog $audit,
    ) {}

    public function approve(
        HumanGate $gate,
        string $optionId,
        string $repository,
        int $issue,
        ?string $displayedSha,
        OwnerContext $owner,
        int $now,
    ): array {
        $owner->assertFresh($now);
        $label = $gate->option($optionId);
        $sha = null;
        $release = $gate->category === 'factory-release' && $optionId === 'A';

        if ($gate->category === 'go-live') {
            throw new RuntimeException('Go-live requiere verificación explícita de prerequisitos legales y de datos.');
        }

        if ($release) {
            if (!is_string($displayedSha) || preg_match('/^[0-9a-f]{40}$/', $displayedSha) !== 1) {
                throw new RuntimeException('SHA aprobado inválido.');
            }
            $sha = $displayedSha;
            $current = $this->github->mainSha($repository);
            if (!hash_equals($sha, $current)) {
                $this->log($owner, $gate->category, 'preflight-sha', $repository, $issue, $optionId, $sha, 'blocked', null, $now);
                throw new RuntimeException('main cambió desde que se mostró la decisión.');
            }
        }

        $comment = "Decisión del dueño: opción {$optionId} — {$label}.";
        if ($release) {
            $comment .= "\n\n<!-- factory-release-approval {\"sha\":\"{$sha}\"} -->";
        }

        $commentUrl = $this->step(
            $owner,
            $gate->category,
            'comment',
            $repository,
            $issue,
            $optionId,
            $sha,
            $now,
            fn (): string => $this->github->commentIssue($repository, $issue, $comment),
        );

        $evidence = ['comment' => $commentUrl];
        if ($release) {
            $tagUrl = $this->step(
                $owner,
                $gate->category,
                'move-v1',
                $repository,
                $issue,
                $optionId,
                $sha,
                $now,
                fn (): string => $this->github->moveTag($repository, 'v1', $sha),
            );
            $runUrl = $this->step(
                $owner,
                $gate->category,
                'dispatch-release',
                $repository,
                $issue,
                $optionId,
                $sha,
                $now,
                fn (): string => $this->github->dispatchWorkflow($repository, 'release-bootstrap.yml', [
                    'expected_sha' => $sha,
                    'gate_issue' => (string) $issue,
                ]),
            );
            $evidence += ['tag' => $tagUrl, 'run' => $runUrl];
        }

        $closeUrl = $this->step(
            $owner,
            $gate->category,
            'close-issue',
            $repository,
            $issue,
            $optionId,
            $sha,
            $now,
            fn (): string => $this->github->closeIssue($repository, $issue),
        );
        $evidence['issue'] = $closeUrl;

        return ['category' => $gate->category, 'option' => $optionId, 'sha' => $sha, 'evidence' => $evidence];
    }

    private function step(
        OwnerContext $owner,
        string $category,
        string $action,
        string $repository,
        int $issue,
        string $option,
        ?string $sha,
        int $at,
        callable $call,
    ): string {
        try {
            $evidence = $call();
        } catch (\Throwable $error) {
            $this->log($owner, $category, $action, $repository, $issue, $option, $sha, 'failed', null, $at);
            throw $error;
        }

        $this->log($owner, $category, $action, $repository, $issue, $option, $sha, 'success', $evidence, $at);
        return $evidence;
    }

    private function log(
        OwnerContext $owner,
        string $category,
        string $action,
        string $repository,
        int $issue,
        string $option,
        ?string $sha,
        string $result,
        ?string $evidence,
        int $at,
    ): void {
        $this->audit->record([
            'actor' => $owner->login,
            'action' => $action,
            'repository' => $repository,
            'issue' => $issue,
            'category' => $category,
            'option' => $option,
            'sha' => $sha,
            'result' => $result,
            'evidence' => $evidence,
            'at' => $at,
        ]);
    }
}
