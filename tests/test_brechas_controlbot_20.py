#!/usr/bin/env python3
"""ControlBot #1027: offline regression contracts for CB-0287..CB-0301.

Exercise versioned synthetic PHP fixtures for external access and monitoring.
Offline contracts do NOT attest production health, external authority or live
provider evidence. Never reconcile the coverage matrix from this leaf.
"""
from __future__ import annotations

import json
import os
import re
import subprocess
import unittest
from functools import lru_cache
from pathlib import Path

from test_matriz_cobertura import credible_offline_coverage_row as _canonical_coverage_row

ROOT = Path(__file__).resolve().parents[1]
IDS = tuple(f"CB-{n:04d}" for n in range(287, 302))
UNKNOWN = "UNKNOWN"
MATRICES = tuple(
    ["docs/matriz-cobertura.md"]
    + [f"docs/matriz-cobertura-parte-{n:02d}.md" for n in range(1, 5)]
)
# Reconciliación #1255: aceptar solo la evidencia documental offline E2 conocida.
# La función canónica cubre E1 y no incluye los PRs del tramo CB-0292..CB-0594.
_E2_PROVENANCE = (
    (292, 309, 1294, 19, 1202), (311, 328, 1285, 20, 1203),
    (329, 343, 1281, 21, 1204), (344, 362, 1277, 22, 1205),
    (363, 381, 1278, 23, 1206), (382, 396, 1279, 24, 1207),
    (397, 414, 1282, 25, 1208), (415, 431, 1273, 26, 1209),
    (432, 451, 1274, 27, 1210), (452, 471, 1275, 28, 1211),
    (472, 488, 1276, 29, 1212), (490, 505, 1283, 30, 1213),
    (506, 513, 1284, 31, 1214), (514, 531, 1289, 32, 1215),
    (533, 547, 1293, 33, 1216), (548, 559, 1297, 34, 1217),
    (560, 579, 1262, 35, 1218), (581, 594, 1263, 36, 1219),
)


def credible_offline_coverage_row(row):
    """E1 y E2 con procedencia verificable, nunca validación productiva."""
    if not isinstance(row, (tuple, list)) or len(row) != 6:
        return False
    if row[3] == "NO_VERIFICABLE":
        if not isinstance(row[4], str):
            return False
        reason = re.split(r"(?i)offline\s*=\s*unknown", row[4], maxsplit=1)[0]
        if len(reason.strip()) < 12:
            return False
    if _canonical_coverage_row(row):
        return True
    key, source, requirement, status, evidence, reference = row
    if (status != "CUBIERTO" or not isinstance(key, str)
            or re.fullmatch(r"CB-\d{4}", key) is None
            or not isinstance(source, str) or not source.startswith("[docs/")
            or not isinstance(requirement, str) or not requirement.strip()
            or not isinstance(reference, str) or not reference.startswith("[#")
            or not isinstance(evidence, str)):
        return False
    match = re.fullmatch(
        r"Código (src/[A-Za-z0-9_.-]+\.php|scripts/hostinger_go_live_plan\.py); "
        r"prueba (tests/test_cobertura_controlbot_(\d{2})\.py); "
        r"PR #(\d+) MERGED; requisito (CB-\d{4}) "
        r"ejercido contra código real con fixtures offline \(#(\d+)\); "
        r"sin validación productiva; live=UNKNOWN; operando=UNKNOWN\.",
        evidence,
    )
    if match is None or match.group(5) != key:
        return False
    number = int(key[3:])
    expected = next(((pr, test, issue) for low, high, pr, test, issue
                     in _E2_PROVENANCE if low <= number <= high), None)
    if expected != (int(match.group(4)), int(match.group(3)),
                    int(match.group(6))):
        return False
    return (ROOT.joinpath(match.group(1)).is_file()
            and ROOT.joinpath(match.group(2)).is_file())


