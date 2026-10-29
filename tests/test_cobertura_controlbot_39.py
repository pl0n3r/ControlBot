"""ControlBot #1222: real PHP regression for 18 CB-0625..0645 rows.

CB-0634 and CB-0640 are C: out-of-scope provider/cron operations and a
legal disclaimer, not executable local functions. All scenarios use synthetic
fixtures; no live provider, secrets, personal data or inferred readiness.
"""
from __future__ import annotations

import json
import os
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCENARIOS = {
    "observation": "tests/infrastructure_observation_scenarios.php",
    "ui": "tests/infrastructure_ui_scenarios.php",
    "lex": "tests/lex_core_scenarios.php",
}
CLASSIFIED_C = {
    "CB-0634": "collectors, cron, provider writes and authority are explicitly outside read-only domain",
    "CB-0640": "legal disclaimer and architectural domain purpose are not legal approval behavior",
}
EXECUTABLE = {
    "CB-0625", "CB-0626", "CB-0627", "CB-0628", "CB-0629",
    "CB-0630", "CB-0631", "CB-0632", "CB-0633",
    "CB-0636", "CB-0637", "CB-0638", "CB-0639",
    "CB-0641", "CB-0642", "CB-0643", "CB-0644", "CB-0645",
}
PHP_STATES = r"""
require 'src/InfrastructureProvider.php';
require 'src/InfrastructureObservation.php';
$base = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$states = [];
foreach (['online','degraded','offline','maintenance','unknown'] as $state) {
  $row = $base; $row['state'] = $state;
  $normal = \ControlBot\Infrastructure\InfrastructureObservation::normalize($row);
  $states[$state] = [$normal['state'],
    \ControlBot\Infrastructure\InfrastructureObservation::effectiveState($row)];
}
$freshness = [];
foreach (['fresh','stale','unknown'] as $state) {
  $row = $base; $row['freshness'] = $state;
  $freshness[$state] = \ControlBot\Infrastructure\InfrastructureObservation::effectiveState($row);
}
$denied = [];
foreach (['invalid_state','sensitive_source','missing_source'] as $case) {
  $row = $base;
  if ($case === 'invalid_state') $row['state'] = 'healthy';
  if ($case === 'sensitive_source') $row['source_ref'] = 'controlbot:secret-token';
  if ($case === 'missing_source') unset($row['source_ref']);
  try { \ControlBot\Infrastructure\InfrastructureObservation::normalize($row);
        $denied[$case] = false;
  } catch (\InvalidArgumentException $error) { $denied[$case] = true; }
}
echo json_encode(['states'=>$states,'freshness'=>$freshness,'denied'=>$denied],
                 JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
"""


def php(args: list[str], payload: dict | None = None) -> dict:
    binary = shutil.which("php")
    if not binary:
        raise AssertionError("UNKNOWN: PHP CLI unavailable")
    # Preserve only the nonsecret PHP coverage runner contract when CI prepends
    # its instrumented php wrapper to PATH; never forward arbitrary credentials.
    env_keys = (
        "PATH", "LANG", "LC_ALL", "CONTROLBOT_REAL_PHP",
        "CONTROLBOT_PHP_COVERAGE_BOOTSTRAP", "CONTROLBOT_PHP_COVERAGE_DIR",
        "CONTROLBOT_REPO_ROOT", "XDEBUG_MODE",
    )
    env = {key: os.environ[key] for key in env_keys if key in os.environ}
    result = subprocess.run(
        [binary, *args], cwd=ROOT, env=env, timeout=30,
        input=json.dumps(payload) if payload is not None else None,
        capture_output=True, text=True, check=False,
    )
    if result.returncode:
        raise AssertionError("UNKNOWN: real PHP case failed: " + result.stderr[-350:])
    try:
        return json.loads(result.stdout)
    except json.JSONDecodeError as exc:
        raise AssertionError("UNKNOWN: invalid PHP JSON output") from exc


def scenario(area: str, case: str) -> dict:
    path = ROOT / SCENARIOS[area]
    if not path.is_file():
        raise AssertionError("UNKNOWN: required scenario absent")
    return php([str(path), case])


