"""ControlBot #1078: synthetic, fail-closed coverage for CB-1087..CB-1101.

These assertions establish local contracts only. They never certify live
operations, personal-data processing, procurement, owner authority or release.
The coverage matrix is read-only and remains NO_VERIFICABLE until reconciled.
"""
from __future__ import annotations

from test_brechas_controlbot_20 import credible_e4_coverage_row, corrupted_e4_row

from functools import lru_cache
import ast
import json
from pathlib import Path
import re
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
IDS = tuple(f"CB-{number:04d}" for number in range(1087, 1102))
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_CONTRACT"
MATRIX_PATHS = tuple(
    ROOT / ("docs/matriz-cobertura" + part + ".md")
    for part in ("", "-parte-01", "-parte-02", "-parte-03", "-parte-04")
)
DOC_PATHS = {
    "staff": ROOT / "docs/staff-control-plane.md",
    "vendor": ROOT / "docs/vendor-exception-signals.md",
    "inbox": ROOT / "docs/vendor-owner-inbox-projection.md",
    "registry": ROOT / "docs/vendor-registry-contract.md",
}
SCRIPTS = {
    "staff": ROOT / "tests/staff_control_plane_scenarios.php",
    "vendor": ROOT / "tests/vendor_exception_signal_scenarios.php",
    "inbox": ROOT / "tests/vendor_owner_inbox_projection_scenarios.php",
    "registry": ROOT / "tests/vendor_registry_scenarios.php",
}
CLAUSES = {
    "CB-1087": ("staff", "Privacidad:"),
    "CB-1088": ("staff", "Cada acción exige identidad humana"),
    "CB-1089": ("staff", "La idempotency key se deriva"),
    "CB-1090": ("vendor", "Las fechas"),
    "CB-1091": ("vendor", "missing_exit_plan"),
    "CB-1092": ("vendor", "critical_unknown"),
    "CB-1093": ("vendor", "Cada señal conserva"),
    "CB-1094": ("inbox", "Este bridge conecta"),
    "CB-1095": ("inbox", "El bridge no deriva"),
    "CB-1096": ("inbox", "Venture scope:"),
    "CB-1097": ("inbox", "Cuando freshness es unknown"),
    "CB-1098": ("registry", "El registry no copia"),
    "CB-1099": ("registry", "Vendor, service, owner, contract"),
    "CB-1100": ("registry", "Health/SLA:"),
    "CB-1101": ("registry", "Renewal y expiry"),
}


def matrix_rows(documents: object) -> dict[str, tuple[str, ...]]:
    """Parse every partition; ambiguous duplicate IDs cannot pass silently."""
    if (not isinstance(documents, tuple) or len(documents) != len(MATRIX_PATHS)
            or any(not isinstance(doc, str) or not doc for doc in documents)):
        raise ValueError("UNKNOWN: matrix unavailable")
    found: dict[str, tuple[str, ...]] = {}
    for document in documents:
        for raw in document.splitlines():
            if not raw.lstrip().startswith("|"):
                continue
            cells = tuple(x.strip() for x in raw.strip().strip("|").split("|"))
            if not cells or cells[0] not in IDS:
                continue
            ident = cells[0]
            if ident in found:
                raise ValueError("UNKNOWN: duplicated " + ident)
            if (len(cells) != 6 or not all(cells[:5])
                    or not credible_e4_coverage_row(cells)):
                raise ValueError("UNKNOWN: invalid/proclaimed " + ident)
            group, opening = CLAUSES[ident]
            if (opening.casefold() not in cells[2].casefold()
                    or f"docs/{DOC_PATHS[group].name}" not in cells[1]):
                raise ValueError("UNKNOWN: source mismatch " + ident)
            found[ident] = cells
    return found


@lru_cache(maxsize=32)
def scenario(group: str, name: str) -> dict[str, object]:
    """Execute an existing deterministic PHP fixture; no remote IO or secrets."""
    if group not in SCRIPTS or re.fullmatch(r"[a-z_]+", name) is None:
        raise ValueError("UNKNOWN: unsupported fixture")
    completed = subprocess.run(
        ["php", str(SCRIPTS[group]), name],
        cwd=ROOT, capture_output=True, text=True, timeout=45, check=False,
    )
    if completed.returncode or completed.stderr.strip():
        raise AssertionError("UNKNOWN: fixture failed " + group + "/" + name +
                             ": " + completed.stderr[:500])
    try:
        decoded = json.loads(completed.stdout)
    except json.JSONDecodeError as exc:
        raise AssertionError("UNKNOWN: malformed fixture evidence") from exc
    if not isinstance(decoded, dict):
        raise AssertionError("UNKNOWN: fixture must return an object")
    return decoded