DOCS = {
    **{f"CB-{n:04d}": "external-api-threat-model-v1.md" for n in (287,)},
    **{f"CB-{n:04d}": "external-api-trusted-request-gate-v1.md" for n in range(288, 292)},
    **{f"CB-{n:04d}": "external-api-v1.md" for n in range(292, 296)},
    "CB-0296": "external-monitor-adapter-v1.md",
    **{f"CB-{n:04d}": "external-monitor-core-v1.md" for n in range(297, 302)},
}
HINTS = {
    "CB-0287": "contexto nominal de sesión externa",
    "CB-0288": "VerifiedAccessContext",
    "CB-0289": "no exige step-up",
    "CB-0290": "step_up_required",
    "CB-0291": "refs opacas",
    "CB-0292": "autoridad nunca proviene del payload",
    "CB-0293": "X-Request-ID",
    "CB-0294": "400=invalid_request",
    "CB-0295": "idempotency_key",
    "CB-0296": "Solo HTTPS",
    "CB-0297": "ProbeObservation",
    "CB-0298": "WorkflowObservation",
    "CB-0299": "normal/warning/critical",
    "CB-0300": "fresh 2xx",
    "CB-0301": "startup_failure",
}
SCRIPTS = {
    "gate": "external_api_request_gate_scenarios.php",
    "session": "external_api_session_scenarios.php",
    "contract": "external_api_contract_scenarios.php",
    "adapter": "external_monitor_adapter_scenarios.php",
    "monitor": "external_monitor_scenarios.php",
}
CASES = {
    "gate": frozenset(("read", "deny", "mutation", "auth_fail", "client", "trusted_mismatch")),
    "session": frozenset(("binding", "lifetime", "stepup", "inventory")),
    "contract": frozenset(("catalog", "envelopes", "mutation")),
    "adapter": frozenset(("probe", "failures", "pure")),
    "monitor": frozenset(("probe", "freshness", "workflow", "alert")),
}
ENV_ALLOWLIST = frozenset((
    "PATH", "HOME", "LANG", "LC_ALL", "TZ", "TMPDIR", "SYSTEMROOT",
    "CONTROLBOT_REAL_PHP", "CONTROLBOT_PHP_COVERAGE_BOOTSTRAP",
    "CONTROLBOT_PHP_COVERAGE_DIR", "CONTROLBOT_REPO_ROOT", "XDEBUG_MODE",
))


def matrix_texts() -> dict[str, str]:
    return {path: (ROOT / path).read_text(encoding="utf-8") for path in MATRICES}


def matrix_rows(documents: dict[str, str]) -> dict[str, tuple[str, ...]]:
    """Require one documented, unpromoted clause in the partitioned matrix."""
    found: dict[str, tuple[str, ...]] = {}
    for path in MATRICES:
        if not isinstance(documents.get(path), str):
            raise ValueError("UNKNOWN: missing matrix partition")
        for line in documents[path].splitlines():
            if not line.startswith("| CB-"):
                continue
            cells = tuple(x.strip() for x in line.strip().strip("|").split("|"))
            if not cells or cells[0] not in IDS:
                continue
            clause = cells[0]
            if clause in found:
                raise ValueError("UNKNOWN: duplicate clause " + clause)
            if (len(cells) != 6 or DOCS[clause] not in cells[1]
                    or HINTS[clause].casefold() not in cells[2].casefold()
                    or not credible_offline_coverage_row(cells)
                    or (cells[3] == "NO_VERIFICABLE"
                        and not all(x in cells[4] for x in (
                            "Offline=UNKNOWN", "live=UNKNOWN", "operando=UNKNOWN"
                        )))):
                raise ValueError("UNKNOWN: malformed clause " + clause)
            found[clause] = cells
    if set(found) != set(IDS):
        raise ValueError("UNKNOWN: missing clauses " + repr(sorted(set(IDS) - set(found))))
    return found


def offline_environment() -> dict[str, str]:
    """Forward only local CLI/coverage plumbing; never provider credentials."""
    return {key: value for key, value in os.environ.items() if key in ENV_ALLOWLIST}


