#!/usr/bin/env python3
"""#1219: pruebas reales PHP offline, sin red, secretos ni derechos live."""
from __future__ import annotations

from functools import lru_cache
import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
IDS = {f"CB-{i:04d}" for i in range(581, 587)} | {"CB-0588"} | {
    f"CB-{i:04d}" for i in range(589, 595)
}
# CB-0586 es una delimitación editorial; no un comportamiento observable.
NON_OFFLINE = {"CB-0586": ("C", "Sin UI, publicación, alertas, scheduler, mutaciones ni cierre automático de incidentes.")}
OFFLINE = IDS - set(NON_OFFLINE)
INCIDENT_CASES = {"postmortem", "unknown", "observer_reclassified", "bad_recovery"}

def php(*argv: str) -> dict:
    result = subprocess.run(
        ["php", *argv], cwd=ROOT, text=True, capture_output=True,
        timeout=45, check=False,
    )
    expected_warning = "The use statement with non-compound name 'InvalidArgumentException' has no effect"
    unexpected = [
        line for line in result.stderr.splitlines()
        if line.strip() and expected_warning not in line
    ]
    if result.returncode or unexpected:
        raise AssertionError("PHP no acreditado: " + result.stderr[-400:])
    try:
        value = json.loads(result.stdout)
    except (ValueError, TypeError) as exc:
        raise AssertionError("Respuesta PHP malformada") from exc
    if not isinstance(value, dict):
        raise AssertionError("Escenario sin objeto PHP válido")
    return value

@lru_cache(maxsize=8)
def incident(case: str) -> dict:
    if case not in INCIDENT_CASES:
        raise AssertionError("Escenario no permitido")
    return php(str(ROOT / "tests/incident_postmortem_scenarios.php"), case)

@lru_cache(maxsize=1)
def infrastructure() -> dict:
    return php(str(ROOT / "tests/infrastructure_action_projection_scenarios.php"))

CHRONOLOGY = r"""
require $argv[1];
use ControlBot\Business\IncidentTimeline;
$r=['version'=>1,'incident_ref'=>'incident:'.str_repeat('a',32),
'opened_at'=>100,'detected_at'=>110,'recovered_at'=>140,'events'=>[[
'event_id'=>'event-open','timestamp'=>100,'sequence'=>0,'kind'=>'incident.opened',
'source'=>'fixture','evidence_ref'=>'evidence:'.str_repeat('b',32)]]];
$bad=static function(array $input): bool {
try { IncidentTimeline::build($input); return false; }
catch (\InvalidArgumentException $e) { return true; }};
$good=IncidentTimeline::build($r);
$early=$r;$early['detected_at']=99;
$reversed=$r;$reversed['recovered_at']=109;
$missing=$r;$missing['recovered_at']=null;
echo json_encode(['duration'=>$good['duration_seconds'],
'early_rejected'=>$bad($early),'reversed_rejected'=>$bad($reversed),
'missing_duration'=>IncidentTimeline::build($missing)['duration_seconds']],
JSON_THROW_ON_ERROR);
"""

SINGLE_USE = r"""
ob_start(); require $argv[1]; ob_end_clean();
$token=authority205('allow','hostinger.read');
$one=\ControlBot\Infrastructure\InfrastructureActionProjection::project(
intent205(['intent_id'=>'replay-first']),$token,null,NOW);
$replayed=rejected205(static fn()=>
\ControlBot\Infrastructure\InfrastructureActionProjection::project(
intent205(['intent_id'=>'replay-second']),$token,null,NOW));
echo json_encode(['status'=>$one['status'],'execution'=>$one['execution'],
'replay_rejected'=>$replayed],JSON_THROW_ON_ERROR);
"""

@lru_cache(maxsize=1)
def chronology() -> dict:
    return php("-r", CHRONOLOGY, "--", str(ROOT / "src/IncidentTimeline.php"))

@lru_cache(maxsize=1)
def single_use() -> dict:
    return php("-r", SINGLE_USE, "--", str(ROOT / "tests/infrastructure_action_projection_scenarios.php"))

