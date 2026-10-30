#!/usr/bin/env python3
"""ControlBot #1013: synthetic-only regressions for CB-0077 through CB-0091.

Local behavior is NOT an attestation of Recovery, legal identity, deploy or live.
"""
from __future__ import annotations

import ast
import copy
from functools import lru_cache
import json
from pathlib import Path
import re
import shutil
import subprocess
import unittest

from test_matriz_cobertura import credible_offline_coverage_row

ROOT = Path(__file__).resolve().parents[1]
IDS = tuple("CB-%04d" % number for number in range(77, 92))
UNKNOWN, LOCAL = "UNKNOWN", "LOCAL_CONTRACT"
MAIN, PART = "docs/matriz-cobertura.md", "docs/matriz-cobertura-parte-01.md"
DOCUMENTS = (
    "docs/brvtal-recovery-rollout.md",
    "docs/business-os-identity.md",
    "docs/business-os-integration.md",
)
ROUTES = {key: DOCUMENTS[0 if n < 80 else 1 if n < 87 else 2]
          for n, key in ((i, "CB-%04d" % i) for i in range(77, 92))}
START = dict(zip(IDS, (
    "canonical restore drill: \x60unknown\x60;",
    "Tampoco contiene secretos, OAuth tokens",
    "La evidencia operativa futura deberá emitirse",
    "ControlBot remains the authority for business identity",
    "\x60HumanIdentity\x60, \x60AgentIdentity\x60 and \x60ServiceIdentity\x60",
    "one exact \x60scope\x60",
    "one canonical authority level",
    "There is no inherited cross-venture access.",
    "Revocation is pure and scoped:",
    "#132 evaluates capability + scope + authority",
    "solicitud lifecycle **sin identidad ni scope de cliente**",
    "Solo acepta \x60allow/deny/owner_decision_required\x60",
    "forma monotónica:",
    "registra en \x60state.audit\x60 usando el mismo \x60command_id\x60",
    "\x60product-direction\x60, con default seguro B.",
)))
SCRIPTS = {
    "rollout": "brvtal_recovery_rollout_scenarios.php",
    "venture": "venture_identity_scenarios.php",
    "rights": "decision_rights_scenarios.php",
    "access": "venture_access_runtime_scenarios.php",
}
TESTFILE = "tests/test_brechas_controlbot_06.py"


def sources() -> dict[str, str]:
    return {p: (ROOT / p).read_text(encoding="utf-8")
            for p in (MAIN, PART, *DOCUMENTS)}


def matrix_rows(files: object) -> dict[str, tuple[str, ...]]:
    if not isinstance(files, dict) or not {MAIN, PART} <= set(files):
        raise ValueError("UNKNOWN: partitions missing")
    rows = {}
    for path in (MAIN, PART):
        raw = files[path]
        if not isinstance(raw, str):
            raise ValueError("UNKNOWN: unreadable partition")
        for line in raw.splitlines():
            m = re.match(r"^\| (CB-\d{4}) \|", line)
            if m is None or m.group(1) not in IDS:
                continue
            key = m.group(1)
            cells = tuple(c.strip() for c in line.strip().strip("|").split("|"))
            expected = MAIN if key in ("CB-0080", "CB-0087") else PART
            if (key in rows or path != expected or len(cells) != 6
                    or cells[0] != key
                    or ("[" + ROUTES[key] + ":") not in cells[1]
                    or not cells[2].startswith(START[key])
                    or not credible_offline_coverage_row(cells)
                    or (cells[3] == "NO_VERIFICABLE"
                        and ("Falta contraste de comportamiento con prueba y PR concretos." not in cells[4]
                             or "Offline=UNKNOWN; live=UNKNOWN; operando=UNKNOWN." not in cells[4]))):
                raise ValueError("UNKNOWN: invalid row " + key)
            rows[key] = cells
    if set(rows) != set(IDS):
        raise ValueError("UNKNOWN: missing coverage rows")
    return rows


@lru_cache(maxsize=32)
def scenario(group: str, case: str | None = None) -> dict:
    if group not in SCRIPTS or (case is not None and not re.fullmatch(r"[a-z][a-z-]{1,40}", case)):
        raise ValueError("UNKNOWN: unapproved scenario")
    php = shutil.which("php")
    if php is None:
        raise AssertionError("UNKNOWN: missing PHP CLI")
    args = [php, "-d", "allow_url_fopen=0", str(ROOT / "tests" / SCRIPTS[group])]
    if case is not None:
        args.append(case)
    run = subprocess.run(args, cwd=ROOT, capture_output=True, text=True,
                         check=False, timeout=25)
    if run.returncode or run.stderr.strip():
        raise AssertionError("UNKNOWN: offline PHP probe failed")
    try:
        output = json.loads(run.stdout)
    except ValueError as exc:
        raise AssertionError("UNKNOWN: malformed PHP output") from exc
    if type(output) is not dict:
        raise AssertionError("UNKNOWN: non-object probe")
    return output


