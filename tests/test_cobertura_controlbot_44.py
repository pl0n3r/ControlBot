#!/usr/bin/env python3
"""#1227: 18 independent real-PHP, offline behavioral contracts (CB-0718..0736).
Runs existing PHP scenario scripts which import the *real* Market/MOMENTUM
classes. Their only input is checked-in synthetic fixtures. This suite never
calls providers, reads secrets or certifies production/legal authorization.
Coverage reconciliation is tracked separately from real behavior tests.
"""
from __future__ import annotations
import json
import re
import shutil
import subprocess
import unittest
from functools import lru_cache
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
SCRIPTS = {
    "lifecycle": "market_scope_lifecycle_scenarios.php",
    "work": "market_work_origin_scenarios.php",
    "campaign": "momentum_campaign_scenarios.php",
    "creative": "momentum_creative_scenarios.php",
    "email": "momentum_email_scenarios.php",
}
DOCUMENTS = {
    **{f"CB-{n:04d}": "docs/market-scope-lifecycle.md" for n in range(718, 722)},
    **{f"CB-{n:04d}": "docs/market-work-origin.md" for n in range(722, 725)},
    **{f"CB-{n:04d}": "docs/momentum-campaign-contract.md" for n in range(726, 729)},
    **{f"CB-{n:04d}": "docs/momentum-creative-contract.md" for n in range(729, 731)},
    **{f"CB-{n:04d}": "docs/momentum-email-contract.md" for n in range(731, 737)},
}
OPAQUE = re.compile(r"^[a-z][a-z-]*:[a-f0-9]{32}$")
WORK_EVIDENCE = re.compile(r"^controlbot:[a-z][a-z0-9-]{1,31}/[a-f0-9]{32}$")
REQUIRED_WORK = {
    "work_id", "origin_mode", "origin_system", "group_id", "work_type",
    "requested_capabilities", "required_roles", "authority_level",
    "priority_class", "depends_on", "claims", "policy_ref",
    "evidence_refs", "idempotency_key",
}
OPTIONAL_WORK = {
    "producer_ref", "requested_by", "venture_id", "project_id",
    "repository_ref", "severity", "budget_ref", "approval_ref",
    "observed_at",
}
@lru_cache(maxsize=40)
def php(domain: str, case: str) -> dict:
    """Whitelisted local PHP processes importing production classes."""
    if domain not in SCRIPTS:
        raise AssertionError("Unknown fixture family")
    allowed = {
        "lifecycle": {"tbd", "delta", "global", "pure", "minimal"},
        "work": {"fresh", "closed", "governance", "deterministic", "e2e",
                 "context_evidence", "context_evidence_limit"},
        "campaign": {"scope", "neutral", "privacy", "closed", "pure"},
        "creative": {"scope", "neutral", "lifecycle", "set"},
        "email": {"scope", "eligibility", "audit", "invalid", "pure"},
    }
    if case not in allowed[domain]:
        raise AssertionError("Unapproved PHP scenario")
    php_bin = shutil.which("php")
    if php_bin is None:
        raise AssertionError("Actual PHP interpreter is required")
    script = ROOT / "tests" / SCRIPTS[domain]
    if not script.is_file():
        raise AssertionError("Checked-in real PHP scenario absent")
    done = subprocess.run(
        [php_bin, "-d", "allow_url_fopen=0", str(script), case],
        cwd=ROOT, capture_output=True, text=True, timeout=30, check=False,
    )
    if done.returncode != 0 or done.stderr.strip():
        raise AssertionError(f"Real PHP {domain}/{case} failed: {done.stderr[-450:]}")
    try:
        result = json.loads(done.stdout)
    except json.JSONDecodeError as error:
        raise AssertionError("Invalid real-PHP scenario response") from error
    if not isinstance(result, dict):
        raise AssertionError("Non-object real-PHP scenario response")
    return result
def pure_source(filename: str, forbidden: tuple[str, ...]) -> bool:
    content = (ROOT / "src" / filename).read_text(encoding="utf-8").lower()
    return all(piece not in content for piece in forbidden)
