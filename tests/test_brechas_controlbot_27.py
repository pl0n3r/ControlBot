"""CB-0395..CB-0409: prueba ejecutable de intents GitHub sin autoridad real.

Usa únicamente harness Python/PHP preexistentes y datos sintéticos.
CURRENT o una firma correcta jamás prueban autorización ni go-live.
"""
from __future__ import annotations

from test_matriz_cobertura import credible_offline_coverage_row

import json
from pathlib import Path
import unittest

from tests import test_github_intent_offline_issuer as issuer
from tests import test_github_intent_policy as policy
from tests import test_github_intent_preview as preview

ROOT = Path(__file__).resolve().parents[1]
IDS = tuple(f"CB-{number:04d}" for number in range(395, 410))


class BrechasControlbot27Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """Quince requisitos, quince aserciones con resultado observado."""
        signed = issuer.execute(issuer.candidate("pr.merge"))
        issued = signed["issuer"]
        verified = signed["verification"]
        reuse = policy.scenario("reuse")
        binding = policy.scenario("binding")
        freshness = policy.scenario("freshness")
        invalid = policy.scenario("invalid_context")
        trusted = policy.scenario("trusted_valid")
        trusted_closed = policy.scenario("trusted_closed")
        public = preview.scenario("preview")
        safe = preview.scenario("safe")
        policy_php = (ROOT / "src/GitHubIntentPolicy.php").read_text(encoding="utf-8")
        preview_php = (ROOT / "src/GitHubIntentPreview.php").read_text(encoding="utf-8")
        catalog = (ROOT / "src/CapabilityPolicy.php").read_text(encoding="utf-8")
        issuer_doc = (ROOT / "docs/github-intent-offline-issuer.md").read_text(encoding="utf-8")
        policy_doc = (ROOT / "docs/github-intent-policy-v1.md").read_text(encoding="utf-8")
        preview_doc = (ROOT / "docs/github-intent-preview-v1.md").read_text(encoding="utf-8")

        checks = {
            # CB-0395: HMAC válido no produce permisos ni aprobaciones.
            "CB-0395": (
                issued["reason"] == verified["reason"] == "offline_only"
                and verified["proof_valid"] is True
                and verified["decision"] == issued["decision"] == "deny"
                and all(issued[k] is False for k in (
                    "authorized", "grant_issued", "approval_issued",
                    "approval_verified", "execution"))
                and verified["execution"] is False
            ),
            # CB-0396: contrato reproducible con PHP real, sin autoridad.
            "CB-0396": (
                len(unittest.defaultTestLoader.getTestCaseNames(
                    issuer.GitHubIntentOfflineIssuerTests)) == 4
                and "python3 -m unittest -v tests.test_github_intent_offline_issuer"
                in issuer_doc and issued["execution"] is False
                and '["php", "--", payload]' in __import__("inspect").getsource(
                    issuer.execute)
            ),
            # CB-0397: policy offline usa envelope tipado, no GitHub real.
            "CB-0397": (
                reuse["missing"]["decision"] == "deny"
                and reuse["missing"]["execution"] is False
                and "GitHubIntentEnvelope::envelope(" in policy_php
                and "curl_init(" not in policy_php
                and "execution=false" in policy_doc
            ),
            # CB-0398: capability viene de binding cerrado de la policy.
            "CB-0398": (
                "'pr.merge'=>'github.pr.merge'" in policy_php
                and "'issue.create'=>'github.issue.write'" in policy_php
                and "context['capability']" not in policy_php
                and binding["hostinger"]["decision"] == "deny"
            ),
            # CB-0399: grants de otro dominio nunca son grants GitHub.
            "CB-0399": (
                "'github.pr.merge' =>" not in catalog
                and "'github.issue.write' =>" not in catalog
                and all(x["decision"] == "deny" and x["execution"] is False
                        for x in binding.values())
            ),
            # CB-0400: la referencia cruda debe estar enlazada, pero
            # timestamp/TTL del caller no acreditan proveniencia.
            "CB-0400": (
                freshness["unbound_ref"]["reasons"] == ["evidence_mismatch"]
                and freshness["raw_fresh"]["reasons"]
                == ["untrusted_evidence_freshness"]
                and freshness["raw_fresh"]["execution"] is False
            ),
            # CB-0401: conjunto cerrado de decisiones; nunca allow con
            # freshness cruda, datos de tipos erróneos -> unknown/deny.
            "CB-0401": (
                invalid["execution"]["decision"] == "unknown"
                and invalid["extra_context"]["decision"] == "deny"
                and all(x["decision"] in {
                    "deny", "unknown", "owner_decision_required", "allow"}
                    and x["decision"] != "allow"
                    and x["execution"] is False
                    for x in [*reuse.values(), *invalid.values()])
            ),
            # CB-0402: sin registro de capabilities, HTTP, adapters ni tokens.
            "CB-0402": (
                "GitHubIntentTrustedEvidence::derive(" in policy_php
                and "CapabilityPolicy::classify(" in policy_php
                and "'github.release.approve' =>" not in catalog
                and all(x not in policy_php for x in (
                    "curl_init(", "shell_exec(", "CapabilityGrant::issue("))
            ),
            # CB-0403: V2 solo puede consumir contexto y snapshot confiados
            # por backend. Rechaza inyecciones; structural != autenticado.
            "CB-0403": (
                trusted["current"]["decision"] == "deny"
                and trusted["no_caller_ttl"]["reasons"]
                == ["requirements_invalid"]
                and trusted["no_caller_timestamp"]["decision"] == "unknown"
                and "serverContext" in policy_php
                and trusted["current"]["execution"] is False
            ),
            # CB-0404: la firma real de describe consume raw sin execution.
            "CB-0404": (
                "unset($raw['execution'])" in policy_php
                and "GitHubIntentAuthorityRequirements::describe(" in policy_php
                and trusted["current"]["reasons"] == ["grant_required"]
                and trusted_closed["mismatched_intent"]["reasons"]
                == ["requirements_invalid"]
            ),
            # CB-0405: aun snapshot actual, catálogo github.* prohibido.
            "CB-0405": (
                len(trusted_closed) == 10
                and all(x["decision"] in {"deny", "unknown"}
                        and x["execution"] is False
                        for x in trusted_closed.values())
                and trusted_closed["foreign_grant"]["reasons"]
                == ["grant_required"]
                and trusted_closed["stale"]["reasons"]
                == ["untrusted_or_stale_snapshot"]
            ),
            # CB-0406: preview solo presenta allowlist, nunca acción.
            "CB-0406": (
                set(public["live"]["intent"])
                == {"intent_id", "project_ref", "repository_ref",
                    "type", "params"}
                and public["live"]["mutation_controls"] == []
                and public["live"]["execution"] is False
                and "GitHubIntentEnvelope::envelope(" in preview_php
                and "read-only" in preview_doc
            ),
            # CB-0407: freshness cruda se proyecta como deny sin escalar.
            "CB-0407": (
                public["live"]["policy"]["decision"] == "deny"
                and public["live"]["policy"]["reasons"]
                == ["untrusted_evidence_freshness"]
                and public["live"]["approval"]["state"] == "unavailable"
                and public["live"]["mutation_controls"] == []
            ),
            # CB-0408: owner_decision_required es fixture de presentación.
            "CB-0408": (
                public["owner"]["policy"]["decision"]
                == "owner_decision_required"
                and public["owner"]["approval"]
                == {"required": True, "state": "required"}
                and public["owner"]["mutation_controls"] == []
                and public["owner"]["execution"] is False
                and public["live"]["policy"]["decision"] == "deny"
            ),
            # CB-0409: contexto sensible/decision/reason inválido -> minimal.
            "CB-0409": (
                safe["sensitive"]["policy"]["decision"] == "unknown"
                and safe["sensitive"]["intent"] is None
                and safe["sensitive"]["evidence_refs"] == []
                and safe["bad_policy"]["intent"] is None
                and safe["bad_policy"]["mutation_controls"] == []
                and safe["bad_policy"]["execution"] is False
            ),
        }
        self.assertEqual(set(checks), set(IDS))
        for requirement, observation in checks.items():
            with self.subTest(requirement=requirement):
                self.assertTrue(observation, "sin contraste observable: " + requirement)

    def test_falla_cerrado(self) -> None:
        """Datos del caller, HMAC inválida, permisos ajenos y PII no escalan."""
        for section, key, value in (
            ("approval", "approved", False),
            ("approval", "origin", "client"),
            ("approval", "owner_verified", False),
            ("trusted", "mode", "production"),
            ("trusted", "nonce", "bad"),
            ("requirements", "capability", "github.admin"),
        ):
            with self.subTest(section=section, field=key):
                case = issuer.candidate()
                case[section][key] = value
                result = issuer.execute(case)["issuer"]
                self.assertEqual(result["decision"], "deny")
                self.assertIsNone(result["proof"])
                self.assertIs(result["execution"], False)
        bad_key = issuer.candidate()
        bad_key["key"] = "01" * 12
        self.assertIsNone(issuer.execute(bad_key)["issuer"]["proof"])

        for category in ("freshness", "trusted_closed"):
            for key, row in policy.scenario(category).items():
                with self.subTest(category=category, key=key):
                    self.assertIn(row["decision"], ("deny", "unknown"))
                    self.assertIs(row["execution"], False)
        for key, row in preview.scenario("safe").items():
            with self.subTest(preview=key):
                self.assertEqual(row["mutation_controls"], [])
                self.assertIs(row["execution"], False)
        self.assertIsNone(preview.scenario("safe")["sensitive"]["intent"])

    def test_sin_datos_reales(self) -> None:
        """No solicita tokens, red, acceso a producción ni datos del usuario."""
        for path in (
            "src/GitHubIntentOfflineIssuer.php",
            "src/GitHubIntentPolicy.php",
            "src/GitHubIntentPreview.php",
        ):
            with self.subTest(path=path):
                source = (ROOT / path).read_text(encoding="utf-8")
                for forbidden in ("curl_init(", "shell_exec(", "stream_socket_client(",
                                  "CapabilityGrant::issue(", "github_pat_"):
                    self.assertNotIn(forbidden, source)
        self.assertEqual(issuer.FIXTURE_KEY, bytes(range(32, 64)))
        output = {
            "signed": issuer.execute(issuer.candidate()),
            "policy": policy.scenario("trusted_closed"),
            "preview": preview.scenario("safe"),
        }
        dumped = json.dumps(output).lower()
        for forbidden in ("private@example.com", "actual_access_token",
                          "password=real", "github_pat_"):
            self.assertNotIn(forbidden, dumped)
        self.assertIs(output["signed"]["issuer"]["execution"], False)
        self.assertTrue(all(
            x["execution"] is False for x in output["policy"].values()))
        self.assertTrue(all(
            x["execution"] is False for x in output["preview"].values()))

    def test_estados_unknown_explicitos(self) -> None:
        """La matriz no se reconcilia hasta PR MERGED + gates reales."""
        lines = []
        for path in ("docs/matriz-cobertura.md",
                     "docs/matriz-cobertura-parte-02.md"):
            lines.extend((ROOT / path).read_text(encoding="utf-8").splitlines())
        for requirement in IDS:
            with self.subTest(requirement=requirement):
                matches = [line for line in lines
                           if line.startswith("| " + requirement + " |")]
                self.assertEqual(len(matches), 1)
                lower = matches[0].lower()
                cells = tuple(x.strip() for x in matches[0].strip("|").split("|"))
                self.assertTrue(credible_offline_coverage_row(cells))
                for plane in ("live=unknown", "operando=unknown"):
                    self.assertIn(plane, lower)
                if cells[3] == "NO_VERIFICABLE":
                    self.assertIn("offline=unknown", lower)
        self.assertEqual(
            policy.scenario("invalid_context")["execution"]["decision"],
            "unknown")
        self.assertEqual(
            preview.scenario("safe")["bad_policy"]["policy"]["decision"],
            "unknown")


if __name__ == "__main__":
    unittest.main()
