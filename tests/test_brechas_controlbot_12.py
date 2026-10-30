#!/usr/bin/env python3
"""ControlBot #1019: offline coverage for CB-0167..CB-0181.

Run only versioned PHP scenario harnesses with invented fixture entities.
Factory, FactoryRunner, live recovery and backup provenance stay UNKNOWN.
This test does not edit or certify the coverage matrix.
"""
from __future__ import annotations

import json
import re
import subprocess
import unittest
from functools import lru_cache
from pathlib import Path

from test_matriz_cobertura import credible_offline_coverage_row

ROOT = Path(__file__).resolve().parents[1]
MATRIX = "docs/matriz-cobertura.md"
PART = "docs/matriz-cobertura-parte-01.md"
DRILL_DOC = "docs/disaster-recovery-drill.md"
EVIDENCE_DOC = "docs/disaster-recovery-evidence.md"
DRILL_SRC = "src/RecoveryDrillProjection.php"
EVIDENCE_SRC = "src/RecoveryEvidence.php"
FILES = (MATRIX, PART, DRILL_DOC, EVIDENCE_DOC, DRILL_SRC, EVIDENCE_SRC)
IDS = tuple(f"CB-{number:04d}" for number in range(167, 182))
EXTERNAL = frozenset(("CB-0167", "CB-0173", "CB-0174",
                       "CB-0175", "CB-0176", "CB-0179"))
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_CONTRACT"
SNIPPETS = {
    "CB-0167": "Este slice de #182 proyecta un restore drill",
    "CB-0168": "ControlBot conserva la proyección por proyecto",
    "CB-0169": "BackupReceipt",
    "CB-0170": "no recalcula",
    "CB-0171": "Un resultado",
    "CB-0172": "Solo se acepta target",
    "CB-0173": "Recovery Health, WorkItems",
    "CB-0174": "Este slice de #182 proyecta evidencia observada",
    "CB-0175": "Factory #305 define Recovery Manifest",
    "CB-0176": "FactoryRunner ejecuta adapters/jobs",
    "CB-0177": "BackupReceipt es el productor nominal",
    "CB-0178": "RecoveryEvidence recibe estados y refs",
    "CB-0179": "ControlBot no crea un segundo motor",
    "CB-0180": "Solo evidencia fresh conserva verified/failed",
    "CB-0181": "RecoveryEvidence.project_ref",
}
SCRIPTS = {
    "drill": "tests/recovery_drill_projection_scenarios.php",
    "evidence": "tests/recovery_evidence_scenarios.php",
}
CASES = {
    "drill": (
        "valid", "cross-project", "producer-mismatch", "evidence-mismatch",
        "stale", "unknown", "breached", "production-target", "failed-check",
        "authority-expanded", "execute-true", "sensitive-ref",
        "extra-field", "target-mismatch", "bad-observed-at",
    ),
    "evidence": (
        "valid", "cross-project", "producer-project-mismatch",
        "cross-project-profile-ref", "stale-checksum", "unknown-checksum",
        "stale-source", "duplicate-source", "required-not-applicable",
        "top-level-digest", "payload-field", "provider-url", "secret-ref",
        "unknown-with-provenance",
    ),
}


def rows(index: str, partition: str) -> dict[str, tuple[str, ...]]:
    found: dict[str, tuple[str, ...]] = {}
    for filename, content in ((MATRIX, index), (PART, partition)):
        for line in content.splitlines():
            match = re.match(r"^\| (CB-\d{4}) \|", line)
            if match is None or match.group(1) not in IDS:
                continue
            key = match.group(1)
            if key in found:
                raise ValueError("UNKNOWN: duplicate " + key)
            cells = tuple(cell.strip() for cell in line.strip().strip("|").split("|"))
            if (len(cells) != 6 or cells[0] != key
                    or not credible_offline_coverage_row(cells)
                    or SNIPPETS[key] not in cells[2]
                    or ((key in {"CB-0167", "CB-0174"}) != (filename == MATRIX))):
                raise ValueError("UNKNOWN: malformed " + key)
            found[key] = cells
    if set(found) != set(IDS):
        raise ValueError("UNKNOWN: missing coverage rows")
    return found