class CoberturaControlbot36Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: aserciones de comportamiento PHP real para 12 requisitos."""
        time = chronology()
        pm = incident("postmortem")
        unknown = incident("unknown")
        infra = infrastructure()
        once = single_use()
        rows = pm["findings"]
        classes = {row["classification"] for row in rows}
        classes.update(row["classification"] for row in unknown["incomplete"]["findings"])
        pairs = {
            "necessary_cause": "root_cause",
            "independent_defect": "independent_bug",
            "contributor": "contributing_factor",
            "preventive_only": "preventive_change",
        }
        checks = {
            "CB-0581": time["duration"] == 30 and time["early_rejected"] is True
                and time["reversed_rejected"] is True and time["missing_duration"] is None,
            "CB-0582": classes == {
                "root_cause", "independent_bug", "contributing_factor",
                "preventive_change", "unknown",
            },
            "CB-0583": all(
                row["evidence_state"] == "supported"
                and row["classification"] == pairs[row["evidence_relation"]]
                for row in rows
            ) and incident("observer_reclassified")["rejected"] is True,
            "CB-0584": all(
                any(row["classification"] == "unknown"
                    and row["evidence_relation"] == "unresolved"
                    and row["owner_action_required"] is True
                    for row in unknown[state]["findings"])
                for state in ("incomplete", "contradictory")
            ),
            "CB-0585": pm["recovery"] == {
                "canary_issue": 86, "mode": "serial", "fan_out": False,
                "serial_queue": [72, 77, 80, 71, 84, 73, 75],
            },
            "CB-0588": once == {
                "status": "planned", "execution": False, "replay_rejected": True,
            },
            "CB-0589": infra["planned"]["status"] == "planned"
                and infra["planned"]["authority_decision"] == "allow"
                and infra["fabricated_array_rejected"] is True,
            "CB-0590": infra["caller_status_rejected"] is True
                and infra["fake_plan_rejected"] is True,
            "CB-0591": infra["cross_capability_rejected"] is True,
            "CB-0592": infra["planned"]["runner_request_ready"] is True
                and infra["owner"]["runner_request_ready"] is False
                and infra["denied"]["runner_request_ready"] is False,
            "CB-0593": all(infra[k] is True for k in (
                "fabricated_array_rejected", "fabricated_object_rejected",
                "cross_capability_rejected",
            )),
            "CB-0594": infra["planned"]["action_ref"] == "controlbot:infrastructure-action/intent-205"
                and infra["planned"]["intent_ref"] == infra["planned"]["source_ref"]
                == "controlbot:infrastructure-intent/intent-205"
                and all(infra[k]["execution"] is False for k in ("planned", "owner", "denied")),
        }
        self.assertEqual(set(checks), OFFLINE)
        self.assertEqual(OFFLINE | set(NON_OFFLINE), IDS)
        self.assertTrue(all(kind in ("B", "C", "D") and why
                            for kind, why in NON_OFFLINE.values()))
        for key, passed in checks.items():
            with self.subTest(requirement=key):
                self.assertTrue(passed, key + ": sin evidencia funcional")

    def test_falla_cerrado(self) -> None:
        """AC-02: invalidación de cronología, causalidad, permisos y replay."""
        self.assertTrue(chronology()["early_rejected"])
        self.assertTrue(chronology()["reversed_rejected"])
        self.assertIsNone(chronology()["missing_duration"])
        self.assertTrue(incident("observer_reclassified")["rejected"])
        for state, output in incident("unknown").items():
            finding = next(row for row in output["findings"]
                           if row["classification"] == "unknown")
            self.assertEqual(finding["evidence_state"], state)
            self.assertEqual(finding["evidence_relation"], "unresolved")
            self.assertTrue(finding["owner_action_required"])
        for name, rejected in incident("bad_recovery").items():
            with self.subTest(case=name):
                self.assertTrue(rejected)
        for key in ("fake_plan_rejected", "caller_status_rejected",
                    "fabricated_array_rejected", "fabricated_object_rejected",
                    "cross_capability_rejected", "secret_rejected"):
            with self.subTest(rejection=key):
                self.assertTrue(infrastructure()[key])
        self.assertTrue(single_use()["replay_rejected"])
        denied = infrastructure()["denied"]
        self.assertEqual(denied["status"], "denied")
        self.assertIsNone(denied["work_item_id"])
        self.assertIsNone(denied["approval_ref"])
        self.assertFalse(denied["runner_request_ready"])
        self.assertEqual(infrastructure()["owner"]["approval_ref"],
                         "controlbot:approval/infra-intent-owner")

    def test_sin_datos_reales(self) -> None:
        """AC-03: evidencia offline sin tráfico externo ni PII."""
        self.assertEqual(len(IDS), 13)
        self.assertEqual(len(OFFLINE), 12)
        self.assertEqual(set(NON_OFFLINE), {"CB-0586"})
        self.assertEqual(OFFLINE & set(NON_OFFLINE), set())
        content = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(content, r"\b(?:github_pat_[A-Za-z0-9]{16,}|ghp_[A-Za-z0-9]{16,}|sk-[A-Za-z0-9]{20,})")
        self.assertNotRegex(content, r"[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}")
        self.assertNotIn("curl_" + "exec(", content)
        self.assertNotIn("https:" + "//", content)
        for p in ("src/IncidentTimeline.php", "src/Postmortem.php",
                  "src/InfrastructureActionProjection.php"):
            self.assertTrue((ROOT / p).is_file(), p)
        report = infrastructure()
        self.assertTrue(all(report[k]["execution"] is False
                            for k in ("planned", "owner", "denied")))
        dump = json.dumps(report).lower()
        for marker in ("github_pat_", "supersecret", "bearer "):
            self.assertNotIn(marker, dump)

    def test_validar_exact_head(self) -> None:
        """AC-04: el workflow selecciona HEAD exacto; CI remoto sigue siendo autoridad."""
        workflow = (ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        self.assertIn("name: Validar", workflow)
        self.assertIn("github.event.pull_request.head.sha", workflow)
        self.assertIn("pull_request:", workflow)
        self.assertNotIn("continue-on-error: true", workflow)


if __name__ == "__main__":
    unittest.main()
