#!/usr/bin/env python3
"""#1185: real offline behavior for ControlBot matrix CB-1130..CB-1144.

PHP scenarios invoke production classes using synthetic in-memory fixtures. This
suite never claims live health, an actual Hostinger backup or go-live.
"""
from __future__ import annotations

from functools import lru_cache
import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
ALL_IDS = {f"CB-{n:04d}" for n in range(1130, 1145)}
# Exactly these clauses describe external proof or non-executable normative text.
# Neither a source-document assertion nor a mock can promote them to CUBIERTO.
NON_OFFLINE = {
    "CB-1130": ("B", "Hostinger go-live requires actual verified D-059 backup and owner gate"),
    "CB-1132": ("B", "V3 observer and public-health producer lack authenticated live observations"),
    "CB-1136": ("C", "README's end-to-end delivery sequence is process guidance"),
    "CB-1139": ("C", "README points to normative authority/privacy manifests"),
    "CB-1140": ("C", "README editorial policy about evidence sources"),
    "CB-1141": ("C", "README architecture statement about independent products"),
}
OFFLINE = ALL_IDS - set(NON_OFFLINE)
SCENARIOS = {
    "github_intent_policy_scenarios.php": {"reuse", "trusted_closed"},
    "github_project_snapshot_scenarios.php": {"routes", "review-invalid"},
    "owner_session_scenarios.php": {"elevate", "wrong-owner", "csrf"},
    "backup_gate_blocks_mutations_scenarios.php": {"principal", "negatives", "privacy"},
    "github_workflow_evidence_scenarios.php": {"normal", "missing-ambiguous", "stale"},
    "gate_inbox_scenarios.php": {"pagination", "release"},
    "decision_runtime_scenarios.php": {"render", "venture-client-authority", "venture-no-writer"},
    "decision_history_scenarios.php": {"history", "secret"},
}

@lru_cache(maxsize=32)
def run_php(script: str, name: str) -> dict:
    if name not in SCENARIOS.get(script, set()):
        raise AssertionError("Unsupported synthetic test case")
    result = subprocess.run(
        ["php", str(ROOT / "tests" / script), name],
        cwd=ROOT, text=True, capture_output=True, check=False, timeout=30,
    )
    if result.returncode:
        raise AssertionError(f"{script}:{name}: {result.stderr[-500:]}")
    if result.stderr:
        raise AssertionError("Unexpected diagnostic output")
    value = json.loads(result.stdout)
    if not isinstance(value, (dict, list)):
        raise AssertionError("Invalid scenario evidence")
    return value