def sources() -> dict[str, str]:
    return {filename: (ROOT / filename).read_text(encoding="utf-8")
            for filename in FILES}


@lru_cache(maxsize=48)
def scenario(domain: str, case: str) -> dict:
    """Call real local PHP scenario programs, never production or providers."""
    if domain not in CASES or case not in CASES[domain]:
        raise AssertionError("UNKNOWN: unapproved scenario")
    path = ROOT / SCRIPTS[domain]
    run = subprocess.run(
        ["php", "-n", str(path), case], cwd=ROOT, check=False,
        text=True, capture_output=True, timeout=15,
    )
    if run.returncode != 0:
        raise AssertionError("UNKNOWN: offline fixture failure: " + case)
    value = json.loads(run.stdout)
    if not isinstance(value, dict):
        raise AssertionError("UNKNOWN: malformed offline fixture")
    return value


@lru_cache(maxsize=1)
def observations() -> dict[str, dict[str, dict]]:
    return {domain: {case: scenario(domain, case) for case in cases}
            for domain, cases in CASES.items()}


def blocked(values: dict[str, dict], *cases: str) -> bool:
    return all(values[name].get("blocked") is True
               and isinstance(values[name].get("message"), str)
               for name in cases)


def evaluate(files: dict[str, str], observed: object) -> dict[str, bool]:
    """Assess narrow local contracts; unknown external authorities stay false."""
    if (not isinstance(observed, dict)
            or not all(isinstance(files.get(k), str) and files[k] for k in FILES)):
        return {}
    try:
        d, e = observed["drill"], observed["evidence"]
        passed, breached, stale, unknown = (
            d["valid"], d["breached"], d["stale"], d["unknown"])
        valid_e = e["valid"]
        if not (isinstance(passed, dict) and isinstance(valid_e, dict)):
            return {}
        drill_src, evidence_src = files[DRILL_SRC], files[EVIDENCE_SRC]
        drill_docs, evidence_docs = files[DRILL_DOC], files[EVIDENCE_DOC]
        clean_drill = (
            passed["target"]["kind"] == "disposable"
            and passed["checks"] == {"health": True, "smoke": True, "integrity": True}
            and passed["authority"] == "unchanged"
            and passed["execute"] is False
        )
        same_project = (
            passed["project_ref"] == valid_e["project_ref"]
            == "controlbot:project/project-controlbot"
            and passed["backup_receipt_id"]
            == "11111111-1111-4111-8111-111111111111"
        )
        projection_only = (
            set(passed) == {
                "version", "project_ref", "recovery_evidence_ref",
                "backup_receipt_id", "target", "status", "reported_status",
                "observed", "checks", "reasons", "evidence_refs",
                "observed_at", "freshness", "authority", "execute",
            }
            and all(token not in drill_src for token in
                    ("WorkItem", "HEALTHY", "shell_exec", "curl_", "file_get_contents"))
        )
        evidence_only = (
            set(valid_e) == {"version", "project_ref", "profile_ref",
                             "controls", "sources"}
            and all(token not in evidence_src for token in
                    ("WorkItem", "shell_exec", "curl_", "file_get_contents"))
        )
        evidence_sources = valid_e["sources"]
        if not isinstance(evidence_sources, list) or len(evidence_sources) != 4:
            return {}
        results = {
            # External Factory #330 judgment cannot be verified by a local projection.
            "CB-0167": False,
            "CB-0168": same_project and stale["status"] == UNKNOWN
                        and unknown["status"] == UNKNOWN,
            "CB-0169": same_project and blocked(
                d, "cross-project", "producer-mismatch", "evidence-mismatch"),
            "CB-0170": (
                passed["reported_status"] == passed["status"] == "PASSED"
                and breached["reported_status"] == breached["status"] == "BREACHED"
                and breached["reasons"] == ["RTO_EXCEEDED"]
                and "'status' => $freshness === 'fresh' ? $reported : 'UNKNOWN'" in drill_src
                and "no recalcula" in drill_docs
            ),
            "CB-0171": (
                passed["freshness"] == "fresh" and passed["status"] == "PASSED"
                and all(x["status"] == UNKNOWN and x["reported_status"] == "PASSED"
                        and x["observed"] == passed["observed"]
                        for x in (stale, unknown))
            ),
            "CB-0172": clean_drill and blocked(
                d, "production-target", "failed-check", "authority-expanded",
                "execute-true", "sensitive-ref", "extra-field",
                "target-mismatch", "bad-observed-at"),
            # These assert the documented responsibility boundary, not its
            # cross-repository enforcement or real health/restore evidence.
            "CB-0173": False,
            "CB-0174": False,
            "CB-0175": False,
            "CB-0176": False,
            "CB-0177": (
                same_project and evidence_only and blocked(
                    e, "producer-project-mismatch", "cross-project")
                and "BackupReceipt es el productor nominal" in evidence_docs
            ),
            "CB-0178": (
                evidence_only and "RecoveryEvidence recibe estados y refs" in evidence_docs
                and "BackupReceipt $receipt" in evidence_src
                and "public static function normalize(" in evidence_src
            ),
            # Absence of any second backup engine cannot be attested here.
            "CB-0179": False,
            "CB-0180": (
                valid_e["controls"]["checksum"]["state"] == "verified"
                and e["stale-checksum"]["controls"]["checksum"]["state"] == "unknown"
                and e["stale-checksum"]["controls"]["checksum"]["reported_state"] == "verified"
                and e["unknown-checksum"]["controls"]["checksum"]["state"] == "unknown"
                and e["unknown-checksum"]["controls"]["checksum"]["evidence_ref"] is None
                and e["stale-source"]["sources"][2]["state"] == "unknown"
                and evidence_sources[1]["state"] == "not_applicable"
                and blocked(e, "required-not-applicable", "unknown-with-provenance")
            ),
            "CB-0181": (
                same_project
                and blocked(e, "cross-project", "producer-project-mismatch",
                            "cross-project-profile-ref")
                and blocked(d, "cross-project", "producer-mismatch",
                            "evidence-mismatch")
            ),
        }
        if not (projection_only and evidence_only):
            return {}
        return results
    except (KeyError, TypeError, AttributeError, IndexError, ValueError):
        return {}


