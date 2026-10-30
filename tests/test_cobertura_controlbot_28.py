"""ControlBot #1211 — evidence by calling real offline GitHub projection code.

No network, credentials, owner sessions, live data, or product mutations.
C clauses are documentary/owner authority and must never be marked CUBIERTO
from a fake harness; reconciliation requires independent evidence.
"""
from __future__ import annotations

import json
from pathlib import Path
import tempfile
import unittest

from tests.test_github_public_entrypoint import scenario as public_scenario
from tests.test_github_readonly_https_transport import (
    run as https_run, request as https_request, page as https_page, reply as https_reply,
)
from tests.test_github_readonly_installation_source import (
    execute as installation_run, fixture as installation_fixture,
    request as installation_request, page as installation_page, seal,
)
from tests.test_github_readonly_projection_pipeline import run as pipeline_run
from tests.test_github_readonly_projection_writer import canonical_snapshot

ROOT = Path(__file__).resolve().parents[1]
IDS = (
    "CB-0453", "CB-0452", "CB-0455", "CB-0456", "CB-0457",
    "CB-0458", "CB-0454", "CB-0460", "CB-0461", "CB-0462",
    "CB-0463", "CB-0464", "CB-0465", "CB-0466", "CB-0467",
    "CB-0459", "CB-0469", "CB-0470", "CB-0471", "CB-0468",
)
NON_OFFLINE = {
    "CB-0454": ("C", "Autorización #929 y journal del dueño son evidencia de gobernanza, no un comportamiento del fake."),
    "CB-0459": ("C", "Voto #919 y procedencia de #55 son decisiones de autoridad, no una función PHP."),
    "CB-0466": ("C", "Exigir CI/CodeRabbit exact-HEAD es una regla de entrega externa; php -r es solo técnica de prueba."),
    "CB-0467": ("C", "No autoriza registrar una instalación real o activar runtime, y la autorización futura depende del dueño."),
}
OFFLINE = frozenset(IDS) - NON_OFFLINE.keys()
SAFE = {"runtime_authorized": False, "production_verified": False,
        "execute_actions": False, "authority": "unchanged"}


