"""Issue #1240: real offline PHP coverage for CB-0947..CB-0961.

Actual PromptPromotionDecision, PromptRegistry, PromptEvaluation and PromptUi
are exercised with synthetic fixtures. Never read coverage-matrix states.
"""
from __future__ import annotations

import ast
from functools import lru_cache
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPTS = {
    "promotion": "prompt_promotion_decision_scenarios.php",
    "registry": "prompt_registry_scenarios.php",
    "ui": "prompt_ui_scenarios.php",
}
CASES = {
    "promotion": frozenset({"eligibility", "binding", "decision", "human_gate",
                            "deterministic", "provenance", "provenance_gate",
                            "provenance_format", "pure"}),
    "registry": frozenset({"immutable", "approved", "invalid", "rollback",
                           "secret", "pure"}),
    "ui": frozenset({"projection", "hold", "unsafe", "tamper", "result_binding",
                     "metric_substitution", "safety_policy_substitution",
                     "candidate_contract", "binding", "secret", "pure"}),
}
ROWS = frozenset(f"CB-{i:04d}" for i in range(947, 962))


def php_run(args: list[str]) -> str:
    binary = shutil.which("php")
    if binary is None:
        raise AssertionError("UNKNOWN: PHP CLI not available")
    env = {"PATH": os.environ.get("PATH", "/usr/bin:/bin"), "LANG": "C"}
    for key in ("CONTROLBOT_REAL_PHP", "CONTROLBOT_PHP_COVERAGE_BOOTSTRAP",
                "CONTROLBOT_PHP_COVERAGE_DIR", "CONTROLBOT_REPO_ROOT", "XDEBUG_MODE"):
        if key in os.environ:
            env[key] = os.environ[key]
    cmd = [binary, "-d", "allow_url_fopen=0", "-d", "enable_dl=0", "-d",
           "disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,shell_exec,system,exec,passthru,proc_open,popen"]
    with tempfile.TemporaryDirectory(prefix="controlbot-1240-") as home:
        env["HOME"] = home
        run = subprocess.run(cmd + args, cwd=ROOT, env=env, text=True,
                             capture_output=True, timeout=60, check=False)
    if run.returncode or run.stderr.strip():
        raise AssertionError(f"UNKNOWN: PHP failed ({run.returncode}): {run.stderr[-800:]}")
    return run.stdout.strip()


@lru_cache(maxsize=48)
def scenario(family: str, case: str):
    if family not in SCRIPTS or case not in CASES[family]:
        raise AssertionError("UNKNOWN: unsupported PHP scenario")
    script = ROOT / "tests" / SCRIPTS[family]
    if not script.is_file():
        raise AssertionError("UNKNOWN: original PHP scenarios missing")
    raw = php_run([str(script), case])
    if family == "ui" and case in {"projection", "hold", "unsafe"}:
        return raw
    try:
        result = json.loads(raw)
    except ValueError as exc:
        raise AssertionError("UNKNOWN: invalid JSON from real PHP") from exc
    if not isinstance(result, dict):
        raise AssertionError("UNKNOWN: PHP response not an object")
    return result


def inline_php(source: str) -> dict:
    """Directly exercise the original PHP class with additional synthetic inputs."""
    return json.loads(php_run(["-r", source]))


