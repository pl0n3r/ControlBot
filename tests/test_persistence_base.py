"""#808: SQLite disposable DB, versioned additive migration and PHP repository."""

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


class PersistenciaBaseTests(unittest.TestCase):
    def test_acceptance(self):
        # Database is temporary; no provider secrets, production data or migrations.
        with tempfile.TemporaryDirectory(prefix="controlbot-persistence-") as temp:
            db_path = Path(temp) / "decisions.sqlite"
            sql = MIGRATION.read_text(encoding="utf-8")
            with sqlite3.connect(db_path) as conn:
                conn.executescript(sql)
                conn.executescript(sql)
                self.assertEqual(
                    conn.execute("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='controlbot_decisions'").fetchone()[0],
                    1,
                )
            for path in (REPOSITORY, MIGRATOR):
                syntax = subprocess.run(["php", "-l", str(path)], cwd=ROOT, capture_output=True, text=True, check=False)
                self.assertEqual(syntax.returncode, 0, syntax.stderr)

            php = r'''require $argv[2]; require $argv[3];
use ControlBot\Persistence\PdoDecisionRepository;
use ControlBot\Persistence\SchemaMigrator;
$db = new PDO('sqlite:' . $argv[1], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
SchemaMigrator::apply($db); SchemaMigrator::apply($db);
$repo = new PdoDecisionRepository($db);
$repo->save('repo:42', ['choice'=>'B','state'=>'pending'], 1700000000);
$before = $repo->find('repo:42');
$repo->save('repo:42', ['choice'=>'A','state'=>'approved'], 1700000001);
$after = $repo->find('repo:42');
try { $repo->save('bad id', ['choice'=>'A'], 3); $bad = false; } catch (InvalidArgumentException $e) { $bad = true; }
try { $repo->save('repo:43', ['choice'=>INF], 3); $invalid = false; } catch (InvalidArgumentException $e) { $invalid = true; }
echo json_encode(['before'=>$before,'after'=>$after,'missing'=>$repo->find('missing'),'count'=>$db->query('SELECT COUNT(*) FROM controlbot_decisions')->fetchColumn(),'invalid_id'=>$bad,'invalid_json'=>$invalid], JSON_THROW_ON_ERROR);'''
            run = subprocess.run(
                ["php", "-r", php, str(db_path), str(REPOSITORY), str(MIGRATOR)],
                cwd=ROOT, capture_output=True, text=True, check=False, timeout=25,
            )
            self.assertEqual(run.returncode, 0, run.stderr)
            value = json.loads(run.stdout)
            self.assertEqual(value["before"], {"choice": "B", "state": "pending"})
            self.assertEqual(value["after"], {"choice": "A", "state": "approved"})
            self.assertIsNone(value["missing"])
            self.assertEqual(int(value["count"]), 1)
            self.assertTrue(value["invalid_id"])
            self.assertTrue(value["invalid_json"])


if __name__ == "__main__":
    unittest.main()
