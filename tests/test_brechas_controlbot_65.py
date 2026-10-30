#!/usr/bin/env python3
"""ControlBot #1072: synthetic contracts for CB-0997..CB-1011; never live."""
from __future__ import annotations

from test_brechas_controlbot_20 import credible_e4_coverage_row
import ast
from functools import lru_cache
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
MAIN, PART = "docs/matriz-cobertura.md", "docs/matriz-cobertura-parte-04.md"
UI = "docs/requirement-intake-decision-ui-v1.md"
INTAKE = "docs/requirement-intake-v1.md"
MAT = "docs/requirement-materialization-v1.md"
SRC_UI = "src/RequirementIntakeDecisionUi.php"
SRC_INTAKE = "src/RequirementIntake.php"
SRC_MAT = "src/RequirementMaterializer.php"
SCRIPT_UI = "tests/requirement_intake_decision_ui_scenarios.php"
SCRIPT_INTAKE = "tests/requirement_intake_scenarios.php"
SCRIPT_MAT = "tests/requirement_materialization_scenarios.php"
FILES = frozenset((MAIN, PART, UI, INTAKE, MAT, SRC_UI, SRC_INTAKE,
                   SRC_MAT, SCRIPT_UI, SCRIPT_INTAKE, SCRIPT_MAT))
UNKNOWN, LOCAL = "UNKNOWN", "CONTRATO_LOCAL"
IDS = tuple(f"CB-{n:04d}" for n in range(997, 1012))
CLAUSES = {
    "CB-0997": (MAIN, UI, "proyección de revisión humana determinista"),
    "CB-0998": (PART, UI, "summary"),
    "CB-0999": (PART, UI, "decision_options"),
    "CB-1000": (PART, UI, "detección de material sensible"),
    "CB-1001": (MAIN, INTAKE, "El core reconoce hechos únicamente"),
    "CB-1002": (PART, INTAKE, "Texto y transcript producen la misma estructura"),
    "CB-1003": (PART, INTAKE, "patrones de credenciales y secretos se sustituyen"),
    "CB-1004": (PART, INTAKE, "preguntas explícitas para los campos"),
    "CB-1005": (PART, INTAKE, "El match de Project es solo una propuesta"),
    "CB-1006": (MAIN, MAT, "La vista se recalcula desde el proposal"),
    "CB-1007": (PART, MAT, "OwnerContext::assertFresh()"),
    "CB-1008": (PART, MAT, "Solo"),
    "CB-1009": (PART, MAT, "owner_choice_required"),
    "CB-1010": (PART, MAT, "clave SHA-256"),
    "CB-1011": (PART, MAT, "El receipt conserva únicamente refs"),
}
SCRIPTS = {"ui": SCRIPT_UI, "intake": SCRIPT_INTAKE, "materialize": SCRIPT_MAT}
CASES = {"ui": {"default"}, "intake": {"default"},
         "materialize": {"idempotent", "rejected", "existing", "project-choice", "receipt"}}


def read_sources() -> dict[str, str]:
    return {path: (ROOT / path).read_text(encoding="utf-8") for path in FILES}


def coverage_rows(data: object) -> dict[str, tuple[str, ...]]:
    if type(data) is not dict or set(data) != FILES or any(
        type(data[p]) is not str or not data[p] for p in FILES
    ):
        raise ValueError("UNKNOWN: missing source")
    result = {}
    for matrix in (MAIN, PART):
        for line in data[matrix].splitlines():
            match = re.match(r"^\|\s*(CB-\d{4})\s*\|", line)
            if not match or match.group(1) not in CLAUSES:
                continue
            key = match.group(1)
            expected, doc, _ = CLAUSES[key]
            cells = tuple(x.strip() for x in line.strip().strip("|").split("|"))
            if (key in result or matrix != expected or len(cells) != 6
                    or cells[0] != key or not all(cells)
                    or doc not in cells[1] or not credible_e4_coverage_row(cells)):
                raise ValueError("UNKNOWN: invalid row " + key)
            result[key] = cells
    if set(result) != set(IDS):
        raise ValueError("UNKNOWN: incomplete rows")
    return result


@lru_cache(maxsize=16)
def fixture(group: str, case: str = "default") -> dict:
    if group not in CASES or case not in CASES[group]:
        raise ValueError("UNKNOWN: disallowed fixture")
    php = shutil.which("php")
    if not php:
        return {"evidence": UNKNOWN, "reason": "php_unavailable"}
    command = [php, str(ROOT / SCRIPTS[group])]
    if group == "materialize":
        command.append(case)
    result = subprocess.run(
        command, cwd=ROOT, capture_output=True, text=True, timeout=30,
        check=False, env={**{key: os.environ[key] for key in
                            ("PATH", "LANG", "LC_ALL", "HOME", "TMPDIR",
                             "LD_LIBRARY_PATH", "PHP_INI_SCAN_DIR")
                            if key in os.environ}, "XDEBUG_MODE": "off"})
    if result.returncode:
        return {"evidence": UNKNOWN, "reason": "php_nonzero_exit"}
    if result.stderr.strip():
        return {"evidence": UNKNOWN, "reason": "php_stderr"}
    try:
        payload = json.loads(result.stdout)
    except ValueError:
        return {"evidence": UNKNOWN, "reason": "non_json"}
    if type(payload) is not dict or not payload:
        return {"evidence": UNKNOWN, "reason": "empty_payload"}
    return payload


