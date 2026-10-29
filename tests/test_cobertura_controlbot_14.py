#!/usr/bin/env python3
"""Issue #1197: exercise the real PHP Executive Cockpit and External API.

The existing local PHP scenarios inject synthetic decisions and sessions.
No matrix file or row state is read; this is not live authorization/dispatch.
"""
from __future__ import annotations

from functools import lru_cache
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPTS = {
    "cockpit": "tests/executive_cockpit_scenarios.php",
    "ui": "tests/executive_cockpit_ui_scenarios.php",
    "read": "tests/external_api_authenticated_read_scenarios.php",
    "work": "tests/external_api_directed_work_origin_scenarios.php",
}
ROWS = frozenset({
    "CB-0204", "CB-0205", "CB-0206", "CB-0207", "CB-0208",
    "CB-0209", "CB-0210", "CB-0211", "CB-0212", "CB-0213",
    "CB-0214", "CB-0215", "CB-0216", "CB-0217", "CB-0218",
    "CB-0219", "CB-0220",
})
CLASSIFIED = {
    "CB-0211": ("C", "Division of Factory, FactoryRunner, Decision Rights "
                "and AEGIS ownership is architectural, not UI behavior."),
    "CB-0213": ("C", "Absence of a second authorization/data model is an "
                "architecture constraint; real gate and projection are "
                "exercised in CB-0212 and CB-0214."),
}
OFFLINE = ROWS - CLASSIFIED.keys()


@lru_cache(maxsize=None)
def scenario(area: str, case: str, raw: bool = False):
    """Execute checked-in PHP implementation with synthetic, local fixtures."""
    php = shutil.which("php")
    if php is None:
        raise AssertionError("UNKNOWN: PHP CLI unavailable")
    script = ROOT / SCRIPTS[area]
    if not script.is_file():
        raise AssertionError("UNKNOWN: missing PHP domain scenario " + area)
    result = subprocess.run(
        [php, "-d", "allow_url_fopen=0", str(script), case],
        cwd=ROOT, capture_output=True, text=True, check=False, timeout=35,
    )
    warning = result.stderr.strip()
    # PHP 8.5 warns about a redundant global 'use' in this checked-in
    # scenario. Match that exact fixture warning, never suppress other stderr.
    if area == "work":
        harmless = (
            "PHP Warning:  The use statement with non-compound name "
            "'InvalidArgumentException' has no effect in "
            + str(script) + " on line 15"
        )
        if warning == harmless:
            warning = ""
    if result.returncode or warning:
        raise AssertionError(f"UNKNOWN: real PHP {area}/{case} failed: "
                             + warning[-350:])
    if raw:
        if not result.stdout.strip():
            raise AssertionError("UNKNOWN: empty HTML")
        return result.stdout
    try:
        return json.loads(result.stdout)
    except json.JSONDecodeError as exc:
        raise AssertionError("UNKNOWN: PHP did not emit valid JSON") from exc


