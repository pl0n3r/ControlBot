<?php
declare(strict_types=1);

namespace ControlBot\Persistence;

use PDO;
use RuntimeException;

/** Explicit, read-from-versioned-source migration. No implicit deployment. */
final class SchemaMigrator
{
    public static function apply(PDO $db): void
    {
        if ($db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new RuntimeException('PDO must use exceptions.');
        }
        $file = dirname(__DIR__, 2) . '/migrations/001_decisions.sql';
        $sql = file_get_contents($file);
        if (!is_string($sql) || $sql === '') {
            throw new RuntimeException('Required migration missing.');
        }
        // The only migration is additive and idempotent on MariaDB and SQLite.
        $db->exec($sql);
    }
}
