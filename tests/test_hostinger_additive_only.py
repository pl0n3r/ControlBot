"""ControlBot #1369 — offline additive-only Hostinger deployment policy."""
from __future__ import annotations

import json
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs" / "hostinger-additive-only.md"


def contract():
    text = DOC.read_text(encoding="utf-8")
    marker = re.search(r"\x60\x60\x60python\n(# BEGIN HOSTINGER_ADDITIVE_ONLY_CONTRACT.*?# END HOSTINGER_ADDITIVE_ONLY_CONTRACT)\n\x60\x60\x60", text, re.S)
    if marker is None:
        raise AssertionError("Falta el contrato offline de #1369")
    scope = {"__name__": "hostinger_additive_only_offline"}
    exec(compile(marker.group(1), str(DOC), "exec"), scope)
    return scope


CONTRACT = contract()
Simulator = CONTRACT["AdditiveOnlySimulator"]
fingerprint = CONTRACT["fingerprint"]
RUN = "aaaa1111-bbbb-4444-cccc-555555555555"


def permission(op, run_id=RUN, **overrides):
    data = {
        "approval_id": "synthetic-once-123456",
        "approved_by": "owner",
        "actor": "owner",
        "run_id": run_id,
        "fingerprint": fingerprint(op, run_id),
        "expires_at": 150,
        "decision": "GRANTED",
    }
    data.update(overrides)
    return data


