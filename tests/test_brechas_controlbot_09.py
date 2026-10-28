#!/usr/bin/env python3
"""ControlBot #1016: CB-0122..0136; solo contratos PHP y fixtures sintéticos.

El contraste offline no certifica salud live, autorización humana ni ejecución.
"""
from __future__ import annotations

import json
import re
import subprocess
import unittest
from functools import lru_cache
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPTS = {
    "feedback": "customer_feedback_signal_scenarios.php",
    "core": "customer_success_core_scenarios.php",
    "inbox": "customer_success_owner_inbox_scenarios.php",
    "work": "customer_success_work_origin_scenarios.php",
    "rights": "decision_rights_scenarios.php",
}
IDS = {f"CB-{n:04d}" for n in range(122, 137)}
REF = re.compile(r"(?:feedback|evidence):[0-9a-f]{32}\Z")


@lru_cache(maxsize=35)
def scenario(domain: str, name: str) -> dict:
    """Ejecuta solo un harness PHP propio; nunca consulta servicios remotos."""
    script = ROOT / "tests" / SCRIPTS[domain]
    completed = subprocess.run(
        ["php", str(script), name], cwd=ROOT, capture_output=True,
        text=True, timeout=20, check=False,
    )
    if completed.returncode:
        raise AssertionError(
            f"Fixture {domain}/{name} rechazado: {completed.stderr.strip()}"
        )
    value = json.loads(completed.stdout)
    if not isinstance(value, dict):
        raise AssertionError(f"Fixture {domain}/{name} no es un objeto")
    return value


def dimension(snapshot: dict, name: str) -> dict:
    return next(row for row in snapshot["dimensions"] if row["name"] == name)