class CoberturaControlbot57Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: exactly one real PHP behavior predicate per original row."""
        eligibility = scenario("promotion", "eligibility")
        valid = eligibility["valid"]
        binding = scenario("promotion", "binding")
        decisions = scenario("promotion", "decision")
        gate = scenario("promotion", "human_gate")
        provenance = scenario("promotion", "provenance")
        link = scenario("promotion", "provenance_gate")
        format_rejected = scenario("promotion", "provenance_format")
        registry_immutable = scenario("registry", "immutable")
        registry_approved = scenario("registry", "approved")
        registry_invalid = scenario("registry", "invalid")
        registry_rollback = scenario("registry", "rollback")
        registry_pure = scenario("registry", "pure")
        ui = scenario("ui", "projection")
        hold = scenario("ui", "hold")
        unsafe = scenario("ui", "unsafe")
        ui_provenance = scenario("ui", "result_binding")
        sorted_templates = inline_php(r'''
            require 'src/PromptRegistry.php';
            $row=static fn(string $id)=>[
                'template_id'=>$id,'version'=>1,'task_class'=>'briefing',
                'provider_scope'=>'openai','body_ref'=>'prompt:briefing/v1',
                'variables_schema'=>['items'=>['type'=>'array','required'=>false]],
                'status'=>'approved','created_at'=>100,'created_by'=>'owner:fixture',
                'supersedes'=>null
            ];
            $older=$row('zeta-briefing'); $newer=$row('alpha-briefing');
            $original=[$older];
            $result=ControlBot\Prompt\PromptRegistry::register($original,$newer);
            echo json_encode(['ids'=>array_column($result,'template_id'),
                 'original_unchanged'=>$original===[$older]],JSON_THROW_ON_ERROR);
        ''')
        escaped = inline_php(r'''
            require 'src/PromptUi.php';
            $m=new ReflectionMethod(ControlBot\Prompt\PromptUi::class,'e');
            echo json_encode(['escaped'=>$m->invoke(null,'<b>fixture</b>')],JSON_THROW_ON_ERROR);
        ''')
        assertions = {
            "CB-0947": (valid["decision"] == "eligible_for_human_approval"
                        and valid["human_gate_required"] is True
                        and gate["history_unchanged"] is True),
            "CB-0948": (all(eligibility[k] is True for k in
                           ("current_not_approved", "candidate_not_candidate",
                            "wrong_successor", "contract_drift"))
                        and all(binding.values()) and all(format_rejected.values())
                        and all(registry_invalid.values())),
            "CB-0949": (valid["current_version"] == 1
                        and valid["candidate_version"] == 2
                        and eligibility["current_not_approved"] is True
                        and eligibility["candidate_not_candidate"] is True
                        and eligibility["wrong_successor"] is True
                        and eligibility["contract_drift"] is True),
            "CB-0950": (provenance["evaluation"]["authority"] == "advisory_only"
                        and binding["authority"] is True
                        and valid["authority"] == "human_approval_required"),
            "CB-0951": (provenance["evaluation"]["decision"] == "candidate_better"
                        and "candidate_dominates_current" in provenance["evaluation"]["reasons"]
                        and decisions["better"]["decision"] == "eligible_for_human_approval"
                        and decisions["better"]["reasons"] == ["evaluation_supports_candidate"]),
            "CB-0952": (decisions["hold"]["decision"] == "hold"
                        and decisions["hold"]["human_gate_required"] is True
                        and all(binding.values())
                        and provenance["tampered_current"] is True
                        and provenance["tampered_candidate"] is True),
            "CB-0953": (all(provenance["out"][key] == provenance["evaluation"][key]
                            for key in ("evaluation_set_fingerprint",
                                        "current_result_fingerprint",
                                        "candidate_result_fingerprint"))
                        and provenance["out"]["evaluation_fingerprint"] == provenance["evaluation"]["fingerprint"]
                        and link["same_set"] is True
                        and scenario("promotion", "pure")["hits"] == []),
            "CB-0954": (gate["human_gate_required"] is True
                        and gate["authority"] == "human_approval_required"
                        and all(v["authority"] == "human_approval_required" for v in decisions.values())),
            "CB-0955": (gate["history_unchanged"] is True
                        and gate["current_status"] == "approved"
                        and gate["candidate_status"] == "candidate"
                        and valid["human_gate_required"] is True
                        and valid["decision"] != "approved"),
            "CB-0956": (registry_approved["selected"]["status"] == "approved"
                        and registry_approved["selected"]["version"] == 1
                        and registry_approved["missing_fails_closed"] is True
                        and registry_pure["methods"] == ["active", "register", "rollback"]),
            "CB-0957": (registry_immutable["count"] == 2
                        and registry_immutable["first_unchanged"] is True
                        and registry_immutable["versions"] == [1, 2]
                        and registry_immutable["second_status"] == "candidate"
                        and sorted_templates["ids"] == ["alpha-briefing", "zeta-briefing"]
                        and sorted_templates["original_unchanged"] is True
                        and registry_invalid["duplicate"] is True),
            "CB-0958": (registry_rollback["current"] == 3
                        and registry_rollback["rollback"] == 1
                        and registry_rollback["non_approved_rejected"] is True
                        and registry_rollback["history_unchanged"] is True),
            "CB-0959": (all(ui_provenance.values())
                        and scenario("ui", "tamper")["rejected"] is True
                        and scenario("ui", "metric_substitution")["rejected"] is True
                        and all(scenario("ui", "safety_policy_substitution").values())
                        and "candidate_result_fingerprint" in provenance["out"]),
            "CB-0960": ('data-decision="eligible_for_human_approval"' in ui
                        and 'data-decision="hold"' in hold
                        and 'data-decision="hold"' in unsafe
                        and "Solo versiones approved" in ui
                        and "human_gate_required = true" in ui
                        and scenario("ui", "candidate_contract")["rejected"] is True
                        and scenario("ui", "binding")["rejected"] is True),
            "CB-0961": (escaped["escaped"] == "&lt;b&gt;fixture&lt;/b&gt;"
                        and scenario("ui", "secret")["rejected"] is True
                        and scenario("ui", "pure")["hits"] == []
                        and scenario("ui", "pure")["escaper"] is True
                        and '<meta name="viewport"' in ui
                        and ':focus-visible' in ui
                        and 'prefers-reduced-motion:reduce' in ui
                        and not any(f"<{t}" in ui.lower()
                                    for t in ("form", "input", "button", "script"))),
        }
        self.assertEqual(set(assertions), ROWS)
        for row, condition in assertions.items():
            with self.subTest(row=row):
                self.assertIs(condition, True, "PHP contract not verified: " + row)

    def test_falla_cerrado(self) -> None:
        """AC-02: invalid shape, binding, scope, versions and unsafe evidence."""
        for key, value in scenario("promotion", "eligibility").items():
            if key != "valid":
                with self.subTest(eligibility=key):
                    self.assertIs(value, True)
        for family, case in (("promotion", "binding"),
                             ("promotion", "provenance_format"),
                             ("registry", "invalid"), ("registry", "secret"),
                             ("ui", "safety_policy_substitution")):
            for key, value in scenario(family, case).items():
                with self.subTest(family=family, case=case, input=key):
                    self.assertIs(value, True)
        for key in ("tampered_current", "tampered_candidate"):
            self.assertIs(scenario("promotion", "provenance")[key], True)
        for key in ("tamper", "metric_substitution", "candidate_contract", "binding", "secret"):
            self.assertIs(scenario("ui", key)["rejected"], True)
        self.assertIs(scenario("registry", "approved")["missing_fails_closed"], True)
        self.assertIs(scenario("registry", "rollback")["non_approved_rejected"], True)

    def test_sin_datos_reales(self) -> None:
        """AC-03: isolated deterministic PHP, no live data or secret requests."""
        self.assertEqual(set(SCRIPTS), {"promotion", "registry", "ui"})
        self.assertEqual(scenario("promotion", "pure")["hits"], [])
        self.assertEqual(scenario("ui", "pure")["hits"], [])
        self.assertTrue(scenario("ui", "pure")["escaper"])
        registry = scenario("registry", "pure")
        for forbidden in ("new PDO", "mysqli", "curl_", "shell_exec", "file_put_contents"):
            self.assertNotIn(forbidden.lower(), registry["source"].lower())
        a = scenario("promotion", "deterministic")
        self.assertIs(a["same"], True)
        self.assertIs(a["fingerprint_same"], True)
        self.assertEqual(a["one"]["authority"], "human_approval_required")
        self.assertEqual(scenario("registry", "secret"),
                         {"variable": True, "body": True, "actor": True})
        for name in ("projection", "hold", "unsafe"):
            html = scenario("ui", name)
            self.assertIn("Proyección read-only", html)
            self.assertNotIn("<form", html)
            self.assertNotIn("<script", html)
        source = Path(__file__).read_text(encoding="utf-8")
        for node in ast.walk(ast.parse(source)):
            if isinstance(node, (ast.Import, ast.ImportFrom)):
                modules = ([item.name.split(".")[0] for item in node.names]
                           if isinstance(node, ast.Import)
                           else [(node.module or "").split(".")[0]])
                self.assertFalse(set(modules) & {"socket", "requests", "urllib",
                                                 "httpx", "pymysql"})


if __name__ == "__main__":
    unittest.main()
