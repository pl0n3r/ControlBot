#!/usr/bin/env python3
"""Issue #1079: synthetic fail-closed witnesses for CB-1102..CB-1116.

Only versioned local contracts and existing PHP fixtures are exercised.
A local witness is NOT evidence of CI, owner authorization or production.
The coverage matrices remain NO_VERIFICABLE until independently reconciled.
"""
from __future__ import annotations

from test_brechas_controlbot_20 import credible_e4_coverage_row, corrupted_e4_row

import ast
from functools import lru_cache
import json
from pathlib import Path
import re
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
LOCAL = "CONTRATO_LOCAL_PARCIAL"
GAP = "BRECHA_DOCUMENTADA"
IDS = tuple(f"CB-{n:04d}" for n in range(1102, 1117))
MAIN = "docs/matriz-cobertura.md"
PART = "docs/matriz-cobertura-parte-04.md"
VENDOR = "docs/vendor-registry-contract.md"
WORK = "docs/vendor-work-origin.md"
SOURCE = "docs/venture-access-source.md"
FINANCE = "docs/venture-finance.md"
OWNER = "docs/venture-owner-decision-flow.md"
CLAUSES = {
    "CB-1102": (PART, VENDOR, "Este core no compra, paga, provisiona"),
    "CB-1103": (MAIN, WORK, "El caller/policy aporta explícitamente"),
    "CB-1104": (PART, WORK, "El bridge primero consume"),
    "CB-1105": (PART, WORK, "La idempotencia se deriva"),
    "CB-1106": (PART, WORK, "Stale, degraded, pending o rejected"),
    "CB-1107": (PART, WORK, "budget_ref"),
    "CB-1108": (MAIN, SOURCE, "El request de infraestructura"),
    "CB-1109": (PART, SOURCE, "Sin source configurado"),
    "CB-1110": (PART, SOURCE, "El fake usado en tests"),
    "CB-1111": (PART, SOURCE, "#218 establece la fuente"),
    "CB-1112": (MAIN, FINANCE, "source_ref and observed_at are mandatory"),
    "CB-1113": (MAIN, OWNER, "DecisionRuntime::executeVentureAccess"),
    "CB-1114": (PART, OWNER, "owner_decision_gate"),
    "CB-1115": (PART, OWNER, "La idempotencia cross-session"),
    "CB-1116": (PART, OWNER, "GateInbox"),
}
DOCS = {MAIN, PART, VENDOR, WORK, SOURCE, FINANCE, OWNER}
FIXTURES = {
    "vendor": ("tests/vendor_work_origin_scenarios.php",
               frozenset({"scope", "explicit", "idempotency", "limited", "optional", "pure"})),
    "source": ("tests/venture_access_source_scenarios.php",
               frozenset({"request", "resolve", "mismatch", "composition", "readonly", "secret"})),
    "finance": ("tests/venture_finance_scenarios.php",
                frozenset({"base", "freshness", "provenance"})),
}
# These rows have an existing executable local witness. The remaining rows
# document a genuine integration or authority gap; UNKNOWN is intentional.
LOCAL_ROWS = frozenset({
    "CB-1103", "CB-1104", "CB-1105", "CB-1106", "CB-1107",
    "CB-1108", "CB-1109", "CB-1110", "CB-1112",
})


def read_documents() -> dict[str, str]:
    return {path: (ROOT / path).read_text(encoding="utf-8") for path in DOCS}


def matrix_rows(files: object) -> dict[str, tuple[str, ...]]:
    """No duplicated, promoted, missing or renamed canonical rows."""
    if (type(files) is not dict or not DOCS.issubset(files)
            or any(type(files.get(p)) is not str or not files[p] for p in DOCS)):
        raise ValueError("UNKNOWN: missing matrix or normative source")
    rows: dict[str, tuple[str, ...]] = {}
    for path in (MAIN, PART):
        for line in files[path].splitlines():
            match = re.match(r"^\|\s*(CB-\d{4})\s*\|", line)
            if match is None or match.group(1) not in IDS:
                continue
            key = match.group(1)
            expected_path, doc, clause = CLAUSES[key]
            cells = tuple(c.strip() for c in line.strip().strip("|").split("|"))
            gap = cells[4].casefold() if len(cells) == 6 else ""
            if (key in rows or path != expected_path or len(cells) != 6
                    or cells[0] != key or f"[{doc}:L" not in cells[1]
                    or clause.casefold() not in cells[2].casefold()
                    or clause.casefold() not in files[doc].casefold()
                    or not credible_e4_coverage_row(cells)
                    or "/issues/1" not in cells[5]):
                raise ValueError("UNKNOWN: incomplete or falsely promoted " + key)
            rows[key] = cells
    if set(rows) != set(IDS):
        raise ValueError("UNKNOWN: 15 expected rows not present")
    return rows


