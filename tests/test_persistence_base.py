"""#808: SQLite disposable DB, additive migration and real PHP statement coverage."""

import json
import sqlite3
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MIGRATION = ROOT / "migrations/001_decisions.sql"
REPOSITORY = ROOT / "src/Persistence/DecisionRepository.php"
MIGRATOR = ROOT / "src/Persistence/SchemaMigrator.php"

# A real PHP file ensures the CLI coverage auto_prepend_file instruments the
# required source files. PHP -r bypasses that bootstrap on some runtimes.
SCENARIO = r'''<?php
declare(strict_types=1);
require $argv[2];
require $argv[3];
use ControlBot\Persistence\PdoDecisionRepository;
use ControlBot\Persistence\SchemaMigrator;
$db = new PDO('sqlite:' . $argv[1], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
SchemaMigrator::apply($db); SchemaMigrator::apply($db);
$repo = new PdoDecisionRepository($db);
$repo->save('repo:42', ['choice'=>'B','state'=>'pending'], 1700000000);
$before = $repo->find('repo:42');
$repo->save('repo:42', ['choice'=>'A','state'=>'approved'], 1700000001);
$after = $repo->find('repo:42');
$missing = $repo->find('missing');
function rejects(callable $task): bool {
    try { $task(); return false; }
    catch (InvalidArgumentException|RuntimeException $e) { return true; }
}
$invalidId = rejects(fn()=> $repo->save('bad id', ['choice'=>'A'], 3));
$invalidReadId = rejects(fn()=> $repo->find('bad id'));
$invalidJson = rejects(fn()=> $repo->save('repo:43', ['choice'=>INF], 3));
$empty = rejects(fn()=> $repo->save('repo:43', [], 3));
$list = rejects(fn()=> $repo->save('repo:43', ['a', 'b'], 3));
$invalidTime = rejects(fn()=> $repo->save('repo:43', ['choice'=>'A'], 0));
$large = rejects(fn()=> $repo->save('repo:43', ['payload'=>str_repeat('x', 65536)], 3));
// Regression: deep JSON must be rejected BEFORE deleting an existing record.
$nested = ['choice'=>'deep'];
for ($level = 0; $level < 34; $level++) { $nested = ['nested'=>$nested]; }
$invalidDepth = rejects(fn()=> $repo->save('repo:42', $nested, 1700000002));
$unchangedAfterDepth = ($repo->find('repo:42') === $after);
$db->exec("INSERT INTO controlbot_decisions VALUES ('invalid', 'not-json', 2)");
$invalidStored = rejects(fn()=> $repo->find('invalid'));
$db->exec("UPDATE controlbot_decisions SET payload_json = '[]' WHERE decision_id='invalid'");
$invalidStoredList = rejects(fn()=> $repo->find('invalid'));
$db->exec("DELETE FROM controlbot_decisions WHERE decision_id='invalid'");
$db->exec("CREATE TRIGGER deny_insert BEFORE INSERT ON controlbot_decisions BEGIN SELECT RAISE(ABORT, 'blocked'); END");
try { $repo->save('repo:42', ['choice'=>'C'], 1700000002); $rolledBack = false; }
catch (PDOException $e) { $rolledBack = ($repo->find('repo:42') === $after); }
$db->exec('DROP TRIGGER deny_insert');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
$invalidMode = rejects(fn()=> new PdoDecisionRepository($db));
$invalidMigrator = rejects(fn()=> SchemaMigrator::apply($db));
echo json_encode([
 'before'=>$before, 'after'=>$after, 'missing'=>$missing,
 'count'=>$db->query('SELECT COUNT(*) FROM controlbot_decisions')->fetchColumn(),
 'invalid_id'=>$invalidId, 'invalid_read_id'=>$invalidReadId,
 'invalid_json'=>$invalidJson, 'empty'=>$empty, 'list'=>$list,
 'invalid_time'=>$invalidTime, 'large'=>$large,
 'invalid_depth'=>$invalidDepth, 'unchanged_after_depth'=>$unchangedAfterDepth,
 'invalid_stored'=>$invalidStored, 'invalid_stored_list'=>$invalidStoredList,
 'rolled_back'=>$rolledBack, 'invalid_mode'=>$invalidMode,
 'invalid_migrator'=>$invalidMigrator
], JSON_THROW_ON_ERROR);
'''


class PersistenciaBaseTests(unittest.TestCase):
    def test_acceptance(self):
        with tempfile.TemporaryDirectory(prefix="controlbot-persistence-") as temp:
            db_path = Path(temp) / "decisions.sqlite"
            scenario_path = Path(temp) / "persistence_fixture.php"
            scenario_path.write_text(SCENARIO, encoding="utf-8")
            sql = MIGRATION.read_text(encoding="utf-8")
            with sqlite3.connect(db_path) as conn:
                conn.executescript(sql)
                conn.executescript(sql)
                self.assertEqual(
                    conn.execute("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='controlbot_decisions'").fetchone()[0], 1,
                )
            for path in (REPOSITORY, MIGRATOR, scenario_path):
                syntax = subprocess.run(["php", "-l", str(path)], cwd=ROOT, capture_output=True, text=True, check=False)
                self.assertEqual(syntax.returncode, 0, syntax.stderr)

            run = subprocess.run(
                ["php", str(scenario_path), str(db_path), str(REPOSITORY), str(MIGRATOR)],
                cwd=ROOT, capture_output=True, text=True, check=False, timeout=25,
            )
            self.assertEqual(run.returncode, 0, run.stderr)
            value = json.loads(run.stdout)
            self.assertEqual(value["before"], {"choice": "B", "state": "pending"})
            self.assertEqual(value["after"], {"choice": "A", "state": "approved"})
            self.assertIsNone(value["missing"])
            self.assertEqual(int(value["count"]), 1)
            for field in (
                "invalid_id", "invalid_read_id", "invalid_json", "empty", "list",
                "invalid_time", "large", "invalid_depth", "unchanged_after_depth",
                "invalid_stored", "invalid_stored_list",
                "rolled_back", "invalid_mode", "invalid_migrator",
            ):
                self.assertTrue(value[field], field)


if __name__ == "__main__":
    unittest.main()