def evidence(ident: str) -> bool:
    """One independent, executable assertion per normative row."""
    if ident == "CB-1087":
        data = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        rows = {x["id"]: x for x in data["treatments"]}
        staff = ("staff_directory_contact", "staff_directory_identity")
        view = scenario("staff", "search")
        return (all(rows[k]["retention"] == "request_lifetime_only"
                    and rows[k]["providers"] == [] for k in staff)
                and data["d063_attestation"] ==
                {"nothing_live": True, "no_real_customer_data": True}
                and view["valid"]["directory_persisted"] is False
                and view["raw_email_leaked"] is False)
    if ident == "CB-1088":
        access = scenario("staff", "authority")
        invite = scenario("staff", "invite")
        return (access["rejected"] is True and invite["mfa_rejected"] is True
                and invite["role_rejected"] is True
                and invite["ok"]["status"] == "applied")
    if ident == "CB-1089":
        actions = scenario("staff", "mutations")
        unknown = scenario("staff", "ambiguous")
        audit = scenario("staff", "audit")
        return (actions["payload_keys_distinct"] is True
                and len(actions["rows"]) == 3
                and all(x["idempotency_key"] == x["expected"]
                        and x["mutations"] == 1 for x in actions["rows"])
                and unknown["mutations"] == unknown["unknown_mutations"] == 1
                and unknown["lookups"] == 2
                and unknown["unknown"]["status"] == "unknown_outcome"
                and audit["secret_free"] is True
                and audit["result"]["audit"]["product_audit_ref"].startswith("product:audit/"))
    if ident == "CB-1090":
        result = scenario("vendor", "material")
        return ("renewal_due" in result["types"]
                and result["at_boundary_types"] == []
                and "renewal_due" in [x["type"] for x in result["signals"]])
    if ident == "CB-1091":
        result = scenario("vendor", "material")
        names = set(result["types"])
        normal = scenario("vendor", "provenance")
        return ({"missing_exit_plan", "missing_export_capability"} <= names
                and not any(x["type"].startswith("missing_") for x in normal["signals"]))
    if ident == "CB-1092":
        result = scenario("vendor", "unknown")
        return (result["unknown_types"] == result["stale_types"] == ["critical_unknown"]
                and result["unknown"][0]["freshness"] == "unknown"
                and result["stale"][0]["freshness"] == "stale"
                and all(item[0]["health"] == "unknown"
                        for item in (result["unknown"], result["stale"])))
    if ident == "CB-1093":
        signals = scenario("vendor", "provenance")["signals"]
        if not signals:
            return False
        mandatory = {"venture_id", "vendor_id", "criticality", "lifecycle",
                     "health", "sla_state", "freshness", "observed_at",
                     "source_ref", "renewal_at", "expiry_at", "evidence_refs"}
        return (all(mandatory <= set(s) and s["venture_id"] == "venture-alpha"
                    and s["source_ref"].startswith("evidence:")
                    for s in signals)
                and all("credentials_ref" not in s and "owner_ref" not in s
                        for s in signals))
    if ident == "CB-1094":
        result = scenario("inbox", "material")
        return (result["fake"] is True
                and result["entry"]["class"] == "watch"
                and result["entry"]["entry_ref"].startswith(
                    "controlbot:vendor-exception/inbox/"))
    if ident == "CB-1095":
        values = scenario("inbox", "explicit")
        classes = scenario("inbox", "classes")
        return (values["watch"]["class"] == "watch"
                and values["watch"]["required_authority_level"] is None
                and values["decision"]["class"] == "decision"
                and values["decision"]["required_authority_level"] == "L4_OWNER"
                and classes["watch_authority"] is True
                and classes["decision_missing"] is True)
    if ident == "CB-1096":
        entry = scenario("inbox", "provenance")["entry"]
        return (entry["scope"] == {"kind": "venture",
                                  "ref": "controlbot:venture/venture-alpha"}
                and entry["source_ref"].startswith(
                    "controlbot:vendor-exception/source/"))
    if ident == "CB-1097":
        values = scenario("inbox", "freshness")
        return (values["unknown"]["freshness"] == "unknown"
                and values["unknown"]["source_ref"] is None
                and values["unknown"]["observed_at"] is None
                and values["unknown"]["evidence_refs"] == []
                and values["stale"]["freshness"] == "stale"
                and values["stale"]["source_ref"] is not None)
    if ident == "CB-1098":
        values = scenario("registry", "authority")
        encoded = json.dumps(values).lower()
        return (values["cost"]["capital_ref"].startswith("capital:")
                and values["security"]["aegis_review_ref"].startswith("aegis:")
                and values["legal"]["lex_review_ref"].startswith("lex:")
                and all(key not in encoded for key in
                        ("authority_level", "decision", "approved_by", "budget_limit")))
    if ident == "CB-1099":
        values = scenario("registry", "privacy")
        return (values["human_rejected"] is True
                and values["credential_rejected"] is True
                and re.fullmatch(r"credential:[0-9a-f]{32}",
                                 values["valid"]["credentials_ref"]) is not None)
    if ident == "CB-1100":
        values = scenario("registry", "states")
        return (values["stale"]["health"] == "degraded"
                and values["stale"]["freshness"]["state"] == "stale"
                and values["unknown"]["health"] == "unknown"
                and values["unknown"]["freshness"] ==
                {"state": "unknown", "observed_at": None, "source_ref": None}
                and values["false_healthy_rejected"] is True
                and values["false_sla_rejected"] is True)
    if ident == "CB-1101":
        values = scenario("registry", "states")
        v = scenario("registry", "privacy")["valid"]
        return (values["bad_dates_rejected"] is True
                and type(v["renewal_at"]) is int and type(v["expiry_at"]) is int
                and v["renewal_at"] <= v["expiry_at"])
    return False


