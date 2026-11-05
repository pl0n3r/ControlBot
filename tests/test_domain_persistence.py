"""#1453: disposable SQLite migration + real PHP repositories, without live IO."""
from __future__ import annotations

import json
import sqlite3
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
M1 = ROOT / "migrations/001_decisions.sql"
M2 = ROOT / "migrations/002_projects_agents_intake.sql"
REPO = ROOT / "src/Persistence/ProjectRepository.php"

SCENARIO = r'''<?php
declare(strict_types=1);
foreach (array_slice($argv, 2) as $source) { require_once $source; }
use ControlBot\Persistence\DomainSchemaMigrator;
use ControlBot\Persistence\PdoProjectRepository;
use ControlBot\Persistence\PdoAgentRepository;
use ControlBot\Persistence\PdoAgentSessionRepository;
use ControlBot\Persistence\PdoIntakeRepository;
use ControlBot\Persistence\PdoPauseRepository;

$db = new PDO('sqlite:' . $argv[1], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$names = fn() => $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'controlbot_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$before = $names();
$plan = DomainSchemaMigrator::dryRun($db);
$afterDry = $names();
$run = '123e4567-e89b-42d3-a456-426614174000';
$scope = [
  'project'=>'controlbot', 'environment'=>'local-test',
  'resource'=>'database:local-test', 'run_id'=>$run,
  'issue'=>'pl0n3r/ControlBot#1453'
];
$now = time();
$format = fn(int $t): string => gmdate('Y-m-d\\TH:i:s\\Z', $t);
$receipt = [
 'version'=>1, 'receipt_id'=>'123e4567-e89b-42d3-a456-426614174001',
 'project'=>$scope['project'], 'environment'=>$scope['environment'],
 'resource'=>$scope['resource'], 'backup_type'=>'database_dump',
 'run_id'=>$scope['run_id'], 'issue'=>$scope['issue'],
 'created_at'=>$format($now-120), 'verified_at'=>$format($now-60),
 'status'=>'ready', 'artifact_ref'=>'offline-backup-20261105-001',
 'integrity'=>['algorithm'=>'sha256','digest'=>str_repeat('a',64)],
 'restore_capability'=>'validated', 'expires_at'=>$format($now+3600)
];
function rejects(callable $fn): bool {
    try { $fn(); return false; } catch (Throwable $e) { return true; }
}
$missingBackup = rejects(fn()=> DomainSchemaMigrator::apply($db, null, $scope, $now));
$notModifiedWithoutBackup = $names() === $before;
$badScope = [...$scope, 'resource'=>'database:another'];
$scopeMismatch = rejects(fn()=> DomainSchemaMigrator::apply($db, $receipt, $badScope, $now));
$stale = [...$receipt,
 'created_at'=>$format($now-100000), 'verified_at'=>$format($now-90000)];
$staleBackup = rejects(fn()=> DomainSchemaMigrator::apply($db, $stale, $scope, $now));
DomainSchemaMigrator::apply($db, $receipt, $scope, $now);
$afterApply = $names();
DomainSchemaMigrator::apply($db, $receipt, $scope, $now);
$idempotent = $names() === $afterApply;
$classes = [
 'projects'=>PdoProjectRepository::class,
 'agents'=>PdoAgentRepository::class,
 'sessions'=>PdoAgentSessionRepository::class,
 'intake'=>PdoIntakeRepository::class,
 'pause'=>PdoPauseRepository::class
];
$records = [];
foreach ($classes as $type=>$class) {
    $repo = new $class($db);
    $value = ['type'=>$type,'nested'=>['ok'=>true,'order'=>[1,2]]];
    $repo->save('tenant-a', 'entity:01', $value, $now);
    $first = $repo->find('tenant-a', 'entity:01');
    $isolated = $repo->find('tenant-b', 'entity:01') === null;
    $repo->save('tenant-b', 'entity:01', ['type'=>$type,'tenant'=>'b'], $now);
    $untouched = $repo->find('tenant-a','entity:01') === $value;
    $repo->save('tenant-a','entity:01',['type'=>$type,'updated'=>true], $now+1);
    $second = $repo->find('tenant-a','entity:01');
    $records[$type] = [
      'first'=>$first, 'second'=>$second, 'isolated'=>$isolated,
      'untouched'=>$untouched, 'other'=>$repo->find('tenant-b','entity:01')
    ];
}
$project = new PdoProjectRepository($db);
$badId = rejects(fn()=> $project->save('tenant-a','bad id', ['a'=>1],$now));
$badScopeId = rejects(fn()=> $project->find('bad scope','entity:01'));
$list = rejects(fn()=> $project->save('tenant-a','entity:01',['a','b'],$now));
$empty = rejects(fn()=> $project->save('tenant-a','entity:01',[],$now));
$time = rejects(fn()=> $project->save('tenant-a','entity:01',['a'=>1],0));
$nan = rejects(fn()=> $project->save('tenant-a','entity:01',['a'=>INF],$now));
$large = rejects(fn()=> $project->save('tenant-a','entity:01',['big'=>str_repeat('x',66000)],$now));
$previous = $project->find('tenant-a','entity:01');
$db->exec("CREATE TRIGGER deny_domain_insert BEFORE INSERT ON controlbot_projects BEGIN SELECT RAISE(ABORT, 'blocked'); END");
$rollback = rejects(fn()=> $project->save('tenant-a','entity:01',['changed'=>'unexpected'],$now+5))
    && $project->find('tenant-a','entity:01') === $previous;
$db->exec('DROP TRIGGER deny_domain_insert');
$db->exec("INSERT INTO controlbot_projects VALUES ('tenant-a','corrupt','not-json',1)");
$corrupt = rejects(fn()=> $project->find('tenant-a','corrupt'));
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
$badMode = rejects(fn()=> new PdoProjectRepository($db));
$badMigratorMode = rejects(fn()=> DomainSchemaMigrator::dryRun($db));
echo json_encode([
 'before'=>$before, 'after_dry'=>$afterDry, 'after_apply'=>$afterApply,
 'plan'=>$plan, 'missing_backup'=>$missingBackup,
 'not_modified_without_backup'=>$notModifiedWithoutBackup,
 'scope_mismatch'=>$scopeMismatch, 'stale_backup'=>$staleBackup,
 'idempotent'=>$idempotent, 'records'=>$records,
 'bad_id'=>$badId, 'bad_scope'=>$badScopeId, 'list'=>$list,
 'empty'=>$empty, 'time'=>$time, 'nan'=>$nan, 'large'=>$large,
 'rollback'=>$rollback,'corrupt'=>$corrupt,
 'bad_mode'=>$badMode,'bad_migrator_mode'=>$badMigratorMode
], JSON_THROW_ON_ERROR);
'''