def probes() -> dict:
    return {"ui": fixture("ui"), "intake": fixture("intake"),
            **{c: fixture("materialize", c) for c in CASES["materialize"]}}


def exercises(key: str, data: dict, p: dict) -> bool:
    u, q = p["ui"], p["intake"]
    if key == "CB-0997":
        view = u["view"]
        diff = view["materialization_diff"]
        return (view == u["replay"] and view["execution"] is False
                and view["proposal_ref"] == u["proposal"]["proposal_ref"]
                and all(e["execution"] is False for e in
                        (diff["project"], diff["epic"], *diff["issues"])))
    if key == "CB-0998":
        summary, proposal = u["view"]["summary"], u["proposal"]
        return (all(summary[f] == proposal[f] for f in
                    ("problem", "user", "objectives", "out_of_scope"))
                and set(summary["unknown_fields"]) == {
                    f for f in ("problem", "user", "objectives",
                                "out_of_scope", "dependencies")
                    if proposal[f]["status"] == "unknown"
                } and bool(u["unknown_view"]["summary"]["unknown_fields"]))
    if key == "CB-0999":
        options = u["view"]["decision_options"]
        return ([x["code"] for x in options] == ["approve", "revise", "reject"]
                and all(x["execution"] is False for x in options))
    if key == "CB-1000":
        serialized = json.dumps(u["secret_view"])
        return (u["secret_view"]["execution"] is False
                and all(x not in serialized for x in
                        ("hunter2", "supersecrettoken", "ABCDEFGHIJKLMNOPQRSTUVWXYZ"))
                and "self::secretFree($out)" in data[SRC_UI])
    if key == "CB-1001":
        free = q["free"]
        return (free["problem"]["status"] == "known"
                and free["user"]["status"] == "known"
                and all(free[x]["status"] == "unknown" for x in
                        ("client", "budget", "deadline", "dependencies")))
    if key == "CB-1002":
        a, b = q["text"], q["transcript"]
        return (a["source_kind"] == "text" and b["source_kind"] == "transcript"
                and a["fingerprint"] == b["fingerprint"]
                and a["draft_ref"] == b["draft_ref"] and a["intent"] == b["intent"]
                and "audio" not in a and "audio" not in b)
    if key == "CB-1003":
        draft = q["secret_draft"]
        output = json.dumps((draft, q["secret_proposal"]))
        return (draft["redaction_count"] > 0 and "[REDACTED]" in draft["intent"]
                and all(s not in output for s in
                        ("hunter2", "supersecrettoken", "ABCDEFGHIJKLMNOPQRSTUVWXYZ")))
    if key == "CB-1004":
        draft, proposal = q["analysis_draft"], q["proposal"]
        unknown = [x for x in ("problem", "user", "client", "budget", "deadline",
                               "constraints", "objectives", "dependencies",
                               "out_of_scope") if draft[x]["status"] == "unknown"]
        return bool(unknown) and proposal["questions"] == [
            "Clarify " + x + "." for x in unknown]
    if key == "CB-1005":
        proposal = q["proposal"]
        return (proposal["project_match"]["auto_create"] is False
                and proposal["requires_approval"] is True
                and proposal["execution"] is False)
    if key == "CB-1006":
        rejected = p["rejected"]
        return (rejected["blocked"][1] is True
                and rejected["mutations"][1] == 0
                and "RequirementIntakeDecisionUi::project($proposal)"
                    in data[SRC_MAT])
    if key == "CB-1007":
        rejected = p["rejected"]
        return (rejected["blocked"][2] is True
                and rejected["mutations"][2] == 0
                and "$owner->assertFresh($now)" in data[SRC_MAT])
    if key == "CB-1008":
        rejected = p["rejected"]
        return (rejected["blocked"][0] is True
                and rejected["mutations"][0] == 0
                and "($a['option']??null)!=='approve'" in data[SRC_MAT])
    if key == "CB-1009":
        choice = p["project-choice"]
        return (choice["missing_blocked"] is True
                and choice["ambiguous_blocked"] is True
                and choice["ambiguous_mutations"] == 0
                and choice["receipt"]["project_ref"].startswith("controlbot:project/")
                and "ProjectModel::normalize" in data[SRC_MAT])
    if key == "CB-1010":
        replay = p["idempotent"]
        first = replay["first"]
        keys = [*first["idempotency_keys"].values(),
                *(item["idempotency_key"] for item in first["issues"])]
        return (first == replay["second"]
                and replay["counts"]["create"] == 1
                and replay["counts"]["epic"] == 1
                and replay["counts"]["issue"] == len(first["issues"])
                and len(set(keys)) == len(keys)
                and all(re.fullmatch(r"[a-f0-9]{64}", k) for k in keys))
    if key == "CB-1011":
        receipt = p["receipt"]
        value = receipt["receipt"]
        permitted = {"receipt_ref", "version", "proposal_ref",
                     "proposal_fingerprint", "decision_ref",
                     "decision_fingerprint", "project_ref", "epic_ref",
                     "issues", "idempotency_keys", "replay_safe"}
        return (receipt["secret_blocked"] is True
                and set(value) == permitted
                and not any(s in json.dumps(value).lower() for s in
                            ("hunter2", "supersecrettoken",
                             "transcript", "password=", "raw_audio")))
    return False