def probe() -> dict:
    unknown = {"state": "unknown", "source_ref": None, "observed_at": None}
    observation = {
        "version": 1, "resource_id": "service-web", "state": "online",
        "source_ref": "controlbot:observation/service-web-2000",
        "observed_at": 2000, "freshness": "fresh",
        "backup_freshness": unknown.copy(), "restore_verification": unknown.copy(),
        "cost_attribution": {**unknown, "cost_ref": None},
        "release_drift": {**unknown, "expected_sha": None, "observed_sha": None},
        "incident_refs": [],
    }
    for file in ("InfrastructureProvider.php", "InfrastructureObservation.php"):
        if not (ROOT / "src" / file).is_file():
            raise AssertionError("UNKNOWN: source missing")
    return php(["-d", "allow_url_fopen=0", "-r", PHP_STATES], observation)


class CoberturaControlbot39Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        self.assertEqual(20, len(EXECUTABLE | CLASSIFIED_C.keys()))
        self.assertTrue(EXECUTABLE.isdisjoint(CLASSIFIED_C))

        # CB-0625/0626: both enum families, exercised in real PHP.
        states = probe()
        for state in ("online", "degraded", "offline", "maintenance", "unknown"):
            self.assertEqual([state, state], states["states"][state])
        self.assertEqual({"fresh": "online", "stale": "unknown",
                          "unknown": "unknown"}, states["freshness"])

        # CB-0627/0629: only fresh observations can keep their observed state.
        fresh = scenario("observation", "freshness")
        self.assertEqual("fresh", fresh["fresh"]["freshness"])
        self.assertEqual("online", fresh["fresh_effective"])
        for key in ("stale_effective", "unknown_effective", "absent_effective"):
            self.assertEqual("unknown", fresh[key])

        # CB-0628: restore is not inferred from backup or cost/release.
        backup = scenario("observation", "recovery-signals")
        self.assertEqual("stale", backup["backup_freshness"]["state"])
        self.assertEqual("verified", backup["restore_verification"]["state"])
        self.assertNotEqual(backup["backup_freshness"]["source_ref"],
                            backup["restore_verification"]["source_ref"])
        signals = scenario("observation", "evidence-signals")
        for key, state in (("cost_attribution", "attributed"), ("release_drift", "drift")):
            self.assertEqual(state, signals["evidenced"][key]["state"])
            self.assertEqual("unknown", signals["unknown"][key]["state"])
        self.assertEqual("controlbot:cost/project-controlbot",
                         signals["evidenced"]["cost_attribution"]["cost_ref"])
        self.assertNotEqual(signals["evidenced"]["release_drift"]["expected_sha"],
                            signals["evidenced"]["release_drift"]["observed_sha"])

        # CB-0630: incident refs only; no fabricated severity or actions.
        ui = scenario("ui", "cockpit")
        views = {x["resource_id"]: x for x in ui["resources"]}
        database = views["database-primary"]
        self.assertEqual(["https://github.com/pl0n3r/ControlBot/issues/190"],
                         database["signals"]["incident_refs"])
        self.assertNotIn("incident_severity", database["signals"])
        self.assertEqual([], database["actions"])

        # CB-0631/0632: exact canonical graph, no foreign venture bindings.
        impact = scenario("observation", "impact")
        self.assertEqual(["controlbot:capability/commerce"],
                         impact["database"]["capability_refs"])
        self.assertNotIn("controlbot:capability/other-venture",
                         impact["database"]["capability_refs"])
        self.assertEqual("controlbot:venture/venture-platform",
                         impact["database"]["venture_ref"])
        self.assertEqual([], impact["other_resources"])
        self.assertIn("controlbot:resource/database-primary", impact["platform_resources"])

        # CB-0633: stable resource identity from real resource/impact projection.
        self.assertEqual("controlbot:resource/database-primary",
                         impact["graph"]["resource_impact"]["database-primary"]["resource_ref"])
        self.assertEqual("database", database["kind"])

        # CB-0636: raw/effective state, provenance and timestamps are distinct.
        observed = database["state"]
        self.assertEqual("degraded", observed["observed_state"])
        self.assertEqual("unknown", observed["effective_state"])
        self.assertEqual("stale", observed["freshness"])
        self.assertEqual("controlbot:observation/database-primary", observed["source_ref"])
        self.assertEqual(2000, observed["observed_at"])
        self.assertEqual("unknown", views["env-prod"]["state"]["effective_state"])
        self.assertIsNone(views["env-prod"]["state"]["source_ref"])

        # CB-0638: operational channels are projected independently.
        projected = database["signals"]
        for key, state in (("backup_freshness", "stale"),
                           ("restore_verification", "verified"),
                           ("cost_attribution", "attributed"),
                           ("release_drift", "drift")):
            self.assertEqual(state, projected[key]["state"])
        self.assertTrue(projected["incident_refs"])

        # CB-0639: caller cannot enable execution through UI.
        self.assertIs(ui["execution"], False)
        self.assertTrue(all(row["actions"] == [] for row in ui["resources"]))

        # CB-0641: four legally defined result states.
        legal = scenario("lex", "states")
        self.assertEqual({"compliant", "gap", "unknown", "not_applicable"},
                         {row["status"] for row in legal["obligations"]})
        self.assertEqual({"compliant": 1, "gap": 1, "unknown": 1,
                          "not_applicable": 1}, legal["summary"]["counts"])

        # CB-0642: stale/unknown/expired reported compliance becomes unknown.
        expiry = scenario("lex", "freshness")
        self.assertTrue(all(row["status"] == "unknown" for row in expiry["obligations"]))
        expired = next(row for row in expiry["obligations"]
                       if row["obligation_id"] == "ob-expired")
        self.assertEqual("stale", expired["freshness"])
        self.assertEqual("fresh", expired["reported_freshness"])

        # CB-0644: evidence references and human review are not discarded.
        entry = scenario("lex", "registry")["obligations"][0]
        self.assertEqual(["controlbot:lex/evidence/a", "controlbot:lex/evidence/b"],
                         entry["evidence_refs"])
        self.assertEqual("compliant", entry["status"])
        self.assertTrue(entry["human_review_required"])

    def test_falla_cerrado(self) -> None:
        self.assertEqual({"invalid_state": True, "sensitive_source": True,
                          "missing_source": True}, probe()["denied"])
        invalid = scenario("observation", "invalid")
        for case in (
            "sensitive_source", "duplicate_incident", "duplicate_binding",
            "dangling_service", "dangling_environment", "cross_scope_service_project",
            "cross_scope_service_venture", "cross_scope_service_environment",
            "cross_scope_environment_project", "cross_scope_environment_venture",
            "cross_scope_parent_project", "cross_scope_parent_venture",
            "ambiguous_environment_source",
        ):
            with self.subTest(invalid=case):
                self.assertIs(invalid[case], True)

        # CB-0637: duplicates/orphans, scope escape and caller actions deny.
        ui = scenario("ui", "invalid")
        for case in ("duplicate_observation", "orphan_observation",
                     "scalar_observation", "cross_scope", "caller_action"):
            self.assertIs(ui[case], True)

        # CB-0643/0644/0645: future timestamps, no evidence, secret refs.
        lex = scenario("lex", "invalid")
        self.assertIn("timestamps in the future", lex["future_observed"])
        self.assertIn("timestamps in the future", lex["future_reviewed"])
        self.assertIn("requires evidence", lex["compliant_without_evidence"])
        self.assertIn("requires justification", lex["not_applicable_without_justification"])
        self.assertIn("fields invalid", lex["extra"])
        self.assertIn("invalid", lex["sensitive"])
        signals = scenario("observation", "evidence-signals")
        self.assertIs(signals["cost_missing_evidence_blocked"], True)
        self.assertIs(signals["release_state_mismatch_blocked"], True)

    def test_sin_datos_reales(self) -> None:
        self.assertEqual(18, len(EXECUTABLE))
        self.assertEqual(2, len(CLASSIFIED_C))
        self.assertTrue(all(CLASSIFIED_C.values()))
        self.assertTrue(all((ROOT / x).is_file() for x in SCENARIOS.values()))
        self.assertEqual("unknown", scenario("observation", "freshness")["absent_effective"])
        states = {row["obligation_id"]: row["status"]
                  for row in scenario("lex", "states")["obligations"]}
        self.assertEqual("unknown", states["ob-unknown"])
        self.assertNotIn("execution", scenario("observation", "freshness"))

    def test_estados_unknown_explicitos(self) -> None:
        # AC-04: UNKNOWN never promoted to GREEN from missing or stale evidence.
        states = probe()
        self.assertEqual("unknown", states["freshness"]["stale"])
        self.assertEqual("unknown", states["freshness"]["unknown"])
        self.assertEqual("unknown", scenario("observation", "freshness")["absent_effective"])
        ui = {row["resource_id"]: row for row in scenario("ui", "cockpit")["resources"]}
        self.assertEqual("unknown", ui["database-primary"]["state"]["effective_state"])
        self.assertEqual("unknown", ui["env-prod"]["state"]["effective_state"])
        self.assertEqual("unknown", ui["service-web"]["signals"]["restore_verification"]["state"])
        for row in scenario("lex", "freshness")["obligations"]:
            self.assertEqual("unknown", row["status"])
            self.assertNotEqual("compliant", row["status"])


if __name__ == "__main__":
    unittest.main()
