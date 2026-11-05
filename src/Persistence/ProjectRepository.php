<?php
declare(strict_types=1);

namespace ControlBot\Persistence;

use ControlBot\Production\BackupGate;
use InvalidArgumentException;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Additive domain migration wrapper. Keep the historical SchemaMigrator and
 * 001_decisions.sql untouched. Never call apply() from a web request.
 */
final class DomainSchemaMigrator
{
    private const TABLES = [
        'controlbot_projects',
        'controlbot_agents',
        'controlbot_agent_sessions',
        'controlbot_intake',
        'controlbot_pause_state',
    ];

    /** @return array{version:int,tables:list<string>,sha256:string} */
    public static function dryRun(PDO $db): array
    {
        self::requireExceptions($db);
        $source = dirname(__DIR__, 2) . '/migrations/002_projects_agents_intake.sql';
        $sql = @file_get_contents($source);
        if (!is_string($sql) || trim($sql) === '') {
            throw new RuntimeException('Domain migration missing.');
        }
        // The tracked SQL must contain exactly the five additive DDL statements.
        $withoutComments = preg_replace('/^\s*--[^\n]*(?:\n|$)/m', '', $sql);
        if (!is_string($withoutComments)) {
            throw new RuntimeException('Domain migration unreadable.');
        }
        $statements = array_values(array_filter(array_map('trim', explode(';', $withoutComments))));
        if (count($statements) !== count(self::TABLES)) {
            throw new RuntimeException('Domain migration statement count invalid.');
        }
        foreach ($statements as $index => $statement) {
            $table = self::TABLES[$index];
            if (preg_match('/^CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+' . $table
                . '\s*\(\s*scope_id\s+VARCHAR\(64\).*PRIMARY\s+KEY\s*\(scope_id,\s*record_id\)\s*\)$/isD', $statement) !== 1
                || preg_match('/\b(DROP|ALTER|TRUNCATE|GRANT|REPLACE|DELETE|UPDATE|INSERT)\b/i', $statement) === 1) {
                throw new RuntimeException('Domain migration contains unsafe DDL.');
            }
        }
        return ['version' => 2, 'tables' => self::TABLES, 'sha256' => hash('sha256', $sql)];
    }