def evidence(key: object, data: object, p: object) -> str:
    if type(key) is not str or key not in CLAUSES or type(p) is not dict:
        return UNKNOWN
    if set(p) != {"ui", "intake", "idempotent", "rejected",
                  "existing", "project-choice", "receipt"}:
        return UNKNOWN
    try:
        coverage_rows(data)
        doc, phrase = CLAUSES[key][1:3]
        if phrase not in data[doc]:
            return UNKNOWN
        return LOCAL if exercises(key, data, p) else UNKNOWN
    except (ValueError, KeyError, IndexError, AttributeError, TypeError,
            AssertionError):
        return UNKNOWN


def operational(_key: object, _candidate: object = None) -> str:
    return UNKNOWN


class BrechasControlbot65Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.data, cls.proof = read_sources(), probes()

    def test_contrato_principal(self) -> None:
        indexed = coverage_rows(self.data)
        self.assertEqual(set(indexed), set(IDS))
        self.assertEqual(set(CLAUSES), set(IDS))
        for key in IDS:
            with self.subTest(key=key):
                required = ("ui" if int(key[3:]) <= 1000 else
                            "intake" if int(key[3:]) <= 1005 else
                            "rejected" if int(key[3:]) <= 1008 else
                            "project-choice" if key == "CB-1009" else
                            "idempotent" if key == "CB-1010" else "receipt")
                expected = (UNKNOWN if self.proof[required].get("evidence") == UNKNOWN
                            else LOCAL)
                self.assertEqual(expected, evidence(key, self.data, self.proof))
                self.assertTrue(credible_e4_coverage_row(indexed[key]))

    def test_falla_cerrado(self) -> None:
        for key in IDS:
            matrix, doc, phrase = CLAUSES[key]
            line = next(x for x in self.data[matrix].splitlines()
                        if x.startswith("| " + key + " |"))
            changed = (self.data[matrix].replace(line, "", 1),
                       self.data[matrix] + "\n" + line,
                       self.data[matrix].replace(
                           line, line.replace("NO_VERIFICABLE", "CUBIERTO", 1), 1))
            for mutation in changed:
                self.assertEqual(UNKNOWN, evidence(
                    key, {**self.data, matrix: mutation}, self.proof))
            self.assertEqual(UNKNOWN, evidence(
                key, {**self.data, doc: self.data[doc].replace(phrase, "")},
                self.proof))
            self.assertEqual(UNKNOWN, evidence(key, self.data, {}))
            self.assertEqual(UNKNOWN, evidence(
                key, self.data, {**self.proof, "extra": {"approved": True}}))
        self.assertEqual(UNKNOWN, evidence("CB-9999", self.data, self.proof))
        self.assertEqual(UNKNOWN, evidence(None, self.data, self.proof))
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            fixture("materialize", "publish")

    def test_sin_datos_reales(self) -> None:
        self.assertEqual(set(self.data), FILES)
        for name in FILES:
            path = Path(name)
            self.assertFalse(path.is_absolute())
            self.assertNotIn("..", path.parts)
            self.assertTrue((ROOT / path).is_file())
        own = (ROOT / "tests/test_brechas_controlbot_65.py").read_text(encoding="utf-8")
        imports = set()
        for node in ast.walk(ast.parse(own)):
            if isinstance(node, ast.Import):
                imports.update(a.name.split(".")[0] for a in node.names)
            elif isinstance(node, ast.ImportFrom):
                imports.add((node.module or "").split(".")[0])
        self.assertLessEqual(imports, {"__future__", "ast", "functools", "json", "os",
                                      "pathlib", "re", "shutil", "subprocess",
                                      "unittest"})
        self.assertNotIn("-----BEGIN " + "PRIVATE KEY-----", own)
        self.assertEqual(UNKNOWN, operational("CB-1011", {"owner": True}))

    def test_estados_unknown_explicitos(self) -> None:
        for key in IDS:
            self.assertEqual("NO_VERIFICABLE", coverage_rows(self.data)[key][3])
            for fake in (None, {}, {"CI": "success", "sha": "0" * 40},
                         {"approved": True}, {"live": "GREEN"},
                         {"production": "VALIDATED_IN_PRODUCTION"}):
                self.assertEqual(UNKNOWN, operational(key, fake))
        self.assertEqual(UNKNOWN, evidence("CB-1008", self.data, {}))


if __name__ == "__main__":
    unittest.main()
