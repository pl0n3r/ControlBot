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
        $keys = array_keys($raw);
        sort($keys);
        $expected = $required;
        sort($expected);
        if ($keys !== $expected || !in_array($raw['category'] ?? null, self::CATEGORIES, true)) {
            throw new InvalidArgumentException('Esquema o categoría de puerta inválidos.');
        }
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
            sort($optionKeys);
            if ($optionKeys !== ['id', 'label'] || !is_string($option['id']) || !is_string($option['label'])) {
                throw new InvalidArgumentException('Opción inválida.');
            }
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
    public function __construct(private readonly string $path) {}

    public function record(array $entry): void
    {
        $allowed = ['actor', 'action', 'repository', 'issue', 'option', 'sha', 'result', 'evidence', 'at'];
        if (array_diff(array_keys($entry), $allowed) !== []) {
            throw new InvalidArgumentException('Campo de auditoría no permitido.');
        }
        $line = json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        if (file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('No fue posible escribir la bitácora.');
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
                $this->log($owner, 'preflight-sha', $repository, $issue, $optionId, $sha, 'blocked', null, $now);
                throw new RuntimeException('main cambió desde que se mostró la decisión.');
            }
        }

        $comment = "Decisión del dueño: opción {$optionId} — {$label}.";
        if ($release) {
            $comment .= "\n\n<!-- factory-release-approval {\"sha\":\"{$sha}\"} -->";
        }

        $commentUrl = $this->step(
            $owner,
            'comment',
            $repository,
            $issue,
            $optionId,
            $sha,
            $now,
            fn (): string => $this->github->commentIssue($repository, $issue, $comment),
        );

        $closeUrl = $this->step(
            $owner,
            'close-issue',
            $repository,
            $issue,
            $optionId,
            $sha,
            $now,
            fn (): string => $this->github->closeIssue($repository, $issue),
        );

        $evidence = ['comment' => $commentUrl, 'issue' => $closeUrl];
        if ($release) {
            $tagUrl = $this->step(
                $owner,
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

        return ['category' => $gate->category, 'option' => $optionId, 'sha' => $sha, 'evidence' => $evidence];
    }

    private function step(
        OwnerContext $owner,
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
            $this->log($owner, $action, $repository, $issue, $option, $sha, 'failed', null, $at);
            throw $error;
        }

        $this->log($owner, $action, $repository, $issue, $option, $sha, 'success', $evidence, $at);
        return $evidence;
    }

    private function log(
        OwnerContext $owner,
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
            'option' => $option,
            'sha' => $sha,
            'result' => $result,
            'evidence' => $evidence,
            'at' => $at,
        ]);
    }
}