@lru_cache(maxsize=32)
def scenario(domain: str, case: str) -> dict:
    if domain not in SCRIPTS or case not in CASES[domain]:
        raise ValueError("UNKNOWN: unapproved offline case")
    result = subprocess.run(
        ["php", str(ROOT / "tests" / SCRIPTS[domain]), case],
        cwd=ROOT, env=offline_environment(), capture_output=True,
        text=True, check=False, timeout=35,
    )
    if result.returncode:
        raise AssertionError("UNKNOWN: synthetic scenario failed: " + domain + "/" + case)
    parsed = json.loads(result.stdout)
    if not isinstance(parsed, dict):
        raise ValueError("UNKNOWN: non-object fixture")
    return parsed


def operational_state(_clause: str, _observation: object = None) -> str:
    """Fixtures cannot verify production, live sessions, or external probes."""
    return UNKNOWN


def opaque(value: object, prefix: str) -> bool:
    return isinstance(value, str) and re.fullmatch(
        re.escape(prefix) + r":[a-f0-9]{32}", value
    ) is not None


class BrechasControlbot20Tests(unittest.TestCase):
    def test_evidencia_e2_estricta_y_negativos(self) -> None:
        """La promoción offline #1255 no equivale a autorización live."""
        text = (ROOT / "docs/matriz-cobertura-parte-02.md").read_text(encoding="utf-8")
        line = next(line for line in text.splitlines()
                    if line.startswith("| CB-0487 |"))
        baseline = [cell.strip() for cell in line.strip("|").split("|")]
        self.assertTrue(credible_offline_coverage_row(baseline))
        verified = list(baseline)
        verified[3] = "CUBIERTO"
        verified[4] = (
            "Código src/GitHubReadOnlyProjectionWriter.php; "
            "prueba tests/test_cobertura_controlbot_29.py; "
            "PR #1276 MERGED; requisito CB-0487 ejercido contra código real "
            "con fixtures offline (#1212); sin validación productiva; "
            "live=UNKNOWN; operando=UNKNOWN."
        )
        self.assertTrue(credible_offline_coverage_row(verified))
        for before, after in (
            ("MERGED", "PENDING"),
            ("PR #1276", "PR #1275"),
            ("(#1212)", "(#1213)"),
            ("CB-0487 ejercido", "CB-0491 ejercido"),
            ("src/GitHubReadOnlyProjectionWriter.php", "src/NoExiste.php"),
            ("tests/test_cobertura_controlbot_29.py",
             "tests/test_cobertura_controlbot_99.py"),
            ("live=UNKNOWN", "live=VALIDATED_IN_PRODUCTION"),
            ("operando=UNKNOWN", "operando=READY"),
        ):
            candidate = list(verified)
            candidate[4] = candidate[4].replace(before, after)
            with self.subTest(change=after):
                self.assertFalse(credible_offline_coverage_row(candidate))
        python_line = next(line for line in text.splitlines()
                           if line.startswith("| CB-0518 |"))
        python_row = [cell.strip() for cell in python_line.strip("|").split("|")]
        python_row[3] = "CUBIERTO"
        python_row[4] = (
            "Código scripts/hostinger_go_live_plan.py; "
            "prueba tests/test_cobertura_controlbot_32.py; "
            "PR #1289 MERGED; requisito CB-0518 ejercido contra código real "
            "con fixtures offline (#1215); sin validación productiva; "
            "live=UNKNOWN; operando=UNKNOWN."
        )
        self.assertTrue(credible_offline_coverage_row(python_row))
        python_row[4] = python_row[4].replace(
            "scripts/hostinger_go_live_plan.py", "scripts/unsafe.py"
        )
        self.assertFalse(credible_offline_coverage_row(python_row))
        without_reason = list(baseline)
        without_reason[4] = "Offline=UNKNOWN; live=UNKNOWN; operando=UNKNOWN."
        self.assertFalse(credible_offline_coverage_row(without_reason))
        without_reason[4] = None
        self.assertFalse(credible_offline_coverage_row(without_reason))

    def test_contrato_principal(self) -> None:
        """AC-01: each of the fifteen clauses exercises concrete local behavior."""
        gate_read = scenario("gate", "read")
        gate_mut = scenario("gate", "mutation")
        gate_deny = scenario("gate", "deny")
        session_life = scenario("session", "lifetime")
        session_step = scenario("session", "stepup")
        session_bind = scenario("session", "binding")
        client = scenario("gate", "client")
        mismatch = scenario("gate", "trusted_mismatch")
        envelopes = scenario("contract", "envelopes")
        mutation = scenario("contract", "mutation")
        adapter = scenario("adapter", "probe")
        adapter_fail = scenario("adapter", "failures")
        pure = scenario("adapter", "pure")
        probe = scenario("monitor", "probe")
        freshness = scenario("monitor", "freshness")
        workflow = scenario("monitor", "workflow")
        alert = scenario("monitor", "alert")
        openapi = json.loads(
            (ROOT / "openapi/controlbot-owner-v1.json").read_text(encoding="utf-8")
        )
        trusted = (ROOT / "src/ExternalApiRequestGate.php").read_text(encoding="utf-8")
        adapter_source = (ROOT / "src/ExternalMonitorAdapter.php").read_text(encoding="utf-8")
        monitor_source = (ROOT / "src/ExternalMonitorCore.php").read_text(encoding="utf-8")
        runner_source = (ROOT / "scripts/external-monitor.php").read_text(encoding="utf-8")
        errors = {
            "400": ["invalid_request"], "401": ["unauthenticated"],
            "403": ["forbidden"], "404": ["not_found"],
            "409": ["conflict", "stale_state"], "429": ["rate_limited"],
            "500": ["internal_error"],
        }
        all_rows = [gate_read["valid"], gate_read["with_step"], gate_mut["with"]]
        verdicts = {
            "CB-0287": (
                all(session_life[key] is True for key in (
                    "too_long", "expired", "revoked_session", "revoked_device"
                )) and all(session_step[key] is True for key in (
                    "expired", "wrong_session", "wrong_device", "revoked_step_up"
                )) and session_bind["wrong_scope"] is True
                and gate_mut["without"]["decision"] == "step_up_required"
            ),
            "CB-0288": (
                all(value is True for value in mismatch.values())
                and gate_read["valid"]["decision"] == "allow"
                and "ExternalApiAccess::authorize" in trusted
            ),
            "CB-0289": (
                gate_read["valid"]["decision"] == "allow"
                and gate_read["valid"]["step_up_ref"] is None
                and gate_read["revoked_device"] is True
            ),
            "CB-0290": (
                gate_mut["without"]["decision"] == "step_up_required"
                and gate_mut["without"]["step_up_ref"] is None
                and gate_mut["with"]["decision"] == "allow"
                and opaque(gate_mut["with"]["step_up_ref"], "stepup")
                and all(scenario("gate", "auth_fail").values())
            ),
            "CB-0291": (
                all(opaque(row["device_ref"], "device")
                        and opaque(row["session_ref"], "session") for row in all_rows)
                and all_rows[0]["step_up_ref"] is None
                and all(opaque(row["step_up_ref"], "stepup") for row in all_rows[1:])
                and gate_deny["decision"] == "deny"
                and gate_deny["step_up_ref"] is None
            ),
            "CB-0292": (
                set(mutation["server_fields_rejected"]) >= {
                    "identity", "role", "authority_level", "policy_ref",
                    "grant", "provider_credential", "execution_target"
                } and all(mutation["server_fields_rejected"].values())
                and all(value is True for value in client.values())
            ),
            "CB-0293": (
                re.fullmatch(r"[a-f0-9]{32}", envelopes["ids"]["request_id"]) is not None
                and re.fullmatch(r"[a-f0-9]{32}", envelopes["ids"]["correlation_id"]) is not None
                and isinstance(envelopes["meta"]["generated_at"], int)
                and isinstance(envelopes["meta"]["freshness"]["observed_at"], int)
                and envelopes["unknown"]["freshness"] == {
                    "state": "unknown", "observed_at": None, "source_ref": None
                }
            ),
            "CB-0294": all(
                openapi["components"]["responses"]["Error" + status]["x-error-codes"]
                == codes for status, codes in errors.items()
            ) and envelopes["error"]["code"] == "forbidden",
            "CB-0295": (
                set(mutation["valid"]) == {"version", "outcome", "idempotency_key"}
                and mutation["valid"]["version"] == 1
                and mutation["valid"]["outcome"] == "approve"
                and re.fullmatch(r"[a-f0-9]{32,64}", mutation["valid"]["idempotency_key"]) is not None
                and mutation["missing_idempotency_rejected"] is True
                and mutation["bad_idempotency_rejected"] is True
                and all("requestBody" not in op["get"]
                        for op in openapi["paths"].values() if "get" in op)
            ),
            "CB-0296": (
                adapter_fail["oversized_rejected"] is True
                and pure["hits"] == [] and pure["actions"] is False
                and pure["embedded"] is False
                and "endpointUrl" in adapter_source
                and "parse_url" in adapter_source
                and "isset($parts['query'])" in adapter_source
                and "isset($parts['fragment'])" in adapter_source
                and "CURLOPT_WRITEFUNCTION" in runner_source
                and "CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS" in runner_source
                and "CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS" in runner_source
            ),
            "CB-0297": (
                set(adapter) == {
                    "version", "endpoint_kind", "observed_at", "outcome",
                    "http_status", "latency_ms", "reported_version",
                    "reported_sha", "freshness", "source_ref"
                } and adapter["version"] == 1
                and adapter["endpoint_kind"] == "health"
                and adapter["observed_at"] == 1000
                and adapter["http_status"] == 200
                and adapter["reported_version"] == "1.2.3"
                and re.fullmatch(r"[a-f0-9]{40}", adapter["reported_sha"]) is not None
                and adapter["freshness"] == "fresh"
                and adapter["source_ref"] == "controlbot:probe"
            ),
            "CB-0298": (
                set(workflow["failure"]["latest_private_workflow"]) == {
                    "version", "visibility", "observed_at", "conclusion",
                    "runner_assigned", "steps_present", "sha", "source_ref"
                }
                and workflow["failure"]["latest_private_workflow"]["visibility"] == "private"
                and workflow["failure"]["latest_private_workflow"]["conclusion"] == "failure"
                and workflow["failure"]["latest_private_workflow"]["runner_assigned"] is True
                and workflow["failure"]["latest_private_workflow"]["steps_present"] is True
            ),
            "CB-0299": (
                "'normal','warning','critical','exhausted','blocked','unknown'" in monitor_source
                and alert["capacity"]["owner_capacity_state"] == "exhausted"
                and alert["critical_capacity"]["owner_capacity_state"] == "degraded"
            ),
            "CB-0300": (
                probe["healthy"]["application_state"] == "healthy"
                and probe["timeout"]["application_state"] == "down"
                and probe["server"]["application_state"] == "down"
                and freshness["missing"]["application_state"] == "unknown"
                and freshness["unknown"]["application_state"] == "unknown"
                and freshness["stale"]["application_state"] == "degraded"
                and freshness["expired"]["application_state"] == "degraded"
                and probe["invalid"] is True
            ),
            "CB-0301": (
                workflow["failure"]["private_workflow_state"] == "degraded"
                and "private_workflow_step_failure" in workflow["failure"]["reasons"]
                and workflow["startup"]["private_workflow_state"] == "blocked"
                and "private_startup_failure_without_runner" in workflow["startup"]["reasons"]
                and workflow["startup"]["latest_private_workflow"]["runner_assigned"] is False
                and workflow["startup"]["latest_private_workflow"]["steps_present"] is False
                and workflow["startup_null_steps"]["private_workflow_state"] == "blocked"
                and workflow["startup_null_steps"]["latest_private_workflow"]["steps_present"] is None
                and workflow["invalid"] is True
            ),
        }
        self.assertEqual(set(verdicts), set(IDS))
        rows = matrix_rows(matrix_texts())
        for clause in IDS:
            with self.subTest(clause=clause):
                self.assertTrue(credible_offline_coverage_row(rows[clause]))
                self.assertTrue(verdicts[clause], f"offline behavior missing: {clause}")

    def test_falla_cerrado(self) -> None:
        """AC-02: malformed matrices and unverified authority do not succeed."""
        documents = matrix_texts()
        rows = matrix_rows(documents)
        first = IDS[0]
        location = next(
            path for path in MATRICES
            if any(line.startswith(f"| {first} |") for line in documents[path].splitlines())
        )
        line = next(
            line for line in documents[location].splitlines()
            if line.startswith(f"| {first} |")
        )
        removed = dict(documents)
        removed[location] = removed[location].replace(line + "\n", "")
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            matrix_rows(removed)
        doubled = dict(documents)
        doubled[location] += "\n" + line + "\n"
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            matrix_rows(doubled)
        altered = dict(documents)
        altered[location] = altered[location].replace(line, (line.replace("MERGED", "PENDING") if "CUBIERTO" in line
                                   else line.replace("NO_VERIFICABLE", "CUBIERTO")))
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            matrix_rows(altered)
        self.assertTrue(credible_offline_coverage_row(rows[first]))
        self.assertTrue(scenario("gate", "read")["revoked_device"])
        self.assertTrue(all(scenario("gate", "trusted_mismatch").values()))
        self.assertTrue(scenario("contract", "mutation")["missing_idempotency_rejected"])
        self.assertTrue(scenario("adapter", "failures")["oversized_rejected"])
        self.assertEqual(scenario("monitor", "freshness")["missing"]["application_state"], "unknown")
        self.assertEqual(scenario("monitor", "workflow")["startup"]["private_workflow_state"], "blocked")

    def test_sin_datos_reales(self) -> None:
        """AC-03: local PHP fixtures and synthetic refs; no external credentials."""
        self.assertTrue(set(SCRIPTS) == set(CASES))
        self.assertTrue(all((ROOT / "tests" / path).is_file() for path in SCRIPTS.values()))
        self.assertFalse(set(offline_environment()) - ENV_ALLOWLIST)
        self.assertFalse(any(
            re.search(r"(?i)(?:password|bearer|secret|cookie|api.?key|access.?token)", key)
            for key in offline_environment()
        ))
        self.assertTrue(all(
            opaque(item["device_ref"], "device")
            and opaque(item["session_ref"], "session")
            for item in (
                scenario("gate", "read")["valid"],
                scenario("gate", "mutation")["with"],
            )
        ))
        serialized = json.dumps({
            "gate": scenario("gate", "read")["valid"],
            "mutation": scenario("contract", "mutation")["valid"],
            "probe": scenario("adapter", "probe"),
            "workflow": scenario("monitor", "workflow")["failure"]["latest_private_workflow"],
        }, sort_keys=True).lower()
        for forbidden in ("password", "bearer ", "refresh_token", "access_token",
                          "provider_credential", "private_key", "session_secret"):
            self.assertNotIn(forbidden, serialized)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: local success never asserts live HEALTHY or authorized mutability."""
        rows = matrix_rows(matrix_texts())
        for clause in IDS:
            with self.subTest(clause=clause):
                self.assertEqual(operational_state(clause), UNKNOWN)
                self.assertEqual(operational_state(clause, {"offline": "pass"}), UNKNOWN)
                self.assertTrue(credible_offline_coverage_row(rows[clause]))
                self.assertIn("live=UNKNOWN", rows[clause][4])
                self.assertIn("operando=UNKNOWN", rows[clause][4])
        self.assertEqual(
            scenario("monitor", "freshness")["missing"]["application_state"], "unknown"
        )
        self.assertEqual(
            scenario("gate", "mutation")["without"]["decision"], "step_up_required"
        )
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            scenario("monitor", "real_provider_request")


if __name__ == "__main__":
    unittest.main()
