#!/usr/bin/env python3
"""ControlBot #1069: CB-0952..CB-0966, reproducible offline coverage.

Tests exercise existing pure PHP scenario scripts with fictitious fixtures.
A green local contract is not a production, legal, backup, owner-approval or
Hostinger attestation; unknown operational evidence always stays UNKNOWN.
"""
from __future__ import annotations

from test_brechas_controlbot_20 import credible_e4_coverage_row

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
LOCAL = "LOCAL_PARTIAL"
IDS = tuple(f"CB-{i:04d}" for i in range(952, 967))
MATRICES = (
    "docs/matriz-cobertura.md",
    "docs/matriz-cobertura-parte-04.md",
)
PROMOTION_DOC = "docs/prompt-promotion-decision-v1.md"
REGISTRY_DOC = "docs/prompt-registry-v1.md"
UI_DOC = "docs/prompt-ui-v1.md"
WEB_DOC = "docs/public-orchestrator-entrypoint.md"
WEB_SOURCE = "src/FactoryOrchestratorWebEntrypoint.php"
PUBLIC_INDEX = "public/index.php"
HTACCESS = ".htaccess"
CRON_DOC = "docs/runbooks/orchestrator-snapshot-cron.md"
PATH_TEST = "tests/test_orchestrator_snapshot_path_env.py"
UI_SOURCE = "src/PromptUi.php"
PROMOTION_SOURCE = "src/PromptPromotionDecision.php"
REGISTRY_SOURCE = "src/PromptRegistry.php"

DOCS = {
    **{f"CB-{n:04d}": PROMOTION_DOC for n in range(952, 956)},
    **{f"CB-{n:04d}": REGISTRY_DOC for n in range(956, 959)},
    **{f"CB-{n:04d}": UI_DOC for n in range(959, 962)},
    **{f"CB-{n:04d}": WEB_DOC for n in range(962, 967)},
}
# Infrastructure approval and the real HTTP authentication boundary are not
# provable by an in-repo PHP simulation. They remain explicit UNKNOWN.
DOCUMENT_ONLY = frozenset({"CB-0962", "CB-0963"})
PROBES = {
    "promotion": ("prompt_promotion_decision_scenarios.php",
                  ("decision", "binding", "human_gate", "provenance", "pure", "provenance_format")),
    "registry": ("prompt_registry_scenarios.php",
                 ("immutable", "approved", "invalid", "rollback", "secret", "pure")),
    "ui": ("prompt_ui_scenarios.php",
           ("projection", "hold", "unsafe", "result_binding",
            "metric_substitution", "safety_policy_substitution",
            "candidate_contract", "binding", "secret", "pure")),
    "web": ("factory_orchestrator_web_entrypoint_scenarios.php",
            ("auth", "unknown")),
}
SOURCES = frozenset(
    (*MATRICES, *DOCS.values(), WEB_SOURCE, PUBLIC_INDEX,
     HTACCESS, CRON_DOC, PATH_TEST, UI_SOURCE, PROMOTION_SOURCE, REGISTRY_SOURCE)
)


def sources() -> dict[str, str]:
    """No remote endpoint, secret, runtime environment or production record."""
    return {path: (ROOT / path).read_text(encoding="utf-8") for path in SOURCES}