class CoberturaControlbot14Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """Exercise 15 offline rows through four real PHP implementations."""
        self.assertEqual(len(ROWS), 17)
        self.assertEqual(len(OFFLINE), 15)
        self.assertEqual(OFFLINE | CLASSIFIED.keys(), ROWS)
        self.assertFalse(OFFLINE & CLASSIFIED.keys())
        self.assertTrue(all(kind == "C" and len(reason) > 40
                            for kind, reason in CLASSIFIED.values()))

        base = scenario("cockpit", "base")
        stale = scenario("cockpit", "stale")
        ordered = scenario("cockpit", "order")
        venture = base["ventures"][0]

        # CB-0204: no fabricated global health/ranking; stable Venture order.
        self.assertEqual(ordered["group_id"], "group-one")
        self.assertEqual([row["venture"]["venture_id"] for row in ordered["ventures"]],
                         ["venture-alpha", "venture-beta"])
        self.assertNotIn("score", json.dumps(base).lower())
        self.assertNotIn("ranking", json.dumps(base).lower())

        # CB-0205: stale business and unknown technical never turn healthy.
        aged = stale["ventures"][0]
        self.assertEqual(aged["business_health"]["freshness"], "stale")
        self.assertEqual(aged["business_health"]["state"], "degraded")
        self.assertEqual(aged["technical_health"], {
            "state": "unknown", "freshness": "unknown",
            "source_ref": None, "observed_at": None,
        })
        self.assertEqual(aged["product_health"]["freshness"], "unknown")
        self.assertEqual(venture["business_health"]["state"], "healthy")
        self.assertEqual(venture["technical_health"]["state"], "healthy")

        # CB-0206: runtime is bound to the canonical Venture, not manual UI.
        self.assertEqual(venture["runtime"]["source"], "factoryrunner")
        self.assertEqual(venture["runtime"]["provider_id"], "runner-one")
        self.assertEqual(venture["runtime"]["total_capacity"], 2)
        self.assertEqual(venture["runtime"]["occupied_capacity"], 0)

        # CB-0207: finance, infrastructure, product, runtime reject wrong scope.
        self.assertEqual(scenario("cockpit", "scope"), {
            "finance": True, "infra": True, "runtime": True, "product": True,
        })
        self.assertIs(scenario("cockpit", "duplicate")["duplicate"], True)

        # CB-0208: server-rendered UI consumes canonical snapshot, no action.
        html = scenario("ui", "base", raw=True)
        self.assertIn('name="viewport"', html)
        self.assertIn("<main>", html)
        self.assertIn('id="venture-venture-alpha"', html)
        self.assertIn("owner-alpha", html)
        self.assertIn("factoryrunner", html)

        # CB-0209: business/technical remain visibly separate, no fake green.
        stale_ui = scenario("ui", "stale", raw=True)
        self.assertIn("Business health", stale_ui)
        self.assertIn("Technical health", stale_ui)
        self.assertIn("freshness stale", stale_ui)
        self.assertIn("freshness unknown", stale_ui)
        self.assertNotIn("health-healthy fresh-stale", stale_ui)
        self.assertNotIn("health-healthy fresh-unknown", stale_ui)

        # CB-0210: four Inbox categories and decision references are read-only.
        for kind in ("FYI", "WATCH", "DECISION", "CRITICAL"):
            self.assertIn(kind, html)
        self.assertIn("controlbot:decision/decide-aa", html)
        self.assertIn("L4_OWNER", html)
        for forbidden in ("<button", "<form", "approve", "reject"):
            self.assertNotIn(forbidden, html.lower())

        # CB-0211 (C): system role split is architectural, not local behavior.

        # CB-0212: server-verified GET composes the public projection.
        public_cockpit = scenario("read", "cockpit")
        public_inbox = scenario("read", "inbox")
        self.assertEqual(set(public_cockpit), {"meta", "data"})
        self.assertEqual(set(public_inbox), {"meta", "data"})
        self.assertEqual(public_cockpit["data"]["ventures"][0]["venture_ref"],
                         "controlbot:venture/alpha")
        self.assertEqual(public_inbox["data"]["entries"][0]["kind"], "DECISION")
        self.assertEqual(public_inbox["data"]["entries"][0]["decision_id"],
                         "decision-alpha")

        # CB-0213 (C): no separate data/authorization model is introduced.

        # CB-0214: exact public DTO; unknown provenance stays null.
        projected = scenario("read", "shape")
        self.assertEqual(set(projected["cockpit"]["data"]), {"ventures"})
        self.assertEqual(set(projected["inbox"]["data"]), {"entries"})
        self.assertEqual(projected["cockpit"]["meta"]["freshness"], {
            "state": "unknown", "observed_at": None, "source_ref": None,
        })

        # CB-0215: mobile input may not supply authority/policy/work.
        self.assertEqual(scenario("work", "client"), {
            "authority": True, "policy": True, "work": True,
        })
        approved = scenario("work", "approve")
        item = approved["work_item"]

        # CB-0216: actual runtime context, not caller data, supplies authority.
        self.assertEqual(item["authority_level"], "l4_owner")
        self.assertTrue(item["producer_ref"].startswith("controlbot:identity/"))
        self.assertEqual(item["policy_ref"], "controlbot:policy/external-owner-v1")
        self.assertEqual(item["origin_mode"], "directed")
        self.assertEqual(item["origin_system"], "human")

        # CB-0217: reject records mutation_rejected with no WorkItem.
        rejection = scenario("work", "reject")["result"]
        self.assertEqual(rejection["decision"]["outcome"], "reject")
        self.assertIsNone(rejection["decision"]["approval_ref"])
        self.assertIsNone(rejection["work_item"])
        self.assertIsNone(rejection["work_item_ref"])
        self.assertEqual(rejection["audit"]["mutation"]["outcome"],
                         "mutation_rejected")
        self.assertIsNone(rejection["audit"]["factory_handoff"])

        # CB-0218: policy must belong to verified runtime policy refs.
        self.assertIs(scenario("work", "policy")["unverified_policy"], True)

        # CB-0219: retry identity remains stable despite distinct request IDs.
        retry = scenario("work", "retry")
        self.assertEqual(retry["first"]["work_item_ref"],
                         retry["second"]["work_item_ref"])
        self.assertEqual(retry["first"]["work_item"]["work_id"],
                         retry["second"]["work_item"]["work_id"])
        self.assertEqual(retry["first"]["work_item"]["idempotency_key"],
                         retry["second"]["work_item"]["idempotency_key"])

        # CB-0220: directed work fulfils canonical Factory Queue v1 contract.
        required = {
            "work_id", "origin_mode", "origin_system", "group_id", "work_type",
            "requested_capabilities", "required_roles", "authority_level",
            "producer_ref", "priority_class", "depends_on", "claims",
            "policy_ref", "approval_ref", "evidence_refs", "idempotency_key",
            "observed_at",
        }
        self.assertTrue(required.issubset(item))
        self.assertEqual(item["requested_capabilities"], ["php"])
        self.assertEqual(item["required_roles"], ["ingenieria-software", "qa"])
        self.assertEqual(approved["audit"]["factory_handoff"]["work_item_ref"],
                         approved["work_item_ref"])

    def test_falla_cerrado(self) -> None:
        """Fail closed on invalid and ambiguous scopes, access and intent."""
        self.assertTrue(all(scenario("cockpit", "scope").values()))
        self.assertIs(scenario("cockpit", "duplicate")["duplicate"], True)
        # Renderer rejects extra hand-authored fields and sensitive PII/secret.
        invalid_html = scenario("ui", "unsafe")
        self.assertTrue(invalid_html)
        self.assertTrue(all(invalid_html.values()))
        # Real authenticated GET gate rejects scope, revoked session, grants.
        read_invalid = scenario("read", "invalid")
        for name in ("wrong_scope", "revoked", "wrong_capability"):
            self.assertIs(read_invalid[name], True, name)
        # Mutation requires step-up and coherent identity and session scope.
        mutation = scenario("work", "authorized")
        self.assertEqual(mutation["ok"]["decision"]["outcome"], "approve")
        for name in ("no_step", "wrong_scope", "revoked"):
            self.assertIs(mutation[name], True, name)
        self.assertTrue(all(scenario("work", "client").values()))
        self.assertIs(scenario("work", "reject")["with_work"], True)
        self.assertEqual(scenario("work", "policy"), {
            "unverified_policy": True, "invalid_repo": True, "secret": True,
        })

    def test_sin_datos_reales(self) -> None:
        """No real identities, credentials or client-authorized work in output."""
        public = json.dumps(scenario("read", "shape"), sort_keys=True).lower()
        for bad in ("identity_id", "authority_level", "policy_ref", "grant_id",
                    "session_ref", "device_ref", "step_up_ref", "access_token",
                    "refresh_token", "authorization", "password"):
            self.assertNotIn(bad, public)
        html = scenario("ui", "base", raw=True).lower()
        for bad in ("<script", 'href="http', 'href="//', "<form", "<button"):
            self.assertNotIn(bad, html)
        self.assertIn("@media(prefers-reduced-motion:reduce)", html)
        approved = scenario("work", "approve")
        self.assertEqual(approved["audit"]["factory_handoff"]["outcome"],
                         "factory_handoff")
        for bad in ("runner_id", "order_id", "dispatch_id", "executed_at",
                    "provider_result"):
            self.assertNotIn(bad, json.dumps(approved).lower())
        for kind, path in SCRIPTS.items():
            with self.subTest(kind=kind):
                self.assertTrue((ROOT / path).is_file())

    def test_validar_exigido(self) -> None:
        """Executable AC-04; actual GitHub Validar exact-HEAD is independent."""
        workflow = ROOT / ".github/workflows/ci.yml"
        self.assertTrue(workflow.is_file())
        config = workflow.read_text(encoding="utf-8")
        self.assertIn("Pruebas de contrato e integración", config)
        self.assertIn("python3 scripts/generate_coverage.py", config)
        pure = scenario("read", "pure")
        self.assertEqual(pure["methods"], ["cockpit", "ownerInbox"])
        self.assertEqual(pure["first"], pure["second"])
        self.assertEqual(scenario("work", "pure")["methods"], ["process"])


if __name__ == "__main__":
    unittest.main()
