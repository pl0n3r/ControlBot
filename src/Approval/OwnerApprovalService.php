<?php

declare(strict_types=1);

namespace ControlBot\Approval;

interface ApprovalPort
{
    /** Only trusted repository metadata, never a body supplied by the browser. */
    public function readGate(int $issue): array;
    public function mainSha(): string;
    public function channelSha(): string;
    public function appendOwnerApproval(int $issue, string $sha, string $owner): void;
    /** Adapter must compare the current channel SHA atomically before moving it. */
    public function compareAndMoveChannel(string $previousSha, string $sha): void;
    public function dispatchRelease(string $sha, int $issue): void;
}

final class ApprovalDenied extends \RuntimeException {}

final class OwnerApprovalService
{
    public function __construct(
        private readonly ApprovalPort $port,
        private readonly \Closure $trustedSession,
        private readonly \Closure $audit,
        private readonly \Closure $clock,
        private readonly string $owner = 'pl0n3r',
    ) {}

    public function approve(int $issue, string $option, ?string $sha = null): array
    {
        if ($issue < 1) {
            throw new ApprovalDenied('Issue inválido');
        }
        $actor = $this->requireOwner();
        $trustedGate = $this->port->readGate($issue);
        $gate = $this->gate($trustedGate);
        if (!in_array($option, $gate['options'], true)) {
            throw new ApprovalDenied('Opción inválida');
        }

        if ($gate['category'] === 'money') {
            if ($sha !== null) {
                throw new ApprovalDenied('Money no acepta release SHA');
            }
            $this->record($actor, $issue, 'money-decision', 'option:' . $option);
            return ['status' => 'recorded', 'category' => 'money'];
        }

        $approvalOption = $trustedGate['approval_option'] ?? null;
        if (!is_string($approvalOption) || !in_array($approvalOption, $gate['options'], true)) {
            throw new ApprovalDenied('Opción afirmativa no verificada');
        }
        if ($option !== $approvalOption) {
            $this->record($actor, $issue, 'factory-release-declined', 'option:' . $option);
            return ['status' => 'recorded', 'category' => 'factory-release'];
        }
        if (!is_string($sha) || preg_match('/^[0-9a-f]{40}$/D', $sha) !== 1) {
            throw new ApprovalDenied('SHA exacto requerido');
        }
        $current = $this->port->mainSha();
        if (!hash_equals($sha, $current)) {
            throw new ApprovalDenied('SHA desactualizado');
        }
        $previous = $this->port->channelSha();
        if (!is_string($previous) || preg_match('/^[0-9a-f]{40}$/D', $previous) !== 1) {
            throw new ApprovalDenied('Canal sin identidad comprobable');
        }

        // Fail closed: if audit cannot persist intent, no GitHub write is attempted.
        $receipt = 'option:' . $option . ':' . $sha;
        $this->record($actor, $issue, 'factory-release', 'prepared:' . $receipt);
        try {
            $this->port->appendOwnerApproval($issue, $sha, $actor);
            if (!hash_equals($sha, $this->port->mainSha())) {
                throw new ApprovalDenied('SHA cambió durante aprobación');
            }
            $this->port->compareAndMoveChannel($previous, $sha);
            $this->port->dispatchRelease($sha, $issue);
            $this->record($actor, $issue, 'factory-release', 'dispatched:' . $receipt);
        } catch (\Throwable) {
            try {
                $this->record($actor, $issue, 'factory-release', 'reconcile-required:' . $receipt);
            } catch (\Throwable) {
                // No untrusted exception text or credentials are written to logs.
            }
            throw new ApprovalDenied('Aprobación no concluida; reconciliar antes de reintentar');
        }
        return ['status' => 'dispatched', 'category' => 'factory-release', 'sha' => $sha];
    }

    private function requireOwner(): string
    {
        // This callback MUST be supplied by a server-side authenticated session.
        $session = ($this->trustedSession)();
        $now = ($this->clock)();
        if (!is_array($session) || !is_int($now) ||
            ($session['authenticated'] ?? null) !== true ||
            !is_string($session['actor'] ?? null) ||
            !hash_equals($this->owner, $session['actor']) ||
            !is_int($session['reauthenticated_at'] ?? null) ||
            $session['reauthenticated_at'] > $now ||
            $now - $session['reauthenticated_at'] > 300) {
            throw new ApprovalDenied('Se requiere reautenticación del dueño');
        }
        return $session['actor'];
    }

    private function gate(array $issue): array
    {
        $body = $issue['body'] ?? null;
        if (!in_array($issue['author_association'] ?? null, ['OWNER', 'MEMBER', 'COLLABORATOR'], true) ||
            !is_string($body) || strlen($body) > 65536 ||
            substr_count($body, 'factory-human-gate') !== 1 ||
            preg_match_all('/<!--\s*factory-human-gate\s+(\{.*?\})\s*-->/s', $body, $matches) !== 1) {
            throw new ApprovalDenied('Puerta no confiable');
        }
        try {
            $value = json_decode($matches[1][0], true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApprovalDenied('Puerta inválida');
        }
        if (!is_array($value) ||
            $this->keys($value) !== ['category', 'context', 'options', 'recommendation', 'safe_default'] ||
            !in_array($value['category'], ['factory-release', 'money'], true) ||
            !$this->line($value['context'], 500) ||
            !is_array($value['options']) || count($value['options']) < 2 || count($value['options']) > 4) {
            throw new ApprovalDenied('Contrato de puerta inválido');
        }
        $ids = [];
        foreach ($value['options'] as $row) {
            if (!is_array($row) || $this->keys($row) !== ['id', 'label'] ||
                !is_string($row['id']) || preg_match('/^[A-D]$/D', $row['id']) !== 1 ||
                in_array($row['id'], $ids, true) || !$this->line($row['label'], 240)) {
                throw new ApprovalDenied('Opciones inválidas');
            }
            $ids[] = $row['id'];
        }
        if (!in_array($value['recommendation'], $ids, true) ||
            !in_array($value['safe_default'], $ids, true)) {
            throw new ApprovalDenied('Default inválido');
        }
        return ['category' => $value['category'], 'options' => $ids];
    }

    private function keys(array $value): array
    {
        $keys = array_keys($value);
        sort($keys);
        return $keys;
    }

    private function line(mixed $value, int $max): bool
    {
        return is_string($value) && trim($value) !== '' &&
            strlen($value) <= $max && !str_contains($value, "\n") &&
            !str_contains($value, "\r");
    }

    private function record(string $actor, int $issue, string $action, string $result): void
    {
        try {
            ($this->audit)([
                'actor' => $actor, 'action' => $action, 'target_type' => 'issue',
                'target_id' => (string) $issue, 'result' => $result,
                'created_at' => gmdate('c', ($this->clock)()),
            ]);
        } catch (\Throwable) {
            throw new ApprovalDenied('Bitácora no disponible');
        }
    }
}