class Fixture(unittest.TestCase):
    def setUp(self):
        self.records = []
        self.backup_ok = True
        self.gate = Simulator(
            lambda run, evidence: self.backup_ok and run == RUN and evidence == "B2_FIXTURE_VERIFIED",
            self.records.append,
            files={"public/index.php": "original"},
            tables={"items": ["test-row"]},
        )

    def dispatch(self, operation, approval=None, run=RUN, evidence="B2_FIXTURE_VERIFIED", actor="owner"):
        return self.gate.dispatch(actor, operation, run, evidence, approval)

    def test_criterio_principal(self):
        add = {"kind": "create", "target": "public/new.php", "content": "fixture-content"}
        self.assertTrue(self.dispatch(add)["ok"])
        self.assertEqual("fixture-content", self.gate.files["public/new.php"])
        self.assertEqual("overwrite_requires_explicit_grant", self.dispatch(add)["reason"])
        remove = {"kind": "remove", "target": "public/index.php"}
        self.assertEqual("one_time_owner_approval_required", self.dispatch(remove)["reason"])
        approval = permission(remove)
        self.assertTrue(self.dispatch(remove, approval)["ok"])
        self.assertNotIn("public/index.php", self.gate.files)
        self.assertEqual("target_missing", self.dispatch(remove, approval)["reason"])
        self.assertFalse(self.gate.runtime_authorized)
        self.assertFalse(self.gate.deploy_enabled)
        self.assertFalse(self.gate.production_verified)
        self.assertTrue(all(set(row) == {"actor", "kind", "target", "run_id", "status", "reason"} for row in self.records))

    def test_raw_shell_sql_and_equivalents_never_execute(self):
        for cmd in ("rm -rf public", "DROP TABLE items", "TRUNCATE TABLE items",
                    "DELETE FROM items", "mv /tmp/new public", "chmod -R 777 public"):
            with self.subTest(cmd=cmd):
                raw = {"kind": "command", "target": cmd}
                self.assertEqual("invalid_operation", self.dispatch(raw, permission(raw))["reason"])
        self.assertEqual(["test-row"], self.gate.tables["items"])
        self.assertEqual("original", self.gate.files["public/index.php"])

    def test_overwrite_and_sql_actions_need_bound_one_time_grant(self):
        for op in (
            {"kind": "overwrite", "target": "public/index.php", "content": "changed"},
            {"kind": "truncate_table", "target": "items"},
        ):
            with self.subTest(op=op["kind"]):
                self.assertEqual("one_time_owner_approval_required", self.dispatch(op)["reason"])
                self.assertEqual("one_time_owner_approval_required", self.dispatch(op, permission(op, fingerprint="f" * 64))["reason"])
                grant = permission(op, approval_id="synthetic-once-" + op["kind"] + "-123456")
                self.assertTrue(self.dispatch(op, grant)["ok"])
                self.assertEqual("one_time_owner_approval_required", self.dispatch(op, grant)["reason"])
        self.assertEqual("changed", self.gate.files["public/index.php"])
        self.assertEqual([], self.gate.tables["items"])

    def test_single_use_scope_expiry_role_and_actor(self):
        op = {"kind": "remove", "target": "public/index.php"}
        for kwargs in (
            {"approved_by": "agent"}, {"actor": "operator"}, {"run_id": "another-run-id"},
            {"decision": "PENDING"}, {"expires_at": 100}, {"expires_at": 500},
            {"approval_id": "bad"}, {"fingerprint": "bad"},
        ):
            with self.subTest(**kwargs):
                self.assertEqual("one_time_owner_approval_required", self.dispatch(op, permission(op, **kwargs))["reason"])
        self.assertEqual("owner_authority_missing", self.dispatch(op, permission(op), actor="agent")["reason"])
        self.assertEqual("backup_not_verified", self.dispatch(op, permission(op), run="wrong-run")["reason"])
        self.assertEqual("original", self.gate.files["public/index.php"])
        self.assertTrue(self.dispatch(op, permission(op))["ok"])
        # A second destructive mutation using the consumed approval is refused.
        self.gate.files["public/index.php"] = "again"
        self.assertEqual("one_time_owner_approval_required", self.dispatch(op, permission(op))["reason"])

    def test_backup_required_even_for_additive_creation(self):
        op = {"kind": "create", "target": "new.txt", "content": "test"}
        for evidence in (None, "UNKNOWN", "EXPIRED", {}, "B2_FIXTURE_FAKE"):
            with self.subTest(evidence=evidence):
                self.assertEqual("backup_not_verified", self.dispatch(op, evidence=evidence)["reason"])
        self.backup_ok = False
        self.assertEqual("backup_not_verified", self.dispatch(op)["reason"])
        self.assertNotIn("new.txt", self.gate.files)

    def test_invalid_paths_unknown_ops_and_unsafe_inputs(self):
        for path in ("/etc/passwd", "../escape", "public/../escape", "public//index.php",
                     "public\\index.php", "", ".", "safe/\x00secret"):
            op = {"kind": "create", "target": path, "content": "x"}
            with self.subTest(path=path):
                self.assertEqual("invalid_operation", self.dispatch(op)["reason"])
        for kind in ("delete", "DROP", "TRUNCATE", "sql", "shell", "rename", "mkdir", ""):
            self.assertEqual("invalid_operation", self.dispatch({"kind": kind, "target": "items"})["reason"])
        self.assertEqual("invalid_operation", self.dispatch({"kind": "create", "target": "huge", "content": "x" * 4097})["reason"])
        self.assertEqual("invalid_operation", self.dispatch({"kind": "drop_table", "target": "items;DROP TABLE others"})["reason"])

    def test_audit_fail_closed_and_payload_redacted(self):
        op = {"kind": "create", "target": "new", "content": "private-fixture-synthetic"}
        self.gate.audit = lambda _: (_ for _ in ()).throw(OSError("audit down"))
        self.assertEqual("audit_unavailable", self.dispatch(op)["reason"])
        self.assertNotIn("new", self.gate.files)
        self.gate.audit = self.records.append
        self.assertTrue(self.dispatch(op)["ok"])
        self.assertNotIn("private-fixture-synthetic", json.dumps(self.records))
        self.assertNotIn("synthetic-once-123456", json.dumps(self.records))

    def test_php_backup_gate_fixture_precedes_deploy_contract(self):
        from tests.test_deploy_backup_gate import fixture, probe
        ok = probe(fixture())
        self.assertEqual("SATISFIED", ok["synthetic_precondition"])
        self.assertFalse(ok["runtime_authorized"])
        self.assertFalse(ok["deploy_enabled"])
        self.assertFalse(ok["production_verified"])
        add = {"kind": "create", "target": "from-backup.txt", "content": "fake"}
        self.gate.backup_gate = lambda run, evidence: (run == RUN and evidence["synthetic_precondition"] == "SATISFIED"
            and evidence["restore"] == "OK" and evidence["checksum"] == "MATCH")
        self.assertTrue(self.dispatch(add, evidence=ok)["ok"])
        bad = fixture()
        bad["receipt"]["status"] = "pending"
        denied = probe(bad)
        self.assertEqual("DENIED", denied["synthetic_precondition"])
        self.assertEqual("backup_not_verified",
            self.dispatch({"kind": "create", "target": "denied.txt", "content": "fake"}, evidence=denied)["reason"])
        self.assertNotIn("denied.txt", self.gate.files)

    def test_docs_are_offline_only_and_no_network(self):
        text = DOC.read_text(encoding="utf-8")
        for token in ("D-059", "#1359", "Backblaze B2", "solo aditivos", "un solo uso",
                      "Hostinger-additive-onlyTests::test_criterio_principal", "runtime_authorized=false"):
            self.assertIn(token, text)
        block = text.split("# BEGIN HOSTINGER_ADDITIVE_ONLY_CONTRACT", 1)[1].split("# END HOSTINGER_ADDITIVE_ONLY_CONTRACT", 1)[0]
        for forbidden in ("subprocess", "socket.", "requests.", "paramiko.", "urllib.", "os.system", "os.remove"):
            self.assertNotIn(forbidden, block)


globals()["Hostinger-additive-onlyTests"] = type("Hostinger-additive-onlyTests", (Fixture,), {})

if __name__ == "__main__":
    unittest.main()
