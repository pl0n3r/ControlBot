#!/usr/bin/env python3
"""#1188: executable offline evidence for CB-0031..CB-0049.

Calls actual PHP implementations using synthetic fixtures. The excluded rows
are C (declarative/live-only boundary), not certified operational evidence.
No matrix assertions, network calls, credentials or production changes.
"""
from __future__ import annotations

from functools import lru_cache
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCENARIOS = {
    "aegis": "aegis_remediation_scenarios.php",
    "core": "agent_replay_core_scenarios.php",
    "cycle": "agent_replay_lifecycle_scenarios.php",
    "recovery": "autofactory_recovery_rollout_scenarios.php",
}
A = frozenset({
    "CB-0031", "CB-0032", "CB-0033", "CB-0034", "CB-0035", "CB-0036",
    "CB-0037", "CB-0038", "CB-0039", "CB-0040", "CB-0042", "CB-0043",
    "CB-0044", "CB-0045", "CB-0046", "CB-0047",
})
C = {
    "CB-0041": "Declarative boundary: no actual ingestion, persistence, UI, DB, actions or network",
    "CB-0048": "Live backup, offsite, immutability, restore and observed RPO/RTO have no canonical receipts here",
    "CB-0049": "Future Recovery Health/Readiness requires verified live recovery evidence",
}


@lru_cache(maxsize=None)
def scenario(component: str, name: str = "") -> dict:
    php = shutil.which("php")
    path = ROOT / "tests" / SCENARIOS[component]
    if php is None or not path.is_file():
        raise AssertionError("UNKNOWN: real PHP scenario unavailable")
    cmd = [php, "-d", "allow_url_fopen=0", str(path)]
    if name:
        cmd.append(name)
    result = subprocess.run(cmd, cwd=ROOT, text=True, capture_output=True,
                            timeout=35, check=False)
    if result.returncode:
        raise AssertionError("UNKNOWN: actual PHP failed: " + result.stderr[-800:])
    try:
        data = json.loads(result.stdout)
    except json.JSONDecodeError as exc:
        raise AssertionError("UNKNOWN: actual PHP output is not JSON") from exc
    if not isinstance(data, dict):
        raise AssertionError("UNKNOWN: PHP output is not an object")
    return data


# Actual classes, not mocks; only synthetic event/profile inputs.
PHP_NEGATIVE = r"""
require $argv[1].'/src/AgentReplayCore.php';
require $argv[1].'/src/AgentReplayLifecycle.php';
require $argv[1].'/src/InfrastructureProvider.php';
require $argv[1].'/src/RecoveryProfile.php';
use ControlBot\Replay\AgentReplayCore;
use ControlBot\Replay\AgentReplayLifecycle;
use ControlBot\Infrastructure\RecoveryProfile;
$reject=static function(callable $fn):bool {
    try { $fn(); return false; } catch (\InvalidArgumentException) { return true; }
};
$event=[
 'event_id'=>'evt-synthetic-01','occurred_at'=>1000,'observed_at'=>1001,'sequence'=>1,
 'source'=>'github_issue','kind'=>'requested','actor_type'=>'system',
 'actor_ref'=>'system:fixture','work_item_id'=>'controlbot-1188',
 'project_id'=>'controlbot','repo'=>'pl0n3r/ControlBot','issue_number'=>1188,
 'pr_number'=>null,'commit_sha'=>str_repeat('a',40),
 'execution_order_id'=>null,'execution_event_id'=>null,
 'summary'=>'Synthetic safe event','evidence_ref'=>'controlbot:evidence/fixture',
 'payload_digest'=>str_repeat('1',64),
];
$entry=['stage'=>'issue','event'=>$event];
$foreign=$entry;$foreign['event']['work_item_id']='controlbot-foreign';
$badStage=$entry;$badStage['stage']='commit';
$duplicated=$entry;$duplicated['stage']='documental';
$profile=json_decode(file_get_contents($argv[1].'/config/recovery/autofactory.json'),
                     true,512,JSON_THROW_ON_ERROR);
$empty=$profile;$empty['sources']=[
 'database'=>'not_applicable','media'=>'not_applicable',
 'repository'=>'not_applicable','configuration'=>'not_applicable',
];
$badRpo=$profile;$badRpo['targets']['rpo_minutes']=-1;
$badRetention=$profile;$badRetention['retention']['hourly']=-1;
$kinds=['cancelled','blocked','waiting_human','unknown'];$events=[];
foreach($kinds as $idx=>$kind) {
 $e=$event;$e['event_id']='evt-state-'.$idx;$e['kind']=$kind;
 $e['sequence']=$idx;$e['payload_digest']=hash('sha256',$kind);$events[]=$e;
}
echo json_encode([
 'literal_kinds'=>AgentReplayCore::build($events),
 'valid_lifecycle'=>AgentReplayLifecycle::build([$entry]),
 'cross_work_denied'=>$reject(fn()=>AgentReplayLifecycle::build([$entry,$foreign])),
 'bad_stage_denied'=>$reject(fn()=>AgentReplayLifecycle::build([$badStage])),
 'two_stages_denied'=>$reject(fn()=>AgentReplayLifecycle::build([$entry,$duplicated])),
 'empty_sources_denied'=>$reject(fn()=>RecoveryProfile::normalize($empty)),
 'bad_rpo_denied'=>$reject(fn()=>RecoveryProfile::normalize($badRpo)),
 'bad_retention_denied'=>$reject(fn()=>RecoveryProfile::normalize($badRetention)),
],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),"\n";
"""