class CoberturaControlbot28Tests(unittest.TestCase):
    def assert_no_authority(self, result: dict) -> None:
        self.assertEqual({key: result.get(key) for key in SAFE}, SAFE)
        self.assertNotIn("execution_grant", result)

    def test_contrato_principal(self) -> None:
        demonstrated: set[str] = set()

        # CB-0452: actual owner-gated GET /github route reads local projection.
        public = public_scenario("owner")
        self.assertEqual(public["owner"]["status"], 200)
        self.assertEqual(public["wrong_owner"]["status"], 403)
        self.assertEqual(public["wrong_method"]["status"], 405)
        demonstrated.add("CB-0452")

        # CB-0453: the real view accepts v1 typed synthetic projection,
        # shows its project, and never promotes an absent projection to current.
        self.assertIn("factory-control", public["owner"]["body"])
        self.assertIn("state=current", public["owner"]["body"])
        absent = public_scenario("unknown")
        self.assertIn("state=unknown", absent["missing"]["body"])
        demonstrated.add("CB-0453")

        off = https_run()
        on = https_run(enabled=True, replies=[https_page(1, 2),
                            https_page(2, items=[{"id": 8, "state": "closed"}])])
        self.assertEqual((off["out"]["status"], off["calls"]), ("UNKNOWN", []))
        self.assertEqual(on["out"]["status"], "CURRENT")
        demonstrated.add("CB-0455")

        # CB-0456: real transport invokes ONLY the allowlisted injected GET.
        calls = on["calls"]
        self.assertEqual(len(calls), 2)
        self.assertEqual([c["method"] for c in calls], ["GET", "GET"])
        self.assertEqual([c["path"] for c in calls],
                         ["/repos/pl0n3r/ControlBot/issues"] * 2)
        self.assertEqual([c["mode"] for c in calls],
                         ["offline_test_only"] * 2)
        for c in calls:
            self.assertEqual(c["host"], "api.github.com")
            self.assertEqual(c["scheme"], "https")
            self.assertIs(c["tls_verify_peer"], True)
            self.assertIs(c["follow_redirects"], False)
            self.assertEqual((c["connect_timeout_seconds"], c["timeout_seconds"]), (2, 5))
        self.assertEqual(on["out"]["projection"]["items_count"], 2)
        demonstrated.add("CB-0456")

        # CB-0457: forbidden request and fake 403/429 fail closed.
        bad = https_run(enabled=True, req={**https_request(), "method": "PATCH"})
        self.assertEqual((bad["out"]["status"], bad["calls"]), ("UNKNOWN", []))
        for status in (403, 429):
            with self.subTest(status=status):
                denied = https_run(enabled=True, replies=[https_reply("{}", status=status)])
                self.assertEqual(denied["out"]["status"], "UNKNOWN")
                self.assertIsNone(denied["out"]["projection"])
        demonstrated.add("CB-0457")

        self.assert_no_authority(on["out"])
        self.assert_no_authority(off["out"])
        demonstrated.add("CB-0458")

        source = installation_run()
        self.assert_no_authority(source["result"])
        self.assertEqual(source["result"]["status"], "CURRENT")
        self.assertEqual(source["result"]["reason"], "fixture_only")
        demonstrated.add("CB-0460")

        fixture = installation_fixture()
        self.assertEqual((fixture["origin"], fixture["test_only"],
                          fixture["owner_verified"]),
                         ("server_fixture", True, True))
        self.assertEqual(fixture["scopes"], ["contents:read", "metadata:read"])
        self.assertEqual(source["result"]["fixture_projection"]["sha"], fixture["sha"])
        demonstrated.add("CB-0461")

        pulls = installation_run(r={**installation_request(), "resource": "pulls",
                                     "page_size": 1})
        self.assertEqual(pulls["result"]["status"], "CURRENT")
        self.assertEqual(pulls["result"]["fixture_projection"]["resource"], "pulls")
        self.assertEqual(pulls["calls"][0]["per_page"], 1)
        demonstrated.add("CB-0462")

        self.assertEqual(source["calls"][0], {
            "method": "GET", "route": "/repos/pl0n3r/ControlBot/issues",
            "page": 1, "per_page": 2, "mode": "offline_test_only",
        })
        demonstrated.add("CB-0463")

        multi = installation_run(responses=[
            installation_page(1, next_page=2),
            installation_page(2, items=[{"id": 2, "state": "closed"}]),
        ])
        self.assertEqual(multi["result"]["status"], "CURRENT")
        self.assertEqual(multi["result"]["fixture_projection"]["pages"], 2)
        self.assertEqual(multi["result"]["fixture_projection"]["items_count"], 2)
        self.assertEqual(len(multi["calls"]), 2)
        demonstrated.add("CB-0464")

        self.assertEqual(multi["audit"], [
            {"status": "CURRENT", "reason": "fixture_only", "pages": 2}
        ])
        self.assertNotIn("pl0n3r", json.dumps(multi["audit"]))
        self.assertNotIn("repository", json.dumps(multi["audit"]))
        demonstrated.add("CB-0465")

        # Pipeline: actual PHP composition with synthetic fixture/witness, and
        # real atomic file writer; no HTTP transport or live installation.
        with tempfile.TemporaryDirectory(prefix="cb-projection-fixture-") as temp:
            disabled = pipeline_run(temp)
            self.assertEqual(disabled["result"]["status"], "UNKNOWN")
            self.assertEqual((disabled["source_calls"], disabled["http_calls"],
                              disabled["witness_calls"]), (0, 0, 0))
            enabled = pipeline_run(temp, enabled=True)
            self.assertEqual(enabled["result"]["status"], "CURRENT")
            self.assertEqual((enabled["source_calls"], enabled["http_calls"],
                              enabled["witness_calls"]), (1, 1, 2))
            demonstrated.add("CB-0468")

            self.assertEqual(enabled["result"]["reason"], "synthetic_fixture_only")
            self.assertEqual((enabled["result"]["project_count"],
                              enabled["result"]["repository_count"]), (1, 1))
            demonstrated.add("CB-0469")

            before = enabled["file"]
            self.assertEqual(json.loads(before), {
                "version": 1, "projects": [canonical_snapshot(observed=1000)]
            })
            failure = pipeline_run(temp, enabled=True, source_mode="forbidden")
            self.assertEqual(failure["result"]["status"], "UNKNOWN")
            self.assertEqual(failure["file"], before)
            demonstrated.add("CB-0470")

            self.assert_no_authority(enabled["result"])
            self.assert_no_authority(failure["result"])
            demonstrated.add("CB-0471")

        self.assertEqual(demonstrated, OFFLINE)
        self.assertEqual(set(IDS), demonstrated | set(NON_OFFLINE))
        self.assertTrue(all(kind in ("B", "C", "D") and reason
                            for kind, reason in NON_OFFLINE.values()))

    def test_falla_cerrado(self) -> None:
        # Owner gate and bad routing must deny before reading an external source.
        guards = public_scenario("guards")
        self.assertEqual(guards["bad_path"]["status"], 404)
        self.assertEqual(guards["bad_owner_config"]["status"], 503)

        for patch in ({"host": "private.invalid"}, {"resource": "hooks"},
                      {"page_size": 200}, {"now": 1200}, {"method": "POST"}):
            with self.subTest(patch=patch):
                output = https_run(enabled=True, req={**https_request(), **patch})
                self.assertEqual(output["out"]["status"], "UNKNOWN")
                self.assertIsNone(output["out"]["projection"])
                self.assertEqual(output["calls"], [])
                self.assert_no_authority(output["out"])
        timeout = https_run(enabled=True, mode="timeout")
        self.assertEqual(timeout["out"]["status"], "UNKNOWN")
        self.assertNotIn("private-token", json.dumps(timeout))

        for patch in ({"owner_verified": False}, {"origin": "browser"},
                      {"scopes": ["contents:write", "metadata:read"]},
                      {"expires_at": 1050}):
            with self.subTest(source_patch=patch):
                f = seal({**installation_fixture(), **patch})
                source = installation_run(f=f)
                self.assertEqual(source["result"]["status"], "UNKNOWN")
                self.assertIsNone(source["result"]["fixture_projection"])
                self.assertEqual(source["calls"], [])
                self.assert_no_authority(source["result"])
        for status in (403, 429):
            denied = installation_run(responses=[
                {**installation_page(), "status": status}
            ])
            self.assertEqual(denied["result"]["status"], "UNKNOWN")

        with tempfile.TemporaryDirectory(prefix="cb-projection-fixture-") as temp:
            accepted = pipeline_run(temp, enabled=True)
            original = accepted["file"]
            for patch in ({"http_mode": "limited"}, {"race": True},
                          {"source_mode": "timeout"}, {"omit_witness": True}):
                with self.subTest(pipeline=patch):
                    denied = pipeline_run(temp, enabled=True, **patch)
                    self.assertEqual(denied["result"]["status"], "UNKNOWN")
                    self.assertEqual(denied["file"], original)
                    self.assert_no_authority(denied["result"])
    def test_sin_datos_reales(self) -> None:
        # Source and transport never execute network: only injected PHP Closures.
        source = installation_run()
        transport = https_run(enabled=True)
        self.assert_no_authority(source["result"])
        self.assert_no_authority(transport["out"])
        for name, audit in (("installation", source["audit"]),
                            ("transport", transport["audit"])):
            with self.subTest(name=name):
                serialized = json.dumps(audit).lower()
                for forbidden in ("token", "secret", "email", "http://",
                                  "https://", "password", "pl0n3r"):
                    self.assertNotIn(forbidden, serialized)
        for path in ("src/GitHubReadOnlyInstallationSource.php",
                     "src/GitHubReadOnlyHttpsTransport.php",
                     "src/GitHubReadOnlyProjectionPipeline.php"):
            source_code = (ROOT / path).read_text(encoding="utf-8")
            for forbidden in ("curl_exec(", "stream_socket_client(",
                              "fsockopen(", "getenv("):
                self.assertNotIn(forbidden, source_code)
        with tempfile.TemporaryDirectory(prefix="cb-projection-fixture-") as temp:
            out = pipeline_run(temp)
            self.assertEqual(out["source_calls"], 0)
            self.assertEqual(out["http_calls"], 0)
            self.assert_no_authority(out["result"])

    def test_ci_contract(self) -> None:
        # Static wiring only; exact HEAD and terminal gates must be read in GitHub.
        workflow = (ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        self.assertIn("name: CI ControlBot", workflow)
        self.assertIn("name: Validar", workflow)
        self.assertIn("python3 scripts/generate_coverage.py", workflow)
        self.assertIn("ref: $" + "{{ github.event_name == 'pull_request' && github.event.pull_request.head.sha || github.sha }}", workflow)
        self.assertIn("phase: construccion", workflow)


if __name__ == "__main__":
    unittest.main()