@lru_cache(maxsize=20)
def scenario(group: str, name: str) -> dict[str, object]:
    """Run only whitelisted, checked-in PHP fixtures with synthetic data."""
    if group not in FIXTURES or name not in FIXTURES[group][1]:
        raise AssertionError("UNKNOWN: fixture not whitelisted")
    php = shutil.which("php")
    if php is None:
        raise AssertionError("UNKNOWN: PHP unavailable")
    run = subprocess.run(
        [php, str(ROOT / FIXTURES[group][0]), name],
        cwd=ROOT, capture_output=True, text=True, timeout=45, check=False,
    )
    if run.returncode != 0 or run.stderr.strip():
        raise AssertionError(f"UNKNOWN: fixture {group}/{name} failed")
    try:
        data = json.loads(run.stdout)
    except (ValueError, TypeError) as exc:
        raise AssertionError("UNKNOWN: invalid JSON fixture") from exc
    if type(data) is not dict or not data:
        raise AssertionError("UNKNOWN: fixture evidence unavailable")
    return data


def witness(key: str, proofs: dict[str, dict]) -> bool:
    """Independent behavioral condition for each local row; never a live claim."""
    try:
        if key == "CB-1103":
            a, b = proofs["vendor_explicit"]["first"], proofs["vendor_explicit"]["second"]
            return (a["work_type"] == "operations"
                    and a["priority_class"] == "high"
                    and a["authority_level"] == "operational"
                    and b["work_type"] == "compliance_review"
                    and b["priority_class"] == "medium"
                    and b["authority_level"] == "owner"
                    and b["required_roles"] == ["legal-privacidad"])
        if key == "CB-1104":
            item = proofs["vendor_scope"]["risk"]
            return (item["observed_at"] == "2023-11-14T22:13:20Z"
                    and proofs["vendor_limited"]["unknown_rejected"] is True)
        if key == "CB-1105":
            a, b = proofs["vendor_idempotency"]["a"], proofs["vendor_idempotency"]["b"]
            return (a["idempotency_key"] == b["idempotency_key"]
                    and a["work_id"] != b["work_id"]
                    and a["requested_capabilities"] == b["requested_capabilities"]
                    and a["claims"] == b["claims"])
        if key == "CB-1106":
            item = proofs["vendor_limited"]["stale"]
            forbidden = {"ready", "approved", "authorized", "readiness"}
            return (proofs["vendor_limited"]["unknown_rejected"] is True
                    and item["origin_mode"] == "automatic"
                    and item["origin_system"] == "controlbot"
                    and not (forbidden & set(item)))
        if key == "CB-1107":
            item = proofs["vendor_optional"]["good"]
            return (item["budget_ref"] == "capital:vendor-budget"
                    and item["approval_ref"] == "owner-decision:275"
                    and proofs["vendor_optional"]["bad_execution_field"] is True
                    and not ({"ready", "authorized", "provider", "executor"} & set(item)))
        if key == "CB-1108":
            item = proofs["source_request"]
            return item["extra_blocked"] is True and item["calls"] == 0
        if key == "CB-1109":
            item = proofs["source_mismatch"]
            return (set(item) >= {"capability", "policy", "scope", "missing",
                                  "ambiguous", "unconfigured"}
                    and all(value is True for value in item.values())
                    and proofs["source_readonly"]["source_unchanged"] is True
                    and proofs["source_readonly"]["has_runtime_state"] is False)
        if key == "CB-1110":
            item = proofs["source_composition"]
            return (item["request_keys"] == ["identity_id", "scope", "capability"]
                    and item["calls"] == 1
                    and item["grant_id"] == "grant-infra")
        if key == "CB-1112":
            item = proofs["finance_freshness"]
            provenance = proofs["finance_provenance"]
            return (all("no puede ser verified" in item[k]
                        for k in ("stale_verified", "unknown_verified"))
                    and item["stale_unknown"]["confidence"] == "unknown"
                    and item["stale_unknown"]["freshness"] == "stale"
                    and all(provenance[k] != "accepted"
                            for k in ("bad_source", "email_source", "credential_url", "bad_time")))
    except (KeyError, IndexError, TypeError, ValueError, AttributeError):
        return False
    return False


def local_evidence(key: object, files: object, proofs: object) -> str:
    if type(key) is not str or key not in IDS or type(proofs) is not dict:
        return UNKNOWN
    try:
        rows = matrix_rows(files)
    except ValueError:
        return UNKNOWN
    if key not in rows:
        return UNKNOWN
    if key not in LOCAL_ROWS:
        # The documented scope of the future integration is NOT implementation.
        return GAP
    if set(proofs) != PROOF_KEYS or any(type(v) is not dict for v in proofs.values()):
        return UNKNOWN
    return LOCAL if witness(key, proofs) else UNKNOWN