class CoberturaControlbot02Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """Each offline row asserts an effect of real PHP code, not a README quote."""
        demonstrated = set()

        # CB-1131: no execution from intent inference, even with a grant.
        intent = run_php("github_intent_policy_scenarios.php", "reuse")
        self.assertTrue(intent)
        self.assertTrue(all(row["decision"] == "deny" and row["execution"] is False
                            for row in intent.values()))
        demonstrated.add("CB-1131")

        # CB-1133: repository projection calls allowlisted GET endpoints only.
        projection = run_php("github_project_snapshot_scenarios.php", "routes")
        self.assertEqual(len(projection["calls"]), 16)
        for method, url, _headers, body in projection["calls"]:
            self.assertEqual(method, "GET")
            self.assertIsNone(body)
            self.assertTrue(url.startswith("https://api.github.com/repos/"))
        demonstrated.add("CB-1133")

        # CB-1134: only the authenticated server-side owner may enter.
        denied = run_php("owner_session_scenarios.php", "wrong-owner")
        self.assertTrue(denied["session_empty"])
        self.assertIn("no corresponde al dueño", denied["error"])
        demonstrated.add("CB-1134")

        # CB-1135/1137: every real infrastructure mutation has a backup gate.
        backup = run_php("backup_gate_blocks_mutations_scenarios.php", "principal")
        self.assertEqual(backup["protected"], backup["gate_types"])
        self.assertEqual(backup["unprotected"], [])
        self.assertGreaterEqual(len(backup["protected"]), 5)
        for capability in backup["protected"]:
            self.assertFalse(backup["checks"][capability]["missing"]["allowed"])
            self.assertEqual(backup["checks"][capability]["missing"]["reason"],
                             "backup_receipt_required")
        demonstrated.update(("CB-1135", "CB-1137"))

        # CB-1138: actual workflow evidence retains a concrete immutable SHA.
        workflows = run_php("github_workflow_evidence_scenarios.php", "normal")["evidence"]
        latest = workflows["items"][0]["latest_run"]
        self.assertEqual(latest["head_sha"], workflows["main_sha"])
        self.assertEqual(latest["conclusion"], "success")
        self.assertEqual(latest["evidence_state"], "COMPLETE")
        demonstrated.add("CB-1138")

        # CB-1142: server-side inbox follows bounded pagination, retaining SHA.
        inbox = run_php("gate_inbox_scenarios.php", "pagination")
        self.assertEqual([item["issue"] for item in inbox["decisions"]], [2201])
        self.assertTrue(any("page=2" in url for _, url in inbox["seen"]))
        release = run_php("gate_inbox_scenarios.php", "release")["decisions"][0]
        self.assertEqual(release["sha"], "a" * 40)
        self.assertEqual(release["ci"]["state"], "success")
        demonstrated.add("CB-1142")

        # CB-1143: the runtime injects CSRF from trusted session context.
        runtime = run_php("decision_runtime_scenarios.php", "render")
        self.assertEqual(runtime["response"]["status"], 200)
        self.assertIn('name="_csrf"', runtime["response"]["body"])
        self.assertTrue(any("/issues?state=open" in row[1] for row in runtime["seen"]))
        demonstrated.add("CB-1143")

        # CB-1144: append-only audit is exposed via safe server-side filtering.
        history = run_php("decision_history_scenarios.php", "history")
        self.assertEqual(len(history["all"]), 2)
        self.assertEqual(len(history["filtered"]), 1)
        self.assertEqual(history["all"][0]["actions"], ["comment", "dispatch-release"])
        demonstrated.add("CB-1144")

        self.assertEqual(demonstrated, OFFLINE)
        self.assertEqual(OFFLINE | set(NON_OFFLINE), ALL_IDS)
        self.assertTrue(all(kind in ("B", "C", "D") and reason
                            for kind, reason in NON_OFFLINE.values()))

    def test_falla_cerrado(self) -> None:
        """Missing evidence, stale SHA, insufficient permission and unsafe input deny."""
        denied = run_php("github_intent_policy_scenarios.php", "trusted_closed")
        self.assertEqual(denied["missing_grant"]["reasons"], ["grant_required"])
        self.assertEqual(denied["stale"]["reasons"], ["untrusted_or_stale_snapshot"])
        self.assertFalse(any(row["execution"] for row in denied.values()))
        snapshots = run_php("github_project_snapshot_scenarios.php", "review-invalid")
        self.assertEqual(snapshots["bad_review"]["items"][0]["review_state"], "unknown")
        self.assertTrue(snapshots["bad_review"]["truncated"])
        self.assertIn("Sesión del dueño inválida",
                      run_php("owner_session_scenarios.php", "elevate")["error"])
        self.assertIn("CSRF inválido",
                      run_php("owner_session_scenarios.php", "csrf")["error"])
        negatives = run_php("backup_gate_blocks_mutations_scenarios.php", "negatives")
        for capability, cases in negatives["checks"].items():
            for case in ("missing", "stale", "owner", "forbidden"):
                with self.subTest(capability=capability, case=case):
                    self.assertFalse(cases[case]["allowed"])
                    self.assertIsNone(cases[case]["evidence"])
        self.assertTrue(run_php("decision_runtime_scenarios.php", "venture-client-authority")
                        ["repo_blocked"])
        self.assertTrue(run_php("decision_runtime_scenarios.php", "venture-no-writer")
                        ["blocked"])
        stale = run_php("github_workflow_evidence_scenarios.php", "stale")
        self.assertEqual(stale["evidence"]["items"][0]["latest_run"]["evidence_state"], "UNKNOWN")

    def test_sin_datos_reales(self) -> None:
        """Synthetic fixtures neither grant live rights nor echo protected values."""
        private = run_php("backup_gate_blocks_mutations_scenarios.php", "privacy")
        self.assertEqual(private["mode"], "SYNTHETIC_ONLY")
        self.assertIs(private["production_executed"], False)
        self.assertIs(private["backup_verified"], False)
        for row in private["checks"].values():
            self.assertFalse(row["leaks_ref"])
            self.assertFalse(row["leaks_digest"])
        history = json.dumps(run_php("decision_history_scenarios.php", "secret"))
        for secret in ("owner-secret-value", "supersecret", "evil.example"):
            self.assertNotIn(secret, history)
        for path in ("README.md", "docs/matriz-cobertura.md", "docs/matriz-cobertura-parte-04.md"):
            self.assertTrue((ROOT / path).is_file())
        self.assertEqual(set(NON_OFFLINE) & OFFLINE, set())

    def test_validar_exact_head(self) -> None:
        """Exercise real workflow source/evidence parsing; GitHub CI is final authority."""
        evidence = run_php("github_workflow_evidence_scenarios.php", "normal")["evidence"]
        completed = evidence["items"][0]["latest_run"]
        self.assertEqual(completed["freshness"], "CURRENT")
        self.assertEqual(completed["head_sha"], evidence["main_sha"])
        mismatched = run_php("github_workflow_evidence_scenarios.php", "missing-ambiguous")
        states = [i["latest_run"]["freshness"] for i in mismatched["evidence"]["items"]]
        self.assertIn("MISSING", states)
        self.assertIn("AMBIGUOUS", states)
        workflow = (ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        self.assertIn("github.event.pull_request.head.sha", workflow)
        self.assertIn("name: Validar", workflow)


if __name__ == "__main__":
    unittest.main()
