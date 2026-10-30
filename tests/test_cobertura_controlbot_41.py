#!/usr/bin/env python3
"""ControlBot #1224: 15 contratos conductuales LEX, sin datos reales ni red.

CB-0665 y CB-0682 son declaraciones de política/fuera de alcance (C), no
comportamientos independientemente demostrables de los módulos LEX.
"""
from __future__ import annotations

import copy
import json
import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
A = tuple(f"CB-{n:04d}" for n in range(666, 673)) + tuple(
    f"CB-{n:04d}" for n in range(674, 682)
)
C = {
    "CB-0665": "Política y finalidad LEX; no tiene acción runtime independiente.",
    "CB-0682": "Exclusiones de crawler, UI, providers y go-live; no hay conducta que ejecutar.",
}
ALL = set(A) | set(C)
NOW = 1200
EVIDENCE = "controlbot:lex/evidence/synthetic"
PHP = r"""
require 'src/LexLifecycle.php';
require 'src/LexWatch.php';
try {
    $raw = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($raw) || array_keys($raw) !== ['kind', 'payload']) {
        throw new \InvalidArgumentException('invalid fixture');
    }
    if ($raw['kind'] === 'lifecycle') {
        $result = \ControlBot\Legal\LexLifecycle::project($raw['payload']);
    } elseif ($raw['kind'] === 'watch') {
        $result = \ControlBot\Legal\LexWatch::normalize($raw['payload'], 1200);
    } else {
        throw new \InvalidArgumentException('invalid fixture kind');
    }
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (\Throwable $error) {
    fwrite(STDERR, "REJECTED\n");
    exit(3);
}
"""


def lifecycle(**updates) -> dict:
    row = {
        "version": 1, "scope": "project:synthetic",
        "stage": "construction", "legal_state": "unknown",
        "material_risk": False, "reversible_work": True,
        "real_data_requested": False,
        "human_review": {
            "state": "pending", "scope": "project:synthetic",
            "evidence_refs": [],
        },
    }
    return dict(row, **updates)


def review(state: str, *, scope: str = "project:synthetic",
           refs: list[str] | None = None) -> dict:
    return {"state": state, "scope": scope,
            "evidence_refs": ([EVIDENCE] if refs is None else refs)}


def signal(name: str, **updates) -> dict:
    row = {
        "signal_id": name,
        "jurisdiction": "CO",
        "regulation_ref": "controlbot:lex/regulation/synthetic",
        "change_ref": "controlbot:lex/change/" + name,
        "source_state": "verified",
        "source_refs": ["controlbot:lex/source/synthetic"],
        "evidence_refs": [EVIDENCE],
        "impact_state": "plausible",
        "impact_refs": ["controlbot:venture/synthetic"],
        "published_at": 1000, "effective_at": 1100,
        "observed_at": 1050, "freshness": "fresh",
    }
    return dict(row, **updates)


def php(kind: str, payload: dict, *, expected_ok: bool = True):
    completed = subprocess.run(
        ["php", "-r", PHP],
        cwd=ROOT,
        input=json.dumps({"kind": kind, "payload": payload}),
        capture_output=True, text=True, check=False, timeout=15,
    )
    if expected_ok:
        if completed.returncode != 0:
            raise AssertionError("PHP LEX rejected synthetic fixture: "
                                 + completed.stderr[:180])
        return json.loads(completed.stdout)
    if completed.returncode != 3 or completed.stderr.strip() != "REJECTED":
        raise AssertionError("Expected fail-closed PHP LEX response")
    return None


def watch(*signals: dict) -> dict:
    return php("watch", {"version": 1, "signals": list(signals)})