    /**
     * Require an in-scope, fresh, protected-write BackupGate receipt BEFORE
     * applying 001 or 002. A structurally valid receipt never proves external
     * authenticity: the production caller must separately establish D-059.
     *
     * @param array{project:string,environment:string,resource:string,run_id:string,issue:string} $scope
     */
    public static function apply(
        PDO $db,
        ?array $backupReceipt,
        array $scope,
        int $writeAt
    ): void {
        $plan = self::dryRun($db);
        $gate = BackupGate::evaluate(
            'migration.registry.reconcile', $scope, $backupReceipt, $writeAt
        );
        if ($gate['allowed'] !== true || $gate['backup_required'] !== true
            || $gate['reason'] !== 'backup_verified') {
            throw new RuntimeException('Domain migration requires a verified scoped backup.');
        }
        // SQLite DDL is transactional. MariaDB DDL auto-commits: external
        // operator/go-live authority and backup/restore gates remain mandatory.
        $sqlite = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        if ($sqlite && $db->inTransaction()) {
            throw new RuntimeException('Nested migration transaction prohibited.');
        }
        if ($sqlite) {
            $db->beginTransaction();
        }
        try {
            SchemaMigrator::apply($db);
            $source = dirname(__DIR__, 2) . '/migrations/002_projects_agents_intake.sql';
            $sql = file_get_contents($source);
            if (!is_string($sql) || !hash_equals($plan['sha256'], hash('sha256', $sql))) {
                throw new RuntimeException('Domain migration changed after preflight.');
            }
            $withoutComments = preg_replace('/^\s*--[^\n]*(?:\n|$)/m', '', $sql);
            if (!is_string($withoutComments)) {
                throw new RuntimeException('Domain migration unreadable.');
            }
            foreach (array_filter(array_map('trim', explode(';', $withoutComments))) as $statement) {
                $db->exec($statement);
            }
            if ($sqlite) {
                $db->commit();
            }
        } catch (Throwable $error) {
            if ($sqlite && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }

    private static function requireExceptions(PDO $db): void
    {
        if ($db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new InvalidArgumentException('PDO must use exception mode.');
        }
    }
}

/** Fixed-table, scope-isolated SQL persistence. No automatic schema writes. */
abstract class PdoDomainRepository
{
    protected const TABLE = '';

    public function __construct(protected readonly PDO $db)
    {
        if ($db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
            || !in_array(static::TABLE, [
                'controlbot_projects', 'controlbot_agents',
                'controlbot_agent_sessions', 'controlbot_intake',
                'controlbot_pause_state',
            ], true)) {
            throw new InvalidArgumentException('Invalid domain repository.');
        }
    }

    /** Persist a bounded, associative payload under a mandatory tenant scope. */
    public function save(string $scopeId, string $recordId, array $payload, int $recordedAt): void
    {
        self::identity($scopeId, 64);
        self::identity($recordId, 96);
        if ($recordedAt < 1 || $payload === [] || array_is_list($payload)) {
            throw new InvalidArgumentException('Invalid domain record.');
        }
        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES, 32);
        } catch (JsonException) {
            throw new InvalidArgumentException('Invalid domain JSON.');
        }
        if (strlen($json) > 65535) {
            throw new InvalidArgumentException('Domain payload too large.');
        }
        if ($this->db->inTransaction()) {
            throw new RuntimeException('Nested domain write prohibited.');
        }

        $this->db->beginTransaction();
        try {
            $delete = $this->db->prepare(
                'DELETE FROM ' . static::TABLE . ' WHERE scope_id = :scope AND record_id = :id'
            );
            $delete->execute([':scope' => $scopeId, ':id' => $recordId]);
            $insert = $this->db->prepare(
                'INSERT INTO ' . static::TABLE
                . ' (scope_id, record_id, payload_json, recorded_at)'
                . ' VALUES (:scope, :id, :payload, :recorded_at)'
            );
            $insert->execute([
                ':scope' => $scopeId, ':id' => $recordId,
                ':payload' => $json, ':recorded_at' => $recordedAt,
            ]);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function find(string $scopeId, string $recordId): ?array
    {
        self::identity($scopeId, 64);
        self::identity($recordId, 96);
        $query = $this->db->prepare(
            'SELECT payload_json FROM ' . static::TABLE . ' WHERE scope_id = :scope AND record_id = :id'
        );
        $query->execute([':scope' => $scopeId, ':id' => $recordId]);
        $json = $query->fetchColumn();
        if ($json === false) {
            return null;
        }
        if (!is_string($json) || strlen($json) > 65535) {
            throw new RuntimeException('Stored domain record invalid.');
        }
        try {
            $value = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Stored domain record invalid.');
        }
        if (!is_array($value) || $value === [] || array_is_list($value)) {
            throw new RuntimeException('Stored domain record invalid.');
        }
        return $value;
    }

    private static function identity(string $value, int $max): void
    {
        if (strlen($value) > $max
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/D', $value) !== 1) {
            throw new InvalidArgumentException('Invalid domain identity.');
        }
    }
}

final class PdoProjectRepository extends PdoDomainRepository
{
    protected const TABLE = 'controlbot_projects';
}
final class PdoAgentRepository extends PdoDomainRepository
{
    protected const TABLE = 'controlbot_agents';
}
final class PdoAgentSessionRepository extends PdoDomainRepository
{
    protected const TABLE = 'controlbot_agent_sessions';
}
final class PdoIntakeRepository extends PdoDomainRepository
{
    protected const TABLE = 'controlbot_intake';
}
final class PdoPauseRepository extends PdoDomainRepository
{
    protected const TABLE = 'controlbot_pause_state';
}