def evidence() -> dict[str, dict]:
    return {
        "rollout": scenario("rollout"),
        "kinds": scenario("venture", "identity-kinds"),
        "scopes": scenario("venture", "distinct-admins"),
        "authority": scenario("venture", "authority"),
        "invalid": scenario("venture", "invalid-grants"),
        "revocation": scenario("venture", "revoke"),
        "rights": scenario("rights", "allow-exact"),
        "rights_invalid": scenario("rights", "invalid"),
        "cross": scenario("rights", "cross-venture"),
        "trusted": scenario("access", "trusted"),
        "restriction": scenario("access", "restrictions"),
        "replay": scenario("access", "replay"),
        "gate": scenario("access", "owner"),
        "secrets": scenario("access", "secrets"),
    }


def classify(rows: object, proof: object, docs: object) -> dict[str, str]:
    """No partial/ambiguous fixture can be promoted to local coverage."""
    unknown = dict.fromkeys(IDS, UNKNOWN)
    if type(rows) is not dict or type(proof) is not dict or type(docs) is not dict:
        return unknown
    try:
        if (set(rows) != set(IDS) or set(proof) != set(evidence())
                or not all(isinstance(docs.get(p), str) for p in DOCUMENTS)
                or not all(len(rows[k]) == 6 and credible_offline_coverage_row(rows[k])
                           and "[" + ROUTES[k] + ":" in rows[k][1]
                           and rows[k][2].startswith(START[k]) for k in IDS)):
            return unknown
        rollout = proof["rollout"]
        rec = rollout["recovery"]
        unknown_recovery = (
            all(rec[k] == "unknown" for k in (
                "canonical_backup_receipt", "offsite_copy",
                "immutable_copy", "canonical_restore_drill"))
            and rec["observed_rpo_minutes"] is None
            and rec["demonstrated_rto_minutes"] is None
            and rec["dr_status"] == UNKNOWN
            and rec["restorable"] is False and rec["execution"] is False
        )
        sensitive = ("password", "oauth_token", "access_token", "refresh_token",
                     "api_key", "private_key", "client_secret", "storage_ref",
                     "checksum", "backup_id", "restore_id",
                     "drive.google.com", "storage.googleapis.com", "s3.amazonaws.com")
        clean = not any(k in json.dumps(rollout).lower() for k in sensitive)
        invalid = proof["invalid"]["blocked"]
        authority = proof["authority"]
        rev = proof["revocation"]
        rights = proof["rights"]
        restriction = proof["restriction"]
        replay = proof["replay"]
        gate = proof["gate"]
        checks = {
            "CB-0077": unknown_recovery and rollout["profile_status"] == "configured",
            "CB-0078": clean and not any(rollout["legacy_capability"]["canonical_contracts"].values()),
            "CB-0079": unknown_recovery and rollout["provider_decision"]["state"] == "pending",
            "CB-0080": "Product repositories remain authoritative" in docs[DOCUMENTS[1]]
                and "Factory remains authoritative" in docs[DOCUMENTS[1]]
                and "reconstruye ambos" in docs[DOCUMENTS[2]],
            "CB-0081": proof["kinds"]["kinds"] == ["human", "agent", "service"]
                and all(proof["kinds"]["blocked_sensitive"].values())
                and bool(proof["kinds"]["blocked_sensitive"]),
            "CB-0082": proof["scopes"]["scoped"] == {
                "condor": ["identity-condor-admin"],
                "grindflow": ["identity-grindflow-admin"],
                "brvtal": ["identity-brvtal-admin"],
            } and invalid["invalid-scope"] is True,
            "CB-0083": authority["owner"]["authority_level"] == "L4_OWNER"
                and authority["owner"]["scope"] == "group:group-pl0n3r"
                and authority["venture_admin"]["authority_level"] == "L2_VENTURE_ADMIN"
                and invalid["unknown-authority"] is True,
            "CB-0084": proof["cross"]["result"] == {
                "decision": "deny", "reasons": ["scope_mismatch"]
            } and invalid["expired"] is True,
            "CB-0085": rev["identity"]["identity_id"] == "identity-admin"
                and [g["grant_id"] for g in rev["grants"]] == ["grant-condor-view"]
                and [e["event_id"] for e in rev["history"]] == [
                    "event-grant-grindflow", "event-revoke-grindflow"]
                and rev["history"][-1]["scope"] == "venture:grindflow",
            "CB-0086": rights["allowed"] == {
                "decision": "allow", "reasons": ["authorized"]}
                and rights["wrong_capability"]["decision"] == "deny"
                and proof["rights_invalid"]["unknown_scope"]["decision"] == "deny",
            "CB-0087": proof["trusted"] == {"actor": True, "scope": True},
            "CB-0088": restriction["allow"]["status"] == "applied"
                and restriction["budget"]["status"] == "deny"
                and restriction["production"]["status"] == "owner_decision_required",
            "CB-0089": restriction["budget_replay"]["status"] == "deny"
                and restriction["production_replay"]["status"] == "owner_decision_required"
                and restriction["budget"]["state"]["identity"]["state"] == "active",
            "CB-0090": replay["first"]["status"] == "applied"
                and replay["replay"]["status"] == "already_applied"
                and replay["replay"]["audit"]["outcome"] == "applied"
                and replay["first"]["audit"]["occurred_at"]
                    == replay["replay"]["audit"]["occurred_at"],
            "CB-0091": gate["status"] == "owner_decision_required"
                and gate["parsed_gate"]["category"] == "product-direction"
                and gate["parsed_gate"]["safe_default"] == "B"
                and gate["state"]["grants"][0]["capability"] == "venture.manage",
        }
    except (KeyError, IndexError, TypeError, ValueError, AttributeError):
        return unknown
    return {key: LOCAL if checks[key] is True else UNKNOWN for key in IDS}


