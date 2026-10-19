<?php
declare(strict_types=1);

namespace ControlBot\Persistence;

use InvalidArgumentException;
use JsonException;
use PDO;
use RuntimeException;

/** Explicit persistence boundary, injectable in the decision module. */
interface DecisionRepository
{
    public function save(string $decisionId, array $decision, int $recordedAt): void;

    public function find(string $decisionId): ?array;
}

/** SQL-bound parameters; no snapshots, credentials or implicit migrations. */
final class PdoDecisionRepository implements DecisionRepository
{
    public function __construct(private readonly PDO $db)
    {
        if ($db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new InvalidArgumentException('PDO must use exceptions.');
        }
    }

    public function save(string $decisionId, array $decision, int $recordedAt): void
    {
        self::assertId($decisionId);
        if ($recordedAt < 1 || $decision === [] || array_is_list($decision)) {
            throw new InvalidArgumentException('Decision record invalid.');
        }
        try {
            $json = json_encode($decision, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new InvalidArgumentException('Decision record invalid.');
        }
        if (strlen($json) > 65535) {
            throw new InvalidArgumentException('Decision record too large.');
        }

        // Two parameterized writes within one transaction work on both SQLite
        // (isolated tests) and MariaDB (runtime). Failed inserts roll back deletes.
        $this->db->beginTransaction();
        try {
            $delete = $this->db->prepare('DELETE FROM controlbot_decisions WHERE decision_id = :id');
            $delete->execute([':id' => $decisionId]);
            $insert = $this->db->prepare('INSERT INTO controlbot_decisions (decision_id, payload_json, recorded_at) VALUES (:id, :payload, :recorded_at)');
            $insert->execute([':id' => $decisionId, ':payload' => $json, ':recorded_at' => $recordedAt]);
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function find(string $decisionId): ?array
    {
        self::assertId($decisionId);
        $query = $this->db->prepare('SELECT payload_json FROM controlbot_decisions WHERE decision_id = :id');
        $query->execute([':id' => $decisionId]);
        $json = $query->fetchColumn();
        if ($json === false) {
            return null;
        }
        if (!is_string($json) || strlen($json) > 65535) {
            throw new RuntimeException('Stored decision invalid.');
        }
        try {
            $value = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Stored decision invalid.');
        }
        if (!is_array($value) || $value === [] || array_is_list($value)) {
            throw new RuntimeException('Stored decision invalid.');
        }
        return $value;
    }

    private static function assertId(string $decisionId): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $decisionId) !== 1) {
            throw new InvalidArgumentException('Decision identity invalid.');
        }
    }
}
