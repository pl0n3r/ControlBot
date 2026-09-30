import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name):
    run = subprocess.run(
        ["php", str(ROOT / "tests" / "product_discovery_work_origin_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
        timeout=60,
    )
    if run.stderr.strip():
        raise AssertionError(run.stderr.strip())
    return json.loads(run.stdout)


class ProductDiscoveryWorkOriginTests(unittest.TestCase):
    def test_valid_build_materializes_factory_work_item_v1(self):
        data = scenario("valid")
        self.assertEqual(data["status"], "materialized")
        self.assertFalse(data["execution"])
        self.assertEqual(data["freshness"], "fresh")
        item = data["work_item"]
        self.assertEqual(item["work_id"], "work:discovery-alpha")
        self.assertEqual(item["origin_mode"], "automatic")
        self.assertEqual(item["origin_system"], "controlbot")
        self.assertEqual(item["producer_ref"], "controlbot:product-discovery")
        self.assertEqual(item["group_id"], "group-alpha")
        self.assertEqual(item["venture_id"], "venture-alpha")
        self.assertEqual(item["project_id"], "project-alpha")
        self.assertEqual(item["repository_ref"], "pl0n3r/ControlBot")
        self.assertEqual(item["work_type"], "product")
        self.assertEqual(item["observed_at"], "1970-01-01T00:03:20Z")
        self.assertNotIn("freshness", item)
        self.assertNotIn("budget_ref", item)
        self.assertNotIn("approval_ref", item)

    def test_non_build_stale_and_unknown_fail_closed(self):
        data = scenario("blocked")
        for name in ("iterate", "park", "stop", "research_more"):
            row = data[name]
            self.assertEqual(row["status"], "blocked")
            self.assertIsNone(row["work_item"])
            self.assertFalse(row["execution"])
            self.assertIn("decision_not_build", row["reasons"])
        self.assertEqual(data["stale"]["freshness"], "stale")
        self.assertIn("discovery_evidence_stale", data["stale"]["reasons"])
        self.assertIsNone(data["stale"]["work_item"])
        self.assertEqual(data["unknown"]["freshness"], "unknown")
        self.assertIn("discovery_evidence_unknown", data["unknown"]["reasons"])
        self.assertIsNone(data["unknown"]["work_item"])

    def test_authority_priority_roles_and_policy_are_explicit_inputs(self):
        data = scenario("explicit")
        base = data["base"]["work_item"]
        changed = data["changed"]["work_item"]
        self.assertEqual(base["authority_level"], "l2")
        self.assertEqual(base["priority_class"], "high")
        self.assertEqual(base["required_roles"], ["producto", "qa"])
        self.assertEqual(base["requested_capabilities"], ["product.discovery", "workitem.write"])
        self.assertEqual(base["policy_ref"], "controlbot:policy/product-discovery-v1")
        self.assertEqual(changed["authority_level"], "l3")
        self.assertEqual(changed["priority_class"], "medium")
        self.assertEqual(changed["required_roles"], ["arquitectura", "producto"])
        self.assertEqual(changed["requested_capabilities"], ["code.review", "product.discovery"])
        self.assertEqual(changed["policy_ref"], "controlbot:policy/product-discovery-review-v1")
        self.assertEqual(changed["claims"], ["claim:review-alpha", "claim:review-beta"])
        self.assertEqual(changed["depends_on"], ["work:alpha", "work:beta"])
        for item in (base, changed):
            self.assertNotIn("required_gates", item)
            self.assertNotIn("budget_ref", item)
            self.assertNotIn("approval_ref", item)

    def test_provenance_evidence_freshness_and_idempotency_are_deterministic(self):
        data = scenario("provenance")
        first = data["first"]
        second = data["second"]
        changed = data["changed"]
        self.assertEqual(first, second)
        self.assertEqual(first["freshness"], "fresh")
        self.assertEqual(first["provenance"]["freshness"], "fresh")
        self.assertEqual(first["provenance"]["observed_at"], "1970-01-01T00:03:20Z")
        evidence = first["work_item"]["evidence_refs"]
        self.assertEqual(evidence, sorted(set(evidence)))
        self.assertEqual(first["work_item"]["idempotency_key"], second["work_item"]["idempotency_key"])
        self.assertNotEqual(first["work_item"]["idempotency_key"], changed["work_item"]["idempotency_key"])
        self.assertNotEqual(first["provenance"]["decision_ref"], changed["provenance"]["decision_ref"])
        self.assertNotIn("freshness", first["work_item"])

    def test_cross_scope_extra_sensitive_and_duplicate_inputs_fail_closed(self):
        data = scenario("invalid")
        self.assertEqual(len(data), 7)
        for row in data.values():
            self.assertEqual(row["status"], "blocked")
            self.assertIsNone(row["work_item"])
            self.assertFalse(row["execution"])
            self.assertEqual(row["reasons"], ["invalid_input"])
            self.assertEqual(row["freshness"], "unknown")

    def test_no_parallel_scheduler_queue_dispatch_provider_or_side_effects(self):
        data = scenario("surface")
        self.assertEqual(data["valid"]["status"], "materialized")
        self.assertFalse(data["valid"]["execution"])
        source = data["source"].lower()
        for value in (
            "new pdo", "mysqli", "curl_", "http://", "https://", "file_put_contents",
            "fopen(", "shell_exec", "proc_open", "enqueue(", "dispatch(", "factoryrunner",
        ):
            self.assertNotIn(value, source)
        serialized = json.dumps(data["valid"]).lower()
        for value in ("password", "bearer ", "authorization", "@"):
            self.assertNotIn(value, serialized)


if __name__ == "__main__":
    unittest.main()