class BrechasControlbot09Tests(unittest.TestCase):
    """Cada ID tiene un predicado observable y su falla queda identificada."""

    def test_contrato_principal(self) -> None:
        feedback = scenario("feedback", "fail_closed")
        fb_scope = scenario("feedback", "scope")
        fb_closed = scenario("feedback", "closed")
        churn = scenario("core", "churn")
        freshness = scenario("core", "freshness")
        privacy = scenario("core", "privacy")
        inbox = scenario("inbox", "support")["entry"]
        inbox_closed = scenario("inbox", "closed")
        inbox_explicit = scenario("inbox", "explicit")
        inbox_compatible = scenario("inbox", "compatible")
        inbox_snapshot = scenario("inbox", "snapshot")
        work_scope = scenario("work", "scope")
        work_explicit = scenario("work", "explicit")
        work_limited = scenario("work", "limited")
        work_deterministic = scenario("work", "deterministic")
        allow = scenario("rights", "allow-exact")
        rights_restrictions = scenario("rights", "restrictions")
        rights_cross = scenario("rights", "cross-venture")
        untrusted = scenario("rights", "untrusted")
        source_signal = fb_scope["good"]
        risk = dimension(churn["valid"], "churn_risk")
        unknown_dim = dimension(freshness["unknown_dimension"], "usage_recency")
        stale_dim = dimension(freshness["stale"], "adoption")
        work = work_scope["support"]
        verdicts = {
            "CB-0122": (
                feedback["churn"]["nature"] == "inferred"
                and feedback["churn"]["confidence"] == 0.42
                and feedback["unknownHealth"]["nature"] == "unknown"
                and feedback["unknownHealth"]["evidence_refs"] == []
            ),
            "CB-0123": (
                bool(REF.fullmatch(source_signal["feedback_ref"]))
                and bool(source_signal["evidence_refs"])
                and all(REF.fullmatch(x) for x in source_signal["evidence_refs"])
                and fb_closed["forgedSource"] and fb_closed["forgedScope"]
            ),
            "CB-0124": (
                risk["nature"] == "inferred"
                and 0 < risk["confidence"] < 1
                and churn["observed_churn_rejected"]
            ),
            "CB-0125": (
                stale_dim["freshness"] == "stale"
                and unknown_dim["status"] == "unknown"
                and unknown_dim["value_ref"] is None
                and unknown_dim["evidence_ref"] is None
                and unknown_dim["confidence"] == 0
            ),
            "CB-0126": (
                all(privacy.values())
                and not any(k in scenario("core", "scope")["support"]
                            for k in ("transcript", "ticket_body", "attachments", "email"))
            ),
            "CB-0127": (
                inbox["freshness"] == "current"
                and inbox_closed["stale"]["freshness"] == "stale"
                and inbox_closed["unknown"]["freshness"] == "unknown"
                and inbox_closed["unknown"]["source_ref"] is None
                and inbox_closed["unknown"]["observed_at"] is None
                and inbox_closed["unknown"]["evidence_refs"] == []
            ),
            "CB-0128": (
                inbox["scope"] == {"kind": "venture", "ref": "controlbot:venture/venture-condor"}
                and inbox_compatible["entry"] == inbox_compatible["again"]
            ),
            "CB-0129": (
                inbox["class"] == "watch"  # support severity is critical
                and inbox_snapshot["dimension"]["nature"] == "inferred"
                and inbox_snapshot["entry"]["class"] == "fyi"
                and inbox_explicit["watch"]["required_authority_level"] is None
                and inbox_explicit["decision"]["required_authority_level"] == "L4_OWNER"
            ),
            "CB-0130": (
                work_explicit["severity"] == "critical"
                and work_explicit["priority_class"] == "medium"
                and work_explicit["work_type"] == "knowledge_documentation"
                and work_explicit["authority_level"] == "l2_team"
                and work_explicit["requested_capabilities"] == ["support_analysis"]
                and work_explicit["required_roles"] == ["contenido"]
                and work_explicit["policy_ref"] == "factory:customer-success-v1"
                and work_explicit["claims"] == ["src/customer"]
                and work_explicit["depends_on"] == []
            ),
            "CB-0131": (
                work_limited["missing_observed"]
                and work["observed_at"] == "2026-09-29T19:30:00Z"
                and work_limited["unknown"]["observed_at"] == work["observed_at"]
            ),
            "CB-0132": (
                any(x.startswith("controlbot:customer-success/support:")
                    for x in work["evidence_refs"])
                and any(x.startswith("evidence:") for x in work["evidence_refs"])
                and work["evidence_refs"] == sorted(set(work["evidence_refs"]))
            ),
            "CB-0133": (
                work_deterministic["first"]["idempotency_key"]
                == work_deterministic["second"]["idempotency_key"]
                and work_deterministic["first"]["idempotency_key"]
                != work_deterministic["changed"]["idempotency_key"]
            ),
            "CB-0134": (
                allow["allowed"]["decision"] == "allow"
                and rights_cross["result"] == {"decision": "deny", "reasons": ["scope_mismatch"]}
                and rights_restrictions["policy"] == {"decision": "deny", "reasons": ["policy_not_active"]}
            ),
            "CB-0135": (
                rights_restrictions["budget"]["decision"] == "owner_decision_required"
                and rights_restrictions["authority"]["decision"] == "owner_decision_required"
                and allow["wrong_capability"]["decision"] == "deny"
            ),
            "CB-0136": (
                untrusted["action_claims"] == {"decision": "deny", "reasons": ["invalid_input"]}
                and untrusted["context_secret"] == {"decision": "deny", "reasons": ["invalid_input"]}
                and not untrusted["leaked"]
            ),
        }
        self.assertEqual(set(verdicts), IDS)
        for clause, result in verdicts.items():
            with self.subTest(clause=clause):
                self.assertTrue(result, f"{clause}: no verificado; UNKNOWN")

    def test_falla_cerrado(self) -> None:
        fb = scenario("feedback", "closed")
        core = scenario("core", "freshness")
        inbox = scenario("inbox", "closed")
        work = scenario("work", "limited")
        rights = scenario("rights", "invalid")
        closed = {
            "CB-0123/ref-falsificada": fb["forgedSource"] and fb["forgedScope"],
            "CB-0125/unknown-con-evidencia": core["unknown_with_evidence_rejected"],
            "CB-0126/contenido-sensible": all(scenario("core", "privacy").values()),
            "CB-0127/scope-ajeno": inbox["cross"] and inbox["missing"],
            "CB-0128/payload-extra": inbox["extra"] and inbox["pii"],
            "CB-0130/scope-ajeno": scenario("work", "scope")["cross"],
            "CB-0131/fecha-ausente": work["missing_observed"],
            "CB-0134/scope-invalido": rights["unknown_scope"]["decision"] == "deny",
            "CB-0135/autoridad-desconocida": rights["unknown_authority"]["decision"] == "deny",
            "CB-0136/reclamaciones-cliente": not scenario("rights", "untrusted")["leaked"],
        }
        for clause, result in closed.items():
            with self.subTest(clause=clause):
                self.assertTrue(result, f"{clause} no falla cerrado")

    def test_sin_datos_reales(self) -> None:
        for domain, script in SCRIPTS.items():
            with self.subTest(harness=domain):
                path = ROOT / "tests" / script
                self.assertTrue(path.is_file())
                self.assertEqual(path.parent, ROOT / "tests")
        samples = [
            scenario("feedback", "scope")["good"],
            scenario("core", "scope")["support"],
            scenario("inbox", "support")["entry"],
            scenario("work", "scope")["support"],
            scenario("rights", "allow-exact")["allowed"],
        ]
        for index, sample in enumerate(samples):
            with self.subTest(sample=index):
                payload = json.dumps(sample, sort_keys=True).lower()
                for forbidden in ("transcript", "ticket_body", "message_body",
                                  "attachment_content", "person@example.test", "bearer ", "github_pat_"):
                    self.assertNotIn(forbidden, payload)
                for execution_field in ("live_ready", "go_live", "deployed"):
                    self.assertNotIn(execution_field, sample)

    def test_estados_unknown_explicitos(self) -> None:
        feedback = scenario("feedback", "fail_closed")
        core = scenario("core", "freshness")
        inbox = scenario("inbox", "closed")["unknown"]
        limited = scenario("work", "limited")
        self.assertEqual(feedback["unknownSupport"]["freshness"], "unknown")
        self.assertEqual(feedback["unknownSupport"]["nature"], "unknown")
        self.assertIsNone(feedback["unknownSupport"]["observed_at"])
        self.assertEqual(feedback["unknownSupport"]["evidence_refs"], [])
        self.assertIsNone(core["unknown"]["evidence_ref"])
        self.assertEqual(inbox["freshness"], "unknown")
        self.assertEqual(inbox["evidence_refs"], [])
        self.assertIsNone(inbox["source_ref"])
        self.assertIsNone(inbox["observed_at"])
        for key in ("unknown", "stale", "churn"):
            with self.subTest(work_origin=key):
                for field in ("ready", "approved", "authorized", "executor", "provider", "model"):
                    self.assertNotIn(field, limited[key])
        # Una prueba offline de contrato no verifica ninguno de los 15 estados live.
        live_status = dict.fromkeys(IDS, "UNKNOWN")
        self.assertEqual(set(live_status), IDS)
        self.assertTrue(all(state == "UNKNOWN" for state in live_status.values()))


if __name__ == "__main__":
    unittest.main()
