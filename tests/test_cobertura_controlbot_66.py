"""ControlBot #1249: exercise real PHP vendor governance contracts offline.

The existing PHP scenario scripts invoke production PHP with synthetic opaque refs.
This suite deliberately never reads the coverage matrix or connects to services.
"""
import json
import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP_SCENARIOS = {
    "signal": "vendor_exception_signal_scenarios.php",
    "inbox": "vendor_owner_inbox_projection_scenarios.php",
    "registry": "vendor_registry_scenarios.php",
    "origin": "vendor_work_origin_scenarios.php",
}


def case(group, name):
    script = ROOT / "tests" / PHP_SCENARIOS[group]
    result = subprocess.run(
        ["php", str(script), name], cwd=ROOT,
        capture_output=True, text=True, check=False, timeout=30,
    )
    if result.returncode:
        raise AssertionError(
            f"Real PHP scenario {group}/{name} failed: {result.stderr[-1000:]}"
        )
    output = json.loads(result.stdout)
    if not isinstance(output, dict):
        raise AssertionError(f"Non-object from real PHP: {group}/{name}")
    return output


class CoberturaControlbot66Tests(unittest.TestCase):
    """CB-1090..CB-1107: real PHP behavior and negative authority boundaries."""

    def test_contrato_principal(self):
        sig = case("signal", "material")
        sig_unknown = case("signal", "unknown")
        sig_refs = case("signal", "provenance")
        bridge_material = case("inbox", "material")
        bridge_explicit = case("inbox", "explicit")
        bridge_refs = case("inbox", "provenance")
        bridge_freshness = case("inbox", "freshness")
        registry = case("registry", "scope")["valid"]
        private = case("registry", "privacy")
        states = case("registry", "states")
        origin_scope = case("origin", "scope")
        origin_explicit = case("origin", "explicit")
        origin_ids = case("origin", "idempotency")
        origin_limited = case("origin", "limited")
        origin_optional = case("origin", "optional")

        with self.subTest(row="CB-1090"):
            self.assertEqual(sig["at_boundary_types"], [])
            due = [s for s in sig["signals"] if s["type"] == "renewal_due"]
            self.assertEqual(len(due), 1)
            self.assertEqual(due[0]["deadline_at"], 2000)
            self.assertEqual(due[0]["renewal_at"], 2000)

        with self.subTest(row="CB-1091"):
            self.assertIn("missing_exit_plan", sig["types"])
            self.assertIn("missing_export_capability", sig["types"])
            self.assertFalse(
                {"missing_exit_plan", "missing_export_capability"}
                & {s["type"] for s in sig_refs["signals"]}
            )
            self.assertEqual(len(sig["types"]), len(set(sig["types"])))

        with self.subTest(row="CB-1092"):
            self.assertEqual(sig_unknown["unknown_types"], ["critical_unknown"])
            self.assertEqual(sig_unknown["stale_types"], ["critical_unknown"])
            for state in ("unknown", "stale"):
                item = sig_unknown[state][0]
                self.assertEqual(item["health"], "unknown")
                self.assertEqual(item["freshness"], state)
                self.assertNotIn("outage", item)
                self.assertNotIn("incident", item)

        with self.subTest(row="CB-1093"):
            self.assertTrue(sig_refs["signals"])
            for item in sig_refs["signals"]:
                self.assertEqual(item["venture_id"], "venture-alpha")
                self.assertRegex(item["vendor_id"], r"^vendor:[a-f0-9]{32}$")
                self.assertEqual(item["freshness"], "fresh")
                self.assertEqual(item["observed_at"], 1500)
                self.assertRegex(item["source_ref"], r"^evidence:[a-f0-9]{32}$")
                self.assertTrue(all(re.fullmatch(r"[a-z]+:[a-f0-9]{32}", x)
                                    for x in item["evidence_refs"]))
                self.assertNotIn("credentials_ref", item)
                self.assertNotIn("credential:" + "7" * 32, item["evidence_refs"])

        with self.subTest(row="CB-1094"):
            self.assertEqual(bridge_material["entry"]["class"], "watch")
            self.assertIs(bridge_material["fake"], True)
            self.assertNotIn("priority_class", bridge_material["entry"])

        with self.subTest(row="CB-1095"):
            watch = bridge_explicit["watch"]
            decision = bridge_explicit["decision"]
            self.assertEqual(watch["class"], "watch")
            self.assertIsNone(watch["required_authority_level"])
            self.assertIsNone(watch["decision_ref"])
            self.assertEqual(decision["class"], "decision")
            self.assertEqual(decision["required_authority_level"], "L4_OWNER")
            self.assertEqual(decision["decision_ref"], "controlbot:decision/vendor-review")
            self.assertEqual(watch["entry_ref"], decision["entry_ref"])

        with self.subTest(row="CB-1096"):
            entry = bridge_refs["entry"]
            self.assertEqual(entry["scope"], {
                "kind": "venture", "ref": "controlbot:venture/venture-alpha",
            })
            self.assertRegex(
                entry["source_ref"],
                r"^controlbot:vendor-exception/source/(?:h[a-f0-9]{2}){32}$",
            )
            self.assertTrue(entry["evidence_refs"])
            for ref in entry["evidence_refs"]:
                self.assertRegex(
                    ref,
                    r"^controlbot:(?:vendor-exception/signal|evidence/vendor)/(?:h[a-f0-9]{2}){32}$",
                )

        with self.subTest(row="CB-1097"):
            unknown = bridge_freshness["unknown"]
            self.assertEqual(unknown["freshness"], "unknown")
            self.assertIsNone(unknown["source_ref"])
            self.assertIsNone(unknown["observed_at"])
            self.assertEqual(unknown["evidence_refs"], [])
            self.assertEqual(bridge_freshness["stale"]["observed_at"], 1500)

        with self.subTest(row="CB-1098"):
            self.assertEqual(registry["cost"]["capital_ref"], "capital:" + "4" * 32)
            self.assertEqual(
                registry["security_review"]["aegis_review_ref"], "aegis:" + "b" * 32
            )
            self.assertFalse(
                {"authority_level", "budget_ref", "approval_ref", "decision_ref"}
                & registry.keys()
            )

        with self.subTest(row="CB-1099"):
            for field in (
                "vendor_id", "service_ref", "owner_ref", "contract_ref",
                "data_ref", "credentials_ref", "exit_plan_ref", "export_ref",
            ):
                self.assertRegex(private["valid"][field], r"^[a-z]+:[a-f0-9]{32}$")
            self.assertIs(private["human_rejected"], True)
            self.assertIs(private["credential_rejected"], True)

        with self.subTest(row="CB-1100"):
            self.assertEqual(states["stale"]["freshness"]["state"], "stale")
            self.assertEqual(states["unknown"]["health"], "unknown")
            self.assertIsNone(states["unknown"]["freshness"]["observed_at"])
            self.assertIsNone(states["unknown"]["freshness"]["source_ref"])
            self.assertIs(states["false_healthy_rejected"], True)
            self.assertIs(states["false_sla_rejected"], True)

        with self.subTest(row="CB-1101"):
            self.assertIsInstance(registry["renewal_at"], int)
            self.assertIsInstance(registry["expiry_at"], int)
            self.assertLessEqual(registry["renewal_at"], registry["expiry_at"])
            self.assertIs(states["bad_dates_rejected"], True)

        # CB-1102 is a class-C operational limit; verify the PHP public surface.
        with self.subTest(row="CB-1102"):
            self.assertEqual(case("registry", "surface")["public"], ["normalize"])
            self.assertFalse(
                {"work_id", "origin_mode", "ready", "approved_by"} & registry.keys()
            )

        with self.subTest(row="CB-1103"):
            first, second = origin_explicit["first"], origin_explicit["second"]
            self.assertEqual(first["work_id"], "work-vendor")
            self.assertEqual(first["group_id"], "pl0n3r-group")
            self.assertEqual(first["work_type"], "operations")
            self.assertEqual(first["priority_class"], "high")
            self.assertEqual(first["authority_level"], "operational")
            self.assertEqual(first["policy_ref"], "factory:constitution-v1")
            self.assertEqual(second["work_type"], "compliance_review")
            self.assertEqual(second["priority_class"], "medium")
            self.assertEqual(second["required_roles"], ["legal-privacidad"])

        with self.subTest(row="CB-1104"):
            for item in origin_scope.values():
                self.assertEqual(item["observed_at"], "2023-11-14T22:13:20Z")
            self.assertIs(origin_limited["unknown_rejected"], True)

        with self.subTest(row="CB-1105"):
            a, b = origin_ids["a"], origin_ids["b"]
            self.assertEqual(a["idempotency_key"], b["idempotency_key"])
            self.assertNotEqual(a["work_id"], b["work_id"])

        with self.subTest(row="CB-1106"):
            item = origin_limited["stale"]
            self.assertEqual(item["origin_mode"], "automatic")
            self.assertEqual(item["origin_system"], "controlbot")
            self.assertEqual(item["producer_ref"], "controlbot:vendor-governance")
            self.assertFalse(
                {"ready", "readiness", "approved", "authorized"} & item.keys()
            )

        with self.subTest(row="CB-1107"):
            item = origin_optional["good"]
            self.assertEqual(item["budget_ref"], "capital:vendor-budget")
            self.assertEqual(item["approval_ref"], "owner-decision:275")
            self.assertNotIn("authorized", item)
            self.assertNotIn("payment", item)

    def test_falla_cerrado(self):
        registry = case("registry", "scope")
        states = case("registry", "states")
        private = case("registry", "privacy")
        inbox = case("inbox", "classes")
        material = case("inbox", "material")
        limited = case("origin", "limited")
        optional = case("origin", "optional")
        pure = case("origin", "pure")
        rejects = {
            "CB-1094 invalid signal": material["fake"],
            "CB-1095 watch owner authority": inbox["watch_authority"],
            "CB-1095 decision missing": inbox["decision_missing"],
            "CB-1098 cost cross-venture": registry["cost_cross"],
            "CB-1098 review cross-venture": registry["security_cross"],
            "CB-1099 identity PII": private["human_rejected"],
            "CB-1099 nonopaque credential": private["credential_rejected"],
            "CB-1100 invalid healthy state": states["false_healthy_rejected"],
            "CB-1100 invalid healthy SLA": states["false_sla_rejected"],
            "CB-1101 dates reversed": states["bad_dates_rejected"],
            "CB-1103 execution field injected": optional["bad_execution_field"],
            "CB-1104 provenance missing": limited["unknown_rejected"],
            "CB-1106 invalid lifecycle": pure["active_lifecycle_rejected"],
        }
        for name, rejected in rejects.items():
            with self.subTest(case=name):
                self.assertIs(rejected, True)

    def test_sin_datos_reales(self):
        signals = case("signal", "provenance")["signals"]
        registry = case("registry", "privacy")["valid"]
        origin = case("origin", "optional")["good"]
        entry = case("inbox", "provenance")["entry"]
        for value in [*signals, registry, origin, entry]:
            with self.subTest(kind=value.get("type", "record")):
                payload = json.dumps(value, sort_keys=True).lower()
                for forbidden in (
                    "password", "api_key", "access_token", "http://",
                    "https://", "@example",
                ):
                    self.assertNotIn(forbidden, payload)
        self.assertNotIn("credential:" + "7" * 32, json.dumps(signals))
        self.assertNotIn("credentials_ref", entry)
        self.assertNotIn("credentials_ref", origin)
        self.assertRegex(registry["credentials_ref"], r"^credential:[a-f0-9]{32}$")
        for ref in entry["evidence_refs"]:
            self.assertRegex(
                ref,
                r"^controlbot:(?:vendor-exception/signal|evidence/vendor)/(?:h[a-f0-9]{2}){32}$",
            )


if __name__ == "__main__":
    unittest.main()