class CoberturaControlbot41Tests(unittest.TestCase):
    def test_contrato_principal(self):
        """AC-01: aserciones sobre LexLifecycle::project y LexWatch::normalize."""
        # CB-0666/0667: construcción reversible y revisión pendiente no
        # significan COMPLIANT, pero permiten solo el scope técnico seguro.
        pending = php("lifecycle", lifecycle())
        self.assertEqual("documented_not_legally_approved",
                         pending["construction_legal_status"])
        self.assertNotEqual("compliant", pending["legal_state"])
        self.assertTrue(pending["construction_work_allowed"])
        self.assertTrue(pending["transition_allowed"])
        self.assertEqual("blocked", pending["live_legal_gate"]["state"])
        self.assertFalse(pending["live_legal_gate"]["required_now"])
        self.assertFalse(php("lifecycle", lifecycle(material_risk=True))[
            "construction_work_allowed"])
        self.assertFalse(php("lifecycle", lifecycle(
            human_review=review("rejected")))["construction_work_allowed"])

        # CB-0668/0669/0670/0672: live/real-data: revisión humana aprobada
        # referenciada y del mismo scope; ni GAP ni UNKNOWN elevan permisos.
        approved = lifecycle(
            stage="live", legal_state="compliant",
            human_review=review("approved"))
        passed = php("lifecycle", approved)
        self.assertEqual("pass", passed["live_legal_gate"]["state"])
        self.assertEqual([EVIDENCE], passed["live_legal_gate"]["evidence_refs"])
        self.assertTrue(passed["transition_allowed"])
        for changed in (
            {"stage": "live"},
            {"real_data_requested": True},
            {"stage": "live", "legal_state": "gap",
             "human_review": review("approved")},
            {"stage": "live", "legal_state": "unknown",
             "human_review": review("approved")},
            {"stage": "live", "legal_state": "compliant",
             "human_review": review("approved", scope="project:other")},
            {"stage": "live", "legal_state": "compliant",
             "human_review": review("rejected")},
            {"stage": "live", "legal_state": "compliant",
             "human_review": review("approved"), "material_risk": True},
        ):
            with self.subTest(gate=changed):
                result = php("lifecycle", lifecycle(**changed))
                self.assertFalse(result["transition_allowed"])
                self.assertEqual("blocked", result["live_legal_gate"]["state"])
        other = php("lifecycle", lifecycle(
            stage="live", legal_state="not_applicable",
            human_review=review("approved", scope="project:other")))
        self.assertIn("human_review_scope_mismatch",
                      other["live_legal_gate"]["reasons"])
        self.assertFalse(other["transition_allowed"])

        # CB-0671: la proyección jamás ejecuta ni concede autoridad.
        for result in (pending, passed):
            self.assertEqual("none", result["authority_effect"])
            self.assertIs(result["auto_execute"], False)

        # CB-0674/0675/0676/0677/0678: fuente verificada y fresca,
        # impacto trazable, fechas y vigencia separada de publicación.
        published = signal("signal-a")
        future = signal("signal-future", effective_at=1400)
        none = signal("signal-none", impact_state="none", impact_refs=[])
        unknown = signal("signal-unknown", impact_state="unknown", impact_refs=[])
        rumor = signal("signal-rumor", source_state="rumor",
                       published_at=None, effective_at=None, evidence_refs=[])
        stale = signal("signal-stale", freshness="stale")
        rows = watch(published, future, none, unknown, rumor, stale)
        by_id = {entry["signal_ids"][0]: entry for entry in rows["signals"]}
        self.assertEqual("plausible", by_id["signal-a"]["impact_state"])
        self.assertEqual(["controlbot:venture/synthetic"],
                         by_id["signal-a"]["impact_refs"])
        self.assertEqual("published", by_id["signal-a"]["publication_state"])
        self.assertEqual("effective", by_id["signal-a"]["effective_state"])
        self.assertEqual("not_yet_effective", by_id["signal-future"]["effective_state"])
        self.assertEqual("published", by_id["signal-future"]["publication_state"])
        self.assertEqual("unknown", by_id["signal-rumor"]["publication_state"])
        self.assertEqual("unknown", by_id["signal-stale"]["effective_state"])

        # CB-0679: identidad semántica deduped; refs/IDs unificados y
        # ordenados, con resultado independiente del orden de entrada.
        first = signal("signal-b", change_ref="controlbot:lex/change/shared",
                       source_refs=["controlbot:lex/source/b"],
                       evidence_refs=["controlbot:lex/evidence/b"])
        second = signal("signal-a", change_ref="controlbot:lex/change/shared",
                        source_refs=["controlbot:lex/source/a"],
                        evidence_refs=["controlbot:lex/evidence/a"],
                        observed_at=1080)
        forward = watch(first, second)
        self.assertEqual(forward, watch(second, first))
        merged = forward["signals"][0]
        self.assertEqual(["signal-a", "signal-b"], merged["signal_ids"])
        self.assertEqual(["controlbot:lex/source/a", "controlbot:lex/source/b"],
                         merged["source_refs"])
        self.assertEqual(1080, merged["observed_at"])

        # CB-0680/0681: solo verified+fresh+plausible+refs produce
        # candidato read-only, nunca aprobación ni acción.
        self.assertEqual(2, len(rows["review_candidates"]))
        for candidate in rows["review_candidates"]:
            self.assertEqual("legal_review", candidate["kind"])
            self.assertEqual("verified", candidate["source_state"])
            self.assertEqual("fresh", candidate["freshness"])
            self.assertEqual("published", candidate["publication_state"])
            self.assertTrue(candidate["impact_refs"])
            self.assertNotIn("compliant", candidate)
            self.assertNotIn("authority", candidate)
        for key in ("signal-none", "signal-unknown", "signal-rumor",
                    "signal-stale"):
            self.assertFalse(by_id[key]["review_candidate"])

    def test_falla_cerrado(self):
        """AC-02: scope, evidencia, fechas, secreto y dedupe ambiguo."""
        php("lifecycle", lifecycle(
            legal_state="compliant", human_review=review(
                "approved", refs=[])), expected_ok=False)
        php("lifecycle", lifecycle(
            human_review=review("approved", refs=[
                "controlbot:lex/evidence/../escape"])), expected_ok=False)
        php("lifecycle", lifecycle(scope="invalid"), expected_ok=False)
        php("lifecycle", lifecycle(reversible_work="true"), expected_ok=False)

        php("watch", {"version": 1, "signals": [
            signal("signal-future", observed_at=1300)]}, expected_ok=False)
        php("watch", {"version": 1, "signals": [
            signal("signal-future", published_at=1100)]}, expected_ok=False)
        php("watch", {"version": 1, "signals": [
            signal("signal-token", source_refs=[
                "controlbot:lex/source/api-token"])]}, expected_ok=False)
        php("watch", {"version": 1, "signals": [
            signal("signal-plausible", impact_refs=[])]}, expected_ok=False)
        php("watch", {"version": 1, "signals": [
            signal("signal-conflict", change_ref="controlbot:lex/change/shared"),
            signal("signal-other", change_ref="controlbot:lex/change/shared",
                   freshness="stale"),
        ]}, expected_ok=False)
    def test_sin_datos_reales(self):
        """AC-03: ningún fixture usa red, credenciales ni PII."""
        sample = json.dumps({"lifecycle": lifecycle(),
                             "watch": signal("signal-synthetic")})
        self.assertNotIn("https://", sample)
        self.assertNotIn("@", sample)
        self.assertNotIn("password", sample.lower())
        self.assertNotIn("private_key", sample.lower())
        self.assertEqual(15, len(A))
        self.assertEqual(2, len(C))
        self.assertTrue(all(reason for reason in C.values()))
        for result in (php("lifecycle", lifecycle()), watch(signal("signal-test"))):
            self.assertIsInstance(result, dict)

    def test_gate_validar_presente(self):
        """AC-04: verificar agregador real; success exact-HEAD corresponde a CI."""
        ci = (ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        self.assertEqual(1, len(re.findall(r"(?m)^  validar:$", ci)))
        gate = ci.split("\n  validar:\n", 1)[1]
        self.assertIn("name: Validar", gate)
        self.assertIn("needs: [factory, contrato]", gate)
        self.assertIn("Exigir gates previos", gate)


if __name__ == "__main__":
    unittest.main()