SOURCES = [
    ROOT / "src/CapabilityPolicy.php",
    ROOT / "src/BackupReceipt.php",
    ROOT / "src/BackupGate.php",
    ROOT / "src/Persistence/SchemaMigrator.php",
    REPO,
]


class PersistenceTests(unittest.TestCase):
    @staticmethod
    def _run(preexisting: bool) -> dict:
        with tempfile.TemporaryDirectory(prefix="cb1453-sqlite-") as directory:
            db = Path(directory) / "disposable.sqlite"
            fixture = Path(directory) / "domain_scenario.php"
            fixture.write_text(SCENARIO, encoding="utf-8")
            if preexisting:
                with sqlite3.connect(db) as conn:
                    conn.executescript(M1.read_text(encoding="utf-8"))
            result = subprocess.run(
                ["php", str(fixture), str(db), *(str(path) for path in SOURCES)],
                cwd=ROOT, capture_output=True, text=True, timeout=45, check=False
            )
            if result.returncode != 0:
                raise AssertionError("PHP scenario failed: " + result.stderr[-1800:])
            return json.loads(result.stdout)

    def test_criterio_principal(self):
        for file in (*SOURCES,):
            syntax = subprocess.run(
                ["php", "-l", str(file)], cwd=ROOT, capture_output=True,
                text=True, timeout=10, check=False
            )
            self.assertEqual(syntax.returncode, 0, syntax.stderr)
        for existing in (False, True):
            with self.subTest(preexisting_001=existing):
                with sqlite3.connect(":memory:") as conn:
                    conn.executescript(M2.read_text(encoding="utf-8"))
                    conn.executescript(M2.read_text(encoding="utf-8"))
                    tables = {r[0] for r in conn.execute(
                        "SELECT name FROM sqlite_master WHERE type='table'"
                    )}
                    self.assertTrue({
                        "controlbot_projects", "controlbot_agents",
                        "controlbot_agent_sessions", "controlbot_intake",
                        "controlbot_pause_state"
                    }.issubset(tables))
                result = self._run(existing)
                self.assertEqual(result["plan"]["version"], 2)
                self.assertEqual(len(result["plan"]["tables"]), 5)
                self.assertEqual(result["before"], result["after_dry"])
                self.assertEqual(len(result["after_apply"]), 6)
                self.assertTrue(result["idempotent"])
                self.assertEqual(len(result["records"]), 5)
                for name, record in result["records"].items():
                    self.assertEqual(record["first"]["type"], name)
                    self.assertEqual(record["second"], {"type": name, "updated": True})
                    self.assertTrue(record["isolated"])
                    self.assertTrue(record["untouched"])
                    self.assertEqual(record["other"], {"type": name, "tenant": "b"})

    def test_criterio_seguridad_y_bordes(self):
        result = self._run(preexisting=False)
        for key in (
            "missing_backup", "not_modified_without_backup",
            "scope_mismatch", "stale_backup", "bad_id", "bad_scope",
            "list", "empty", "time", "nan", "large", "rollback",
            "corrupt", "bad_mode", "bad_migrator_mode",
        ):
            with self.subTest(guard=key):
                self.assertTrue(result[key], key)
        sql = M2.read_text(encoding="utf-8")
        for forbidden in ("DROP TABLE", "ALTER TABLE", "TRUNCATE", "DELETE FROM"):
            self.assertNotIn(forbidden, sql.upper())
        for label in ("production", "live", "hostinger"):
            self.assertNotIn(label, SCENARIO.lower())
        self.assertEqual(
            sorted(p.name for p in SOURCES if not p.is_file()), [],
            "All tested dependencies must be checked in."
        )


if __name__ == "__main__":
    unittest.main()