class CoberturaControlbot44Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: 18 individual predicates backed by real PHP calls."""
        for identifier, doc in DOCUMENTS.items():
            with self.subTest(document=identifier):
                self.assertTrue((ROOT / doc).is_file())
        tbd = php("lifecycle", "tbd")
        delta = php("lifecycle", "delta")
        global_event = php("lifecycle", "global")["event"]
        life_pure = php("lifecycle", "pure")
        fresh = php("work", "fresh")
        closed = php("work", "closed")
        governance = php("work", "governance")["work_items"][0]
        deterministic = php("work", "deterministic")
        e2e = php("work", "e2e")
        context = php("work", "context_evidence")
        campaign = php("campaign", "scope")
        neutral = php("campaign", "neutral")["campaign"]
        privacy = php("campaign", "privacy")
        campaign_pure = php("campaign", "pure")
        creative = php("creative", "scope")
        variants = php("creative", "neutral")
        creative_life = php("creative", "lifecycle")
        email = php("email", "scope")
        audiences = php("email", "eligibility")
        audit = php("email", "audit")
        email_pure = php("email", "pure")
        eligible = audiences["cases"]["eligible"]
        work = fresh["work_items"][0]
        refs = context["valid"]["work_items"][0]["evidence_refs"]
        variant = creative["variant"]
        brief = creative["brief"]
        full = variants["full"]
        behaviors = {
            "CB-0718": (
                tbd["first"] == tbd["second"]
                and tbd["first"]["before"] == {"state": "tbd", "fingerprint": None}
                and tbd["first"]["after"]["state"] == "defined"
                and tbd["first"]["venture_id"] == "venture-condor"
            ),
            "CB-0719": (
                delta["reorder_noop"] is True
                and delta["change"]["delta"]["target_countries"]
                == {"added": ["PE"], "removed": ["MX"]}
                and delta["change"]["delta"]["launch_countries"]
                == {"added": ["PE"], "removed": ["CO"]}
            ),
            "CB-0720": (
                global_event["delta"]["target_countries"]
                == {"added": [], "removed": []}
                and global_event["delta"]["launch_countries"]
                == {"added": ["US"], "removed": []}
            ),
            "CB-0721": (
                life_pure["methods"] == ["change"]
                and life_pure["event"]["execution"] is False
                and pure_source("MarketScopeLifecycle.php", (
                    "curl_", "mysqli", "pdo(", "factoryrunner",
                    "scheduler", "workitem", "file_put_contents", "shell_exec",
                ))
            ),
            "CB-0722": (
                fresh["status"] == "materialized"
                and fresh["execution"] is False
                and len(fresh["work_items"]) == 1
                and REQUIRED_WORK.issubset(work)
                and set(work).issubset(REQUIRED_WORK | OPTIONAL_WORK)
                and "freshness" not in work
                and re.fullmatch(r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z",
                                 work["observed_at"]) is not None
                and all(closed[key]["status"] == "blocked"
                        and closed[key]["work_items"] == []
                        and closed[key]["unresolved_domains"]
                        for key in ("unknown", "stale"))
            ),
            "CB-0723": (
                governance["authority_level"] == "owner_required"
                and governance["policy_ref"] == "controlbot:policy/business-os-v1"
                and governance["approval_ref"] == "controlbot:approval/market-co"
                and governance["budget_ref"] == "controlbot:budget/market-co"
                and deterministic["first"] == deterministic["second"]
                and e2e["ready"]["status"] == "no_work"
                and e2e["ready"]["work_items"] == []
                and len({item["idempotency_key"] for item in
                         deterministic["first"]["work_items"]}) == 2
            ),
            "CB-0724": (
                {"controlbot:market-evidence/" + "a" * 32,
                 "controlbot:market-evidence/" + "b" * 32}.issubset(refs)
                and refs == sorted(set(refs))
                and all(WORK_EVIDENCE.fullmatch(ref) for ref in refs)
                and all(context[key] is True for key in (
                    "phone", "email", "free_text", "secret",
                    "malformed", "duplicate"))
            ),
            "CB-0726": (
                campaign["cross_venture"] is True
                and campaign["campaign"]["venture_id"]
                    == campaign["brand"]["venture_id"]
                and campaign["campaign"]["brand_context_id"]
                    == campaign["brand"]["brand_context_id"]
                and bool(OPAQUE.fullmatch(neutral["audience_ref"]))
            ),
            "CB-0727": (
                all(value is True for value in privacy.values())
                and all(OPAQUE.fullmatch(neutral[key]) for key in (
                    "audience_ref", "offer_ref", "cta_ref", "budget_ref"))
                and all(OPAQUE.fullmatch(ref) for key in (
                    "creative_variant_refs", "experiment_refs", "evidence_refs")
                    for ref in neutral[key])
            ),
            "CB-0728": (
                neutral["execution"] is False
                and campaign_pure["campaign"]["execution"] is False
                and set(campaign_pure["methods"]) == {"brandContext", "campaign"}
                and pure_source("MomentumCampaign.php", (
                    "curl_", "factoryrunner", "scheduler", "publishcampaign",
                    "spend(", "file_put_contents", "mysqli", "pdo(",
                ))
            ),
            "CB-0729": (
                creative["cross_venture"] is True
                and all(variant[key] == brief[key] for key in (
                    "venture_id", "brand_context_id", "format"))
                and variant["brief_id"] == brief["brief_id"]
                and full["status"] == "draft"
                and variants["content_only"]["asset_ref"] is None
                and variants["content_only"]["hypothesis_ref"] is None
                and bool(variants["content_only"]["content_ref"])
                and variants["asset_only"]["content_ref"] is None
                and bool(variants["asset_only"]["asset_ref"])
                and creative_life["approved"]["status"] == "approved"
                and creative_life["missing_rejected"] is True
                and creative_life["archived"]["status"] == "archived"
            ),
            "CB-0730": (
                set(full) == {
                    "version", "variant_id", "brief_id", "venture_id",
                    "brand_context_id", "variant_key", "format", "content_ref",
                    "asset_ref", "hypothesis_ref", "metric_ref",
                    "provenance_refs", "status",
                }
                and not {"text", "body", "asset_bytes", "provider", "prompt",
                         "execution", "scheduler", "work_item"}.intersection(full)
                and pure_source("MomentumCreative.php", (
                    "curl_", "factoryrunner", "scheduler", "publish",
                    "spend(", "file_put_contents", "mysqli", "pdo(",
                ))
            ),
            "CB-0731": (
                email["cross_venture"] is True
                and email["channel_mismatch"] is True
                and email["program"]["venture_id"] == "venture-condor"
                and email["program"]["message_class"] == "marketing"
                and email["program"]["execution"] is False
                and OPAQUE.fullmatch(email["program"]["campaign_ref"]) is not None
                and OPAQUE.fullmatch(email["program"]["audience_ref"]) is not None
                and audiences["transactional"]["marketing_eligible"] is False
            ),
            "CB-0732": (
                eligible["consent"]["status"] == "granted"
                and audiences["cases"]["denied"]["consent"]["status"] == "denied"
                and audit["consent"]["status"] == "unknown"
            ),
            "CB-0733": (
                eligible["suppression"]["status"] == "clear"
                and audiences["cases"]["suppressed"]["suppression"]["status"] == "active"
                and audit["suppression"]["status"] == "unknown"
            ),
            "CB-0734": (
                eligible["unsubscribe"]["status"] == "clear"
                and audiences["cases"]["unsubscribed"]["unsubscribe"]["status"] == "active"
                and eligible["unsubscribe"]["state_ref"] != eligible["suppression"]["state_ref"]
            ),
            "CB-0735": (
                len({eligible[key]["state_ref"] for key in (
                    "consent", "suppression", "unsubscribe")}) == 3
                and all(set(eligible[key]) == {
                    "state_ref", "status", "source_ref", "observed_at", "freshness",
                } for key in ("consent", "suppression", "unsubscribe"))
                and all(eligible[key]["freshness"] == "current"
                        and isinstance(eligible[key]["observed_at"], int)
                        and OPAQUE.fullmatch(eligible[key]["source_ref"])
                        for key in ("consent", "suppression", "unsubscribe"))
                and audit["suppression"]["freshness"] == "unknown"
            ),
            "CB-0736": (
                eligible["marketing_eligible"] is True
                and all(audiences["cases"][key]["marketing_eligible"] is False
                        for key in ("denied", "suppressed", "unsubscribed", "stale"))
                and audit["marketing_eligible"] is False
                and audiences["transactional"]["marketing_eligible"] is False
                and audiences["transactional"]["execution"] is False
                and email_pure["state"]["execution"] is False
            ),
        }
        self.assertEqual(set(behaviors), set(DOCUMENTS))
        for identifier, valid in behaviors.items():
            with self.subTest(contract=identifier):
                self.assertIs(valid, True, f"{identifier}: real PHP behavior not proved")

    def test_falla_cerrado(self) -> None:
        """AC-02: real product rejects no-ops, scope, authority, PII and unknown."""
        lifecycle = php("lifecycle", "minimal")
        self.assertTrue(lifecycle["secret_actor_rejected"])
        self.assertTrue(lifecycle["cross_venture_format_rejected"])
        self.assertTrue(php("lifecycle", "delta")["reorder_noop"])

        work = php("work", "closed")
        for key in ("unknown", "stale"):
            self.assertEqual(work[key]["work_items"], [])
            self.assertFalse(work[key]["execution"])
        for key in ("scope", "profile", "extra"):
            self.assertIs(work[key], True)
        context = php("work", "context_evidence")
        for key in ("phone", "email", "free_text", "secret", "malformed", "duplicate"):
            self.assertIs(context[key], True)
        limits = php("work", "context_evidence_limit")
        self.assertIs(limits["fifty_one"], True)
        self.assertEqual(limits["fifty"]["status"], "blocked")

        privacy = php("campaign", "privacy")
        self.assertTrue(all(privacy.values()))
        invalid_campaign = php("campaign", "closed")
        for key in ("duplicate_rejected", "extra_rejected", "schedule_rejected",
                    "active_without_start_rejected",
                    "completed_without_start_rejected"):
            self.assertIs(invalid_campaign[key], True)
        self.assertIs(php("creative", "scope")["cross_venture"], True)
        self.assertIs(php("creative", "lifecycle")["missing_rejected"], True)
        variants = php("creative", "set")
        self.assertIs(variants["duplicate_rejected"], True)
        self.assertEqual(variants["empty"], [])
        self.assertIs(php("email", "scope")["channel_mismatch"], True)
        self.assertTrue(all(php("email", "invalid").values()))
        for key in ("denied", "suppressed", "unsubscribed", "stale"):
            self.assertIs(php("email", "eligibility")["cases"][key]["marketing_eligible"],
                          False)

    def test_sin_datos_reales(self) -> None:
        """AC-03: no external network, customer data or operational permission."""
        for domain, case in (
            ("lifecycle", "pure"), ("work", "fresh"),
            ("campaign", "pure"), ("creative", "neutral"),
            ("email", "pure"),
        ):
            with self.subTest(domain=domain):
                payload = json.dumps(php(domain, case), sort_keys=True).lower()
                for forbidden in (
                    '"password"', '"api_key"', '"access_token"', '"credentials"',
                    '"private_key"', "bearer ", "https://", "@",
                ):
                    self.assertNotIn(forbidden, payload)
        for filename in ("MarketScopeLifecycle.php", "MarketWorkOrigin.php",
                         "MomentumCampaign.php", "MomentumCreative.php",
                         "MomentumEmail.php"):
            with self.subTest(implementation=filename):
                self.assertTrue(pure_source(filename, (
                    "curl_", "mysqli", "pdo(", "factoryrunner", "shell_exec",
                    "file_put_contents", "scheduler",
                )))
        for name in SCRIPTS.values():
            self.assertTrue((ROOT / "tests" / name).is_file())
        self.assertFalse(php("work", "fresh")["execution"])
        self.assertFalse(php("email", "pure")["state"]["execution"])

    def test_ci_gate_contract(self) -> None:
        """AC-04: inspect real branch-gate wiring, not invented CI status."""
        workflow = (ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        self.assertIn("name: Validar", workflow)
        self.assertIn("name: Pruebas de contrato e integración", workflow)
        self.assertIn("python3 scripts/generate_coverage.py", workflow)
        self.assertIn("needs: [factory, contrato]", workflow)


if __name__ == "__main__":
    unittest.main()