def verdict(ident: object, rows: object, proofs: object,
            docs: object) -> str:
    """Missing sources, false proofs or malformed inputs always mean UNKNOWN."""
    if (not isinstance(ident, str) or ident not in IDS
            or not isinstance(rows, dict) or not isinstance(proofs, dict)
            or not isinstance(docs, dict) or proofs.get(ident) is not True):
        return UNKNOWN
    row = rows.get(ident)
    if (not isinstance(row, tuple) or len(row) != 6
            or not credible_e4_coverage_row(row)):
        return UNKNOWN
    group, phrase = CLAUSES[ident]
    original = docs.get(group)
    if (not isinstance(original, str) or phrase.casefold() not in original.casefold()):
        return UNKNOWN
    return LOCAL


class BrechasControlbot71Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.documents = tuple(p.read_text(encoding="utf-8") for p in MATRIX_PATHS)
        cls.rows = matrix_rows(cls.documents)
        cls.docs = {name: path.read_text(encoding="utf-8")
                    for name, path in DOC_PATHS.items()}

    def test_contrato_principal(self) -> None:
        """AC-01: all 15 rows receive an independent PHP or privacy-map proof."""
        self.assertEqual(15, len(IDS))
        self.assertEqual(set(IDS), set(CLAUSES))
        self.assertEqual(set(IDS), set(self.rows))
        for ident in IDS:
            with self.subTest(ident=ident):
                checked = evidence(ident)
                self.assertTrue(checked, "UNKNOWN: behavior unverified for " + ident)
                self.assertEqual(verdict(ident, self.rows, {ident: checked},
                                         self.docs), LOCAL)

    def test_falla_cerrado(self) -> None:
        """AC-02: missing/ambiguous/promoted clauses cannot earn coverage."""
        self.assertEqual(verdict("CB-1087", {}, {"CB-1087": True},
                                 self.docs), UNKNOWN)
        self.assertEqual(verdict("CB-1090", self.rows, {}, self.docs), UNKNOWN)
        self.assertEqual(verdict("CB-1101", self.rows,
                                 {"CB-1101": "true"}, self.docs), UNKNOWN)
        self.assertEqual(verdict("CB-1101", self.rows,
                                 {"CB-1101": True}, {}), UNKNOWN)
        self.assertEqual(verdict("CB-9999", self.rows, {}, self.docs), UNKNOWN)
        for ident in IDS:
            with self.subTest(ident=ident):
                source = next(i for i, doc in enumerate(self.documents)
                              if any(s.startswith("| " + ident + " |")
                                     for s in doc.splitlines()))
                row = next(s for s in self.documents[source].splitlines()
                           if s.startswith("| " + ident + " |"))
                missing = list(self.documents)
                missing[source] = missing[source].replace(row, "", 1)
                self.assertEqual(verdict(ident, matrix_rows(tuple(missing)),
                                         {ident: True}, self.docs), UNKNOWN)
                for changed in (self.documents[source] + "\n" + row,
                                self.documents[source].replace(
                                    row, corrupted_e4_row(row), 1)):
                    copies = list(self.documents)
                    copies[source] = changed
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        matrix_rows(tuple(copies))
        with self.assertRaisesRegex(ValueError, UNKNOWN):
            scenario("staff", "bad/fixture")

    def test_sin_datos_reales(self) -> None:
        """AC-03: only local fixtures; no real identity, network, or secrets."""
        tree = ast.parse(Path(__file__).read_text(encoding="utf-8"))
        imports = {alias.name.split(".")[0]
                   for node in ast.walk(tree) if isinstance(node, ast.Import)
                   for alias in node.names}
        imports |= {node.module.split(".")[0]
                    for node in ast.walk(tree) if isinstance(node, ast.ImportFrom)
                    and node.module}
        self.assertFalse(imports & {"requests", "httpx", "socket", "urllib"})
        self.assertEqual(len(SCRIPTS), 4)
        self.assertTrue(all(p.is_file() and p.parent == ROOT / "tests"
                            for p in SCRIPTS.values()))
        mapping = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        self.assertEqual(mapping["d063_attestation"],
                         {"nothing_live": True, "no_real_customer_data": True})
        self.assertTrue(all("email" not in row and "masked_email" in row
                            for row in scenario("staff", "search")["valid"]["records"]))

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: no local pass is evidence of live/production operations."""
        self.assertEqual(set(self.rows), set(IDS))
        for ident in IDS:
            with self.subTest(ident=ident):
                self.assertTrue(credible_e4_coverage_row(self.rows[ident]))
                self.assertIn("live=UNKNOWN", self.rows[ident][4])
                self.assertIn(verdict(ident, self.rows, {ident: False},
                                    self.docs), (UNKNOWN,))
        self.assertEqual(verdict(None, self.rows, {}, self.docs), UNKNOWN)


if __name__ == "__main__":
    unittest.main()