@lru_cache(maxsize=1)
def negative_probe() -> dict:
    php = shutil.which("php")
    if not php:
        raise AssertionError("UNKNOWN: PHP missing")
    run = subprocess.run(
        [php, "-d", "allow_url_fopen=0", "-r", PHP_NEGATIVE, str(ROOT)],
        cwd=ROOT, capture_output=True, text=True, check=False, timeout=35,
    )
    if run.returncode:
        raise AssertionError("UNKNOWN: real negative probe failed: " + run.stderr[-1000:])
    return json.loads(run.stdout)


class CoberturaControlbot05Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        self.assertEqual(len(A), 16)
        a = scenario("aegis", "auto")
        authority = scenario("aegis", "authority")
        risk = scenario("aegis", "high_risk")
        idem = scenario("aegis", "idempotency")
        verified = scenario("aegis", "verification")
        # CB-0031: read-only planning never means action was performed.
        self.assertEqual(a["remediation_state"], "not_verified")
        self.assertEqual(a["verification"]["status"], "not_attempted")
        # CB-0032: no escalation of DecisionRights authority/capability.
        self.assertEqual(a["authority"]["decision"], "allow")
        self.assertEqual(authority["insufficient"]["authority"]["decision"],
                         "owner_decision_required")
        # CB-0033: policy binding must be active and match the grant.
        self.assertEqual(authority["unknown_policy"]["decision"], "deny")
        self.assertEqual(authority["policy_mismatch"]["decision"], "deny")
        # CB-0034: scope shared by grant, verified identity and proposal.
        self.assertEqual(authority["group_scope"]["scope"], "group:pl0n3r")
        self.assertEqual(authority["group_scope"]["decision"], "auto_eligible")
        # CB-0035: auto requires preauthorization, reversible, low blast radius.
        self.assertTrue(a["auto_eligible"])
        self.assertTrue(a["proposal"]["preauthorized"] and a["proposal"]["reversible"])
        self.assertEqual(a["proposal"]["blast_radius"], "low")
        self.assertTrue(all(p["decision"] == "owner_decision_required" for p in risk.values()))
        # CB-0036: L0 only; higher authority or unsafe risk needs owner.
        self.assertEqual(a["proposal"]["required_authority_level"], "L0_AI_AUTONOMOUS")
        self.assertFalse(authority["insufficient"]["auto_eligible"])
        # CB-0037: SHA-256 stable idempotency key and actual batch dedupe.
        self.assertRegex(a["idempotency_key"], r"^aegis-remediation:[a-f0-9]{64}$")
        self.assertEqual(idem["first"]["idempotency_key"], idem["second"]["idempotency_key"])
        self.assertNotEqual(idem["first"]["idempotency_key"], idem["different"]["idempotency_key"])
        self.assertEqual(len(idem["batch"]), 2)
        # CB-0038: success only after verified with actual evidence.
        self.assertEqual(verified["pending"]["remediation_state"], "not_verified")
        self.assertEqual(verified["verified"]["remediation_state"], "success")
        self.assertTrue(verified["verified"]["verification"]["evidence_refs"])
        # CB-0039: literal CI states, including startup_failure and waiting_human.
        states = scenario("core", "states")
        self.assertEqual([x["kind"] for x in states["events"]],
                         ["success", "failure", "skipped", "startup_failure"])
        self.assertEqual([x["kind"] for x in negative_probe()["literal_kinds"]["events"]],
                         ["cancelled", "blocked", "waiting_human", "unknown"])
        # CB-0040: stable event IDs dedupe; conflicting variants are UNKNOWN.
        stable = scenario("core", "stable")
        conflict = scenario("core", "conflict")
        self.assertEqual(stable["first"], stable["second"])
        self.assertEqual(len(stable["first"]["events"]), 2)
        self.assertEqual(conflict["conflicts"][0]["state"], "unknown")
        self.assertEqual(conflict["conflicts"][0]["variant_count"], 2)
        # CB-0042: stage source is checked by actual lifecycle code.
        self.assertTrue(negative_probe()["bad_stage_denied"])
        # CB-0043: fixed lifecycle stages; missing stage not fabricated.
        lifecycle = scenario("cycle", "complete")
        self.assertEqual([x["name"] for x in lifecycle["stages"]],
                         ["issue", "reservation", "plan", "commit", "review",
                          "check", "merge", "deploy"])
        self.assertEqual(scenario("cycle", "missing")["missing"],
                         ["reservation", "review", "check", "merge", "deploy"])
        # CB-0044: one work ID; one event variant cannot span two stages.
        self.assertTrue(negative_probe()["cross_work_denied"])
        self.assertTrue(negative_probe()["two_stages_denied"])
        # CB-0045: genuine #78 success/startup_failure and #86 skipped.
        c78 = scenario("cycle", "incident78")
        self.assertEqual([e["kind"] for s in c78["stages"]
                          if s["name"] == "check" for e in s["events"]],
                         ["success", "startup_failure"])
        c86 = scenario("cycle", "pr86")
        self.assertEqual([e["kind"] for s in c86["stages"]
                          if s["name"] == "check" for e in s["events"]], ["skipped"])
        # CB-0046: real RecoveryProfile normalizer enforces declared targets.
        profile = scenario("recovery")["profile"]
        self.assertEqual(profile["targets"], {"rpo_minutes": 15, "rto_minutes": 60})
        self.assertEqual(profile["retention"], {"hourly": 24, "daily": 7, "weekly": 8, "monthly": 12})
        self.assertEqual(profile["restore_drill_cadence_hours"], 168)
        self.assertTrue(profile["encryption_required"])
        self.assertEqual(profile["strategy"],
                         {"copies_required": 3, "media_types_required": 2,
                          "offsite_required": True, "immutable_required": True,
                          "undetected_restore_failures_target": 0})
        # CB-0047: repository/configuration only are required.
        self.assertEqual(profile["sources"], {"repository": "required",
                         "configuration": "required", "database": "not_applicable",
                         "media": "not_applicable"})

    def test_falla_cerrado(self) -> None:
        self.assertTrue(scenario("aegis", "authority")["short_scope_rejected"])
        self.assertTrue(scenario("aegis", "verification")["verified_without_evidence_rejected"])
        self.assertFalse(scenario("aegis", "authority")["unknown_policy"]["auto_eligible"])
        self.assertTrue(all(scenario("core", "sensitive")["rejected"]))
        probe = negative_probe()
        for key in ("cross_work_denied", "bad_stage_denied", "two_stages_denied",
                    "empty_sources_denied", "bad_rpo_denied", "bad_retention_denied"):
            with self.subTest(negative=key):
                self.assertIs(probe[key], True)
        self.assertEqual(probe["valid_lifecycle"]["missing"],
                         ["reservation", "plan", "commit", "review", "check", "merge", "deploy"])

    def test_sin_datos_reales(self) -> None:
        self.assertEqual(set(C), {"CB-0041", "CB-0048", "CB-0049"})
        self.assertEqual(A | set(C), {f"CB-{n:04d}" for n in range(31, 50)})
        for script in SCENARIOS.values():
            self.assertTrue((ROOT / "tests" / script).is_file())
        self.assertEqual(scenario("recovery")["profile_status"], "configured")
        self.assertEqual(scenario("recovery")["recovery"]["dr_status"], "UNKNOWN")
        self.assertFalse(scenario("recovery")["recovery"]["execution"])
        self.assertIsNone(scenario("recovery")["recovery"]["observed_rpo_minutes"])
        self.assertEqual(scenario("aegis", "no_provider")["remediation_state"], "not_verified")

    def test_estados_unknown_explicitos(self) -> None:
        self.assertEqual(scenario("aegis", "verification")["pending"]["remediation_state"],
                         "not_verified")
        self.assertEqual(scenario("cycle", "missing")["missing"],
                         ["reservation", "review", "check", "merge", "deploy"])
        evidence = scenario("recovery")["recovery"]
        for name in ("canonical_backup_receipt", "offsite_copy",
                     "immutable_copy", "canonical_restore_drill"):
            self.assertEqual(evidence[name], "unknown")
        self.assertEqual(evidence["dr_status"], "UNKNOWN")
        self.assertIsNone(evidence["observed_rpo_minutes"])
        self.assertIsNone(evidence["demonstrated_rto_minutes"])
        self.assertIs(evidence["restorable"], False)


if __name__ == "__main__":
    unittest.main()