PROOF_KEYS = frozenset({
    "vendor_scope", "vendor_explicit", "vendor_idempotency",
    "vendor_limited", "vendor_optional", "source_request",
    "source_mismatch", "source_composition", "source_readonly",
    "finance_freshness", "finance_provenance",
})


@lru_cache(maxsize=1)
def local_proofs() -> dict[str, dict]:
    return {
        "vendor_scope": scenario("vendor", "scope"),
        "vendor_explicit": scenario("vendor", "explicit"),
        "vendor_idempotency": scenario("vendor", "idempotency"),
        "vendor_limited": scenario("vendor", "limited"),
        "vendor_optional": scenario("vendor", "optional"),
        "source_request": scenario("source", "request"),
        "source_mismatch": scenario("source", "mismatch"),
        "source_composition": scenario("source", "composition"),
        "source_readonly": scenario("source", "readonly"),
        "finance_freshness": scenario("finance", "freshness"),
        "finance_provenance": scenario("finance", "provenance"),
    }


def operational_attestation(_key: object, _claim: object = None) -> str:
    """CI/release/owner/live evidence is external; never inferred from fixtures."""
    return UNKNOWN


class BrechasControlbot72Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        files, proofs = read_documents(), local_proofs()
        self.assertEqual(set(matrix_rows(files)), set(IDS))
        self.assertEqual(LOCAL_ROWS | (set(IDS) - LOCAL_ROWS), set(IDS))
        for ident in IDS:
            with self.subTest(row=ident):
                expected = LOCAL if ident in LOCAL_ROWS else GAP
                self.assertEqual(local_evidence(ident, files, proofs), expected)
                self.assertEqual(operational_attestation(ident), UNKNOWN)

    def test_falla_cerrado(self) -> None:
        files, proofs = read_documents(), local_proofs()
        for ident in IDS:
            path, doc, _ = CLAUSES[ident]
            line = next(line for line in files[path].splitlines()
                        if re.match(r"^\|\s*" + ident + r"\s*\|", line))
            corruptions = (
                files[path].replace(line, "", 1),
                files[path] + "\n" + line,
                files[path].replace(line, corrupted_e4_row(line), 1),
            )
            for modified in corruptions:
                with self.subTest(row=ident, corruption=modified[-20:]):
                    broken = {**files, path: modified}
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        matrix_rows(broken)
                    self.assertEqual(local_evidence(ident, broken, proofs), UNKNOWN)
            self.assertEqual(local_evidence(ident, {**files, doc: ""}, proofs), UNKNOWN)
        for ident in LOCAL_ROWS:
            bad = {**proofs}
            bad.clear()
            self.assertEqual(local_evidence(ident, files, bad), UNKNOWN)
        self.assertEqual(local_evidence("CB-9999", files, proofs), UNKNOWN)
        with self.assertRaisesRegex(AssertionError, UNKNOWN):
            scenario("vendor", "untrusted")
        with self.assertRaisesRegex(AssertionError, UNKNOWN):
            scenario("network", "request")

    def test_sin_datos_reales(self) -> None:
        script = (ROOT / "tests/test_brechas_controlbot_72.py").read_text(encoding="utf-8")
        imports = set()
        for node in ast.walk(ast.parse(script)):
            if isinstance(node, ast.Import):
                imports.update(x.name.split(".")[0] for x in node.names)
            elif isinstance(node, ast.ImportFrom) and node.module:
                imports.add(node.module.split(".")[0])
        self.assertLessEqual(
            imports, {"__future__", "ast", "functools", "json", "pathlib",
                      "re", "shutil", "subprocess", "unittest",
                                  "test_brechas_controlbot_20"})
        self.assertTrue(all(path.startswith("tests/") and path.endswith("_scenarios.php")
                            for path, _ in FIXTURES.values()))
        data = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        self.assertEqual(data["phase"], "construccion")
        self.assertEqual(data["d063_attestation"],
                         {"nothing_live": True, "no_real_customer_data": True})
        self.assertEqual(set(FIXTURES), {"vendor", "source", "finance"})

    def test_estados_unknown_explicitos(self) -> None:
        files, proofs = read_documents(), local_proofs()
        for ident in IDS:
            with self.subTest(row=ident):
                self.assertIn(local_evidence(ident, files, proofs), (LOCAL, GAP))
                for forged in (None, True, {"ci": "success"},
                               {"approved": True}, {"phase": "live"},
                               {"status": "VALIDATED_IN_PRODUCTION"}):
                    self.assertEqual(operational_attestation(ident, forged), UNKNOWN)
        self.assertEqual(local_evidence(None, files, proofs), UNKNOWN)
        self.assertEqual(local_evidence("CB-1103", files, None), UNKNOWN)


if __name__ == "__main__":
    unittest.main()