def matrix_rows(data: object) -> dict[str, str]:
    """Keep exact identity and NO_VERIFICABLE on all fifteen unique rows."""
    if not isinstance(data, dict):
        raise ValueError("UNKNOWN: matrices unavailable")
    found: dict[str, str] = {}
    for path in MATRICES:
        raw = data.get(path)
        if not isinstance(raw, str):
            raise ValueError("UNKNOWN: matrix source missing")
        for line in raw.splitlines():
            matched = re.match(r"^\| (CB-\d{4}) \|", line)
            if matched is None or matched.group(1) not in IDS:
                continue
            key = matched.group(1)
            if key in found:
                raise ValueError("UNKNOWN: duplicated coverage row " + key)
            if (not line.startswith(f"| {key} | [{DOCS[key]}:")
                    or not credible_e4_coverage_row(tuple(x.strip() for x in line.strip().strip("|").split("|"))):
                raise ValueError("UNKNOWN: promoted or ambiguous coverage row " + key)
            found[key] = line
    if set(found) != set(IDS):
        raise ValueError("UNKNOWN: missing coverage IDs")
    return found


@lru_cache(maxsize=None)
def probe(group: str, scenario: str) -> object:
    """Execute ONLY allowlisted local PHP fixtures, never arbitrary commands."""
    if group not in PROBES:
        raise ValueError("UNKNOWN: unknown PHP fixture group")
    script, names = PROBES[group]
    if scenario not in names:
        raise ValueError("UNKNOWN: unapproved scenario")
    php = shutil.which("php")
    if php is None:
        raise AssertionError("UNKNOWN: PHP CLI unavailable")
    task = subprocess.run(
        [php, str(ROOT / "tests" / script), scenario],
        cwd=ROOT, check=False, capture_output=True, text=True, timeout=40,
    )
    if task.returncode or task.stderr.strip():
        raise AssertionError("UNKNOWN: PHP fixture failed: " + group + ":" + scenario)
    if scenario in ("projection", "hold", "unsafe"):
        return task.stdout
    try:
        return json.loads(task.stdout)
    except (TypeError, ValueError) as exc:
        raise AssertionError("UNKNOWN: fixture JSON invalid") from exc


def observations() -> dict[str, object]:
    return {f"{group}:{name}": probe(group, name)
            for group, (_, names) in PROBES.items() for name in names}


def _field(obs: dict[str, object], group: str, scenario: str) -> object:
    return obs.get(group + ":" + scenario)


def local_checks(data: object, observed: object) -> dict[str, object]:
    """One independent, checkable predicate or explicit gap per matrix ID."""
    if not isinstance(data, dict) or not isinstance(observed, dict):
        return {key: UNKNOWN for key in IDS}
    try:
        matrix_rows(data)
    except ValueError:
        return {key: UNKNOWN for key in IDS}
    if any(not isinstance(data.get(p), str) or not data[p] for p in SOURCES):
        return {key: UNKNOWN for key in IDS}
    if any(not isinstance(data[DOCS[k]], str) for k in IDS):
        return {key: UNKNOWN for key in IDS}
    try:
        decision = _field(observed, "promotion", "decision")
        binding = _field(observed, "promotion", "binding")
        gate = _field(observed, "promotion", "human_gate")
        provenance = _field(observed, "promotion", "provenance")
        pure_promotion = _field(observed, "promotion", "pure")
        formats = _field(observed, "promotion", "provenance_format")
        immutable = _field(observed, "registry", "immutable")
        approved = _field(observed, "registry", "approved")
        invalid = _field(observed, "registry", "invalid")
        rollback = _field(observed, "registry", "rollback")
        secret_registry = _field(observed, "registry", "secret")
        pure_registry = _field(observed, "registry", "pure")
        result = _field(observed, "ui", "result_binding")
        metrics = _field(observed, "ui", "metric_substitution")
        policy = _field(observed, "ui", "safety_policy_substitution")
        candidate = _field(observed, "ui", "candidate_contract")
        ui_binding = _field(observed, "ui", "binding")
        ui_secret = _field(observed, "ui", "secret")
        ui_pure = _field(observed, "ui", "pure")
        ui_projection = _field(observed, "ui", "projection")
        ui_hold = _field(observed, "ui", "hold")
        ui_unsafe = _field(observed, "ui", "unsafe")
        web_auth = _field(observed, "web", "auth")
        web_unknown = _field(observed, "web", "unknown")
        doc = data[WEB_DOC]
        htaccess = data[HTACCESS]
        private = "/home/u151692719/domains/control.condorapp.com.co/private/orchestrator-live.json"
        configured = "SetEnv CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH " + private
        # Validate complete configuration LINE, not arbitrary URL substring.
        configured_lines = htaccess.splitlines()
        expected_private = (
            configured_lines.count(configured) == 1
            and "/public_html/" not in private
            and "$HOME/domains/control.condorapp.com.co/private/orchestrator-live.json" in doc
            and private in data[CRON_DOC]
        )
        unknown_snapshots = (
            isinstance(web_unknown, dict)
            and set(web_unknown) == {"missing", "invalid", "stale"}
            and all(
                item["status"] == 200
                and json.loads(item["body"])["snapshot"]["central"]["activity_state"] == UNKNOWN
                and json.loads(item["body"])["snapshot"]["fronts"] == []
                for item in web_unknown.values()
            )
        )
        return {
            "CB-0952": (
                decision["hold"]["decision"] == "hold"
                and all(binding.values())
                and decision["better"]["decision"] == "eligible_for_human_approval"
            ),
            "CB-0953": (
                provenance["out"]["evaluation_fingerprint"] == provenance["evaluation"]["fingerprint"]
                and all(provenance["out"][k] == provenance["evaluation"][k]
                        for k in ("evaluation_set_fingerprint",
                                  "current_result_fingerprint", "candidate_result_fingerprint"))
                and provenance["tampered_current"] is True
                and provenance["tampered_candidate"] is True
                and all(formats.values())
            ),
            "CB-0954": (
                gate["human_gate_required"] is True
                and gate["authority"] == "human_approval_required"
                and pure_promotion["authority"] == "human_approval_required"
            ),
            "CB-0955": (
                gate["history_unchanged"] is True
                and gate["current_status"] == "approved"
                and gate["candidate_status"] == "candidate"
                and pure_promotion["hits"] == []
                and pure_promotion["human_gate_required"] is True
            ),
            "CB-0956": (
                approved["selected"]["status"] == "approved"
                and approved["missing_fails_closed"] is True
                and pure_registry["methods"] == ["active", "register", "rollback"]
                and "file_put_contents" not in data[REGISTRY_SOURCE].lower()
            ),
            "CB-0957": (
                immutable["first_unchanged"] is True
                and immutable["versions"] == [1, 2]
                and immutable["second_status"] == "candidate"
                and invalid["duplicate"] is True
                and invalid["schema_drift"] is True
                and invalid["history_limit"] is True
            ),
            "CB-0958": (
                rollback["current"] == 3
                and rollback["rollback"] == 1
                and rollback["history_unchanged"] is True
                and rollback["non_approved_rejected"] is True
            ),
            "CB-0959": (
                result == {"current": True, "candidate": True, "rendered": True}
                and metrics["rejected"] is True
                and policy == {"safety_rejected": True, "policy_rejected": True}
            ),
            "CB-0960": (
                'data-decision="hold"' in ui_hold
                and 'data-decision="hold"' in ui_unsafe
                and "Elegible para aprobación humana" in ui_projection
                and candidate["rejected"] is True
                and ui_binding["rejected"] is True
                and "<form" not in ui_projection.lower()
            ),
            "CB-0961": (
                ui_pure["hits"] == []
                and ui_pure["escaper"] is True
                and ui_secret["rejected"] is True
                and secret_registry == {"variable": True, "body": True, "actor": True}
                and "htmlspecialchars(" in data[UI_SOURCE]
                and all(tag not in ui_projection.lower()
                        for tag in ("<input", "<form", "<button"))
            ),
            "CB-0962": UNKNOWN,  # D-059 / Hostinger backup is owner evidence, not an offline test.
            "CB-0963": UNKNOWN,  # HTTP auth is outside runtime; real owner/session not attested.
            "CB-0964": (
                expected_private and "/home/u151692719/domains/control.condorapp.com.co/public_html" in doc
                and "no debe vivir dentro de" in data[CRON_DOC]
            ),
            "CB-0965": (
                expected_private
                and "private const SNAPSHOT_ENV" in data[WEB_SOURCE]
                and "DOCUMENT_ROOT" in data[WEB_SOURCE]
                and "is_link(" in data[WEB_SOURCE]
                and "test_invalid_or_in_tree_snapshot_path_fails_closed_to_unknown"
                    in data[PATH_TEST]
                and "symlink" in data[PATH_TEST]
            ),
            "CB-0966": (
                unknown_snapshots
                and "MAX_AGE = 300" in data[WEB_SOURCE]
                and "MAX_BYTES = 2_000_000" in data[WEB_SOURCE]
                and "private static function unknown" in data[WEB_SOURCE]
            ),
        }
    except (KeyError, TypeError, ValueError, AttributeError, IndexError):
        return {key: UNKNOWN for key in IDS}


def verdict(key: object, data: object, obs: object) -> str:
    if key not in IDS:
        return UNKNOWN
    checks = local_checks(data, obs)
    value = checks[key]
    return LOCAL if value is True else UNKNOWN


def operational_verdict(_key: object, _evidence: object = None) -> str:
    """Never promote synthetic fixtures, HTML, documents or CI to production."""
    return UNKNOWN


class BrechasControlbot62Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: 15 explicit rows and real synthetic fixture predicates."""
        data, observed = sources(), observations()
        self.assertEqual(len(IDS), 15)
        self.assertEqual(set(DOCS), set(IDS))
        self.assertEqual(set(matrix_rows(data)), set(IDS))
        checks = local_checks(data, observed)
        self.assertEqual(set(checks), set(IDS))
        self.assertEqual(DOCUMENT_ONLY, {"CB-0962", "CB-0963"})
        for key in IDS:
            with self.subTest(clause=key):
                if key in DOCUMENT_ONLY:
                    self.assertEqual(checks[key], UNKNOWN)
                    self.assertEqual(verdict(key, data, observed), UNKNOWN)
                else:
                    self.assertIs(checks[key], True, "local contract missing: " + key)
                    self.assertEqual(verdict(key, data, observed), LOCAL)
                self.assertEqual(operational_verdict(key, observed), UNKNOWN)

    def test_falla_cerrado(self) -> None:
        """AC-02: absent, duplicate, promoted or fabricated evidence is UNKNOWN."""
        data, observed = sources(), observations()
        for key in IDS:
            path = next(
                p for p in MATRICES if any(line.startswith(f"| {key} |")
                    for line in data[p].splitlines()))
            row = next(line for line in data[path].splitlines()
                       if line.startswith(f"| {key} |"))
            for altered in (
                data[path].replace(row, "", 1),
                data[path] + "\n" + row,
                data[path].replace(row, row.replace("NO_VERIFICABLE", "CUBIERTO"), 1),
            ):
                with self.subTest(clause=key):
                    bad = {**data, path: altered}
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        matrix_rows(bad)
                    self.assertEqual(verdict(key, bad, observed), UNKNOWN)
            self.assertEqual(verdict(key, {**data, DOCS[key]: ""}, observed), UNKNOWN)
        self.assertEqual(verdict("CB-9999", data, observed), UNKNOWN)
        self.assertEqual(verdict("CB-0952", {}, observed), UNKNOWN)
        self.assertEqual(verdict("CB-0953", data, {"promotion:provenance": {}}), UNKNOWN)
        self.assertEqual(verdict("CB-0966", data,
                                 {**observed, "web:unknown": {"stale": {}}}), UNKNOWN)
        expected = "SetEnv CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH "
        invalid_config = data[HTACCESS].replace(
            expected + "/home/u151692719/domains/control.condorapp.com.co/private/orchestrator-live.json",
            expected + "/home/u151692719/domains/control.condorapp.com.co/public_html/orchestrator-live.json", 1)
        self.assertNotEqual(data[HTACCESS], invalid_config)
        self.assertEqual(verdict("CB-0965", {**data, HTACCESS: invalid_config},
                                 observed), UNKNOWN)
        self.assertEqual(verdict("CB-0956", data,
                                 {**observed, "registry:approved": {}}), UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        """AC-03: fixed offline PHP scenarios; no HTTP client or credentials."""
        own = (ROOT / "tests/test_brechas_controlbot_62.py").read_text(encoding="utf-8")
        tree = ast.parse(own)
        names = {alias.name.split(".")[0] for node in ast.walk(tree)
                 if isinstance(node, ast.Import) for alias in node.names}
        names |= {node.module.split(".")[0] for node in ast.walk(tree)
                  if isinstance(node, ast.ImportFrom) and node.module}
        self.assertFalse(names & {
            "requests", "httpx", "urllib", "socket", "pymysql", "sqlalchemy",
            "mysql", "playwright",
        })
        self.assertTrue(all((ROOT / p).is_file() for p in SOURCES))
        self.assertTrue(all((ROOT / "tests" / script).is_file()
                            for script, _ in PROBES.values()))
        with self.assertRaises(ValueError):
            probe("promotion", "unapproved")
        with self.assertRaises(ValueError):
            probe("unknown-group", "unapproved")
        self.assertEqual(operational_verdict("CB-0962", observations()), UNKNOWN)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: no invented live, approval, backup or auth evidence."""
        data, observed = sources(), observations()
        for key in IDS:
            with self.subTest(clause=key):
                for invented in (
                    None, True, {}, {"ci": "success", "sha": "f" * 40},
                    {"human_approval_required": True},
                    {"backup": "git", "status": "VALIDATED_IN_PRODUCTION"},
                    {"owner": "synthetic", "status": "GREEN"},
                    {"http_status": 200, "auth": "remote_user"},
                ):
                    self.assertEqual(operational_verdict(key, invented), UNKNOWN)
        self.assertEqual(verdict("CB-0962", data, observed), UNKNOWN)
        self.assertEqual(verdict("CB-0963", data, observed), UNKNOWN)
        self.assertEqual(verdict("CB-0966", data,
                                 {**observed, "web:unknown": {}}), UNKNOWN)


if __name__ == "__main__":
    unittest.main()