def operational_state(_key: object, _evidence: object = None) -> str:
    return UNKNOWN


class BrechasControlbot06Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        files = sources()
        rows = matrix_rows(files)
        self.assertEqual(set(rows), set(IDS))
        results = classify(rows, evidence(), files)
        for key in IDS:
            with self.subTest(key=key):
                self.assertEqual(results[key], LOCAL)
                self.assertEqual(operational_state(key), UNKNOWN)

    def test_falla_cerrado(self) -> None:
        files, proof = sources(), evidence()
        rows = matrix_rows(files)
        for key in IDS:
            with self.subTest(key=key):
                missing = dict(rows)
                missing.pop(key)
                self.assertEqual(classify(missing, proof, files)[key], UNKNOWN)
                changed = dict(rows)
                cells = list(rows[key])
                if cells[3] == "NO_VERIFICABLE":
                    cells[3] = "CUBIERTO"
                else:
                    cells[4] = cells[4].replace("MERGED", "PENDING")
                changed[key] = tuple(cells)
                self.assertEqual(classify(changed, proof, files)[key], UNKNOWN)
                altered = dict(files)
                path = MAIN if key in ("CB-0080", "CB-0087") else PART
                row = next(s for s in altered[path].splitlines()
                           if s.startswith("| " + key + " |"))
                altered[path] += "\n" + row
                with self.assertRaisesRegex(ValueError, UNKNOWN):
                    matrix_rows(altered)
        self.assertEqual(classify(rows, {}, files), dict.fromkeys(IDS, UNKNOWN))
        for field, key in (("rollout", "CB-0077"), ("scopes", "CB-0082"),
                           ("rights", "CB-0086"), ("restriction", "CB-0089"),
                           ("gate", "CB-0091")):
            damaged = copy.deepcopy(proof)
            damaged[field] = None
            self.assertEqual(classify(rows, damaged, files)[key], UNKNOWN)
        self.assertEqual(operational_state("CB-0077", {"ci": "success"}), UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        source = (ROOT / TESTFILE).read_text(encoding="utf-8")
        ast.parse(source)
        for file in SCRIPTS.values():
            php_source = (ROOT / "tests" / file).read_text(encoding="utf-8").lower()
            for forbidden in ("curl_", "fsockopen", "stream_socket_client",
                              "mysqli_connect", "shell_exec", "proc_open", "new pdo"):
                self.assertNotIn(forbidden, php_source)
        proof = evidence()
        self.assertTrue(all(proof["secrets"]["blocked"].values()))
        self.assertTrue(all(proof["kinds"]["blocked_sensitive"].values()))
        self.assertFalse(proof["rollout"]["recovery"]["execution"])
        self.assertTrue(all(k in DOCUMENTS for k in ROUTES.values()))

    def test_estados_unknown_explicitos(self) -> None:
        files, proof = sources(), evidence()
        result = classify(matrix_rows(files), proof, files)
        for key in IDS:
            self.assertEqual(result[key], LOCAL)
            for fabricated in (None, True, {}, {"sha": "f" * 40, "ci": "success"},
                               {"state": "VALIDATED_IN_PRODUCTION"}):
                with self.subTest(key=key):
                    self.assertEqual(operational_state(key, fabricated), UNKNOWN)
        self.assertFalse(proof["rollout"]["recovery"]["restorable"])
        self.assertEqual(proof["gate"]["parsed_gate"]["safe_default"], "B")


if __name__ == "__main__":
    unittest.main()