def verdict(key: str, table: dict[str, tuple[str, ...]],
            files: dict[str, str], observed: object) -> dict[str, str]:
    state = {"offline": UNKNOWN, "ci": UNKNOWN, "live": UNKNOWN,
             "operating": UNKNOWN}
    row = table.get(key)
    if (key not in IDS or not isinstance(row, tuple) or len(row) != 6
            or row[0] != key or not credible_offline_coverage_row(row)
            or SNIPPETS[key] not in row[2]
            or not all(isinstance(files.get(k), str) and files[k] for k in FILES)):
        return state
    local = evaluate(files, observed)
    if local.get(key, False) is True and key not in EXTERNAL:
        state["offline"] = LOCAL
    return state


class BrechasControlbot12Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.files = sources()
        cls.table = rows(cls.files[MATRIX], cls.files[PART])
        cls.observed = observations()

    def test_contrato_principal(self) -> None:
        """AC-01: 15 explicit assertions, with external authority UNKNOWN."""
        self.assertEqual(set(self.table), set(IDS))
        outcomes = evaluate(self.files, self.observed)
        self.assertEqual(set(outcomes), set(IDS))
        for key in IDS:
            with self.subTest(clause=key):
                row = self.table[key]
                self.assertIn("disaster-recovery-", row[1])
                self.assertTrue(credible_offline_coverage_row(row))
                self.assertIn("live=UNKNOWN", row[4])
                expected = UNKNOWN if key in EXTERNAL else LOCAL
                self.assertEqual(verdict(key, self.table, self.files,
                                         self.observed)["offline"], expected)
                # External claims get their own explicit unproven case.
                if key in EXTERNAL:
                    self.assertFalse(outcomes[key], f"{key}: external verification absent")
                else:
                    self.assertTrue(outcomes[key], f"{key}: local fixture rejected")
        self.assertIn("Factory #330", self.files[DRILL_DOC])
        self.assertIn("FactoryRunner", self.files[EVIDENCE_DOC])
        self.assertIn("BackupReceipt", self.files[EVIDENCE_DOC])

    def test_falla_cerrado(self) -> None:
        """AC-02: malformed coverage and forged/stale/unsafe fixtures fail."""
        first = next(line for line in self.files[MATRIX].splitlines()
                     if line.startswith("| CB-0167 |"))
        with self.assertRaisesRegex(ValueError, "duplicate"):
            rows(self.files[MATRIX] + "\n" + first, self.files[PART])
        with self.assertRaisesRegex(ValueError, "missing"):
            rows(self.files[MATRIX].replace(first + "\n", "", 1),
                 self.files[PART])
        for key in IDS:
            with self.subTest(clause=key):
                self.assertEqual(verdict(key, {}, self.files, self.observed)
                                 ["offline"], UNKNOWN)
                self.assertEqual(verdict(key, self.table, {}, self.observed)
                                 ["offline"], UNKNOWN)
                changed = dict(self.table)
                cells = list(changed[key])
                if cells[3] == "NO_VERIFICABLE":
                    cells[3] = "CUBIERTO"
                else:
                    cells[4] = cells[4].replace("MERGED", "PENDING")
                changed[key] = tuple(cells)
                self.assertEqual(verdict(key, changed, self.files,
                                         self.observed)["offline"], UNKNOWN)
        self.assertEqual(verdict("CB-9999", self.table, self.files,
                                 self.observed)["offline"], UNKNOWN)
        self.assertEqual(evaluate(self.files, None), {})
        for domain, cases in (
            ("drill", ("production-target", "failed-check", "execute-true",
                       "authority-expanded", "extra-field", "sensitive-ref")),
            ("evidence", ("duplicate-source", "required-not-applicable",
                          "top-level-digest", "payload-field", "provider-url",
                          "secret-ref", "unknown-with-provenance")),
        ):
            with self.subTest(domain=domain):
                self.assertTrue(blocked(self.observed[domain], *cases))
                for case in cases:
                    self.assertNotIn("token-secret-value",
                                     self.observed[domain][case]["message"])

    def test_sin_datos_reales(self) -> None:
        """AC-03: fixtures have invented refs; no remote clients or secrets."""
        own = Path(__file__).read_text(encoding="utf-8")
        for path in (*FILES, *SCRIPTS.values()):
            with self.subTest(file=path):
                self.assertTrue((ROOT / path).is_file())
                self.assertFalse(Path(path).is_absolute())
        self.assertIn("synthetic-recovery", (ROOT / SCRIPTS["drill"])
                      .read_text(encoding="utf-8"))
        self.assertNotRegex(own, r"(?m)^(?:from|import)\s+(?:requests|httpx|urllib|socket)\b")
        self.assertNotRegex(own, r"-----BEGIN (?:RSA |OPENSSH )?PRIVATE KEY-----")
        for domain, cases in self.observed.items():
            for name, result in cases.items():
                with self.subTest(domain=domain, case=name):
                    payload = json.dumps(result).lower()
                    self.assertNotIn("bearer ", payload)
                    self.assertNotIn("github_pat_", payload)
                    self.assertNotIn("password=", payload)
                    self.assertNotIn("private_key", payload)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: local schema evidence cannot certify CI/live/recovery."""
        for key in IDS:
            with self.subTest(clause=key):
                result = verdict(key, self.table, self.files, self.observed)
                self.assertEqual(result["ci"], UNKNOWN)
                self.assertEqual(result["live"], UNKNOWN)
                self.assertEqual(result["operating"], UNKNOWN)
                if key in EXTERNAL:
                    self.assertEqual(result["offline"], UNKNOWN)
        d = self.observed["drill"]
        self.assertEqual(d["stale"]["status"], UNKNOWN)
        self.assertEqual(d["unknown"]["status"], UNKNOWN)
        self.assertEqual(d["stale"]["reported_status"], "PASSED")
        e = self.observed["evidence"]
        self.assertEqual(e["stale-checksum"]["controls"]["checksum"]["state"],
                         "unknown")
        self.assertIsNone(e["unknown-checksum"]["controls"]["checksum"]["evidence_ref"])
        self.assertEqual(e["valid"]["sources"][1]["state"], "not_applicable")


if __name__ == "__main__":
    unittest.main()
