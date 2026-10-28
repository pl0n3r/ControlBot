"""CB-0455..CB-0469: contraste ejecutable de GitHub GET-only sintético.

Los adaptadores prueban contratos PHP mediante closures fakes: CURRENT solo
significa fixture fresco, NUNCA instalación real, producción ni permisos.
"""
from __future__ import annotations

import inspect
import json
from pathlib import Path
import tempfile
import unittest

from tests import test_github_readonly_https_transport as https
from tests import test_github_readonly_installation_source as installation
from tests import test_github_readonly_projection_pipeline as pipeline

ROOT = Path(__file__).resolve().parents[1]
IDS = tuple(f"CB-{number:04d}" for number in range(455, 470))


class BrechasControlbot31Tests(unittest.TestCase):
    def assert_sin_autoridad(self, result: dict) -> None:
        self.assertIs(result["runtime_authorized"], False)
        self.assertIs(result["production_verified"], False)
        self.assertIs(result["execute_actions"], False)
        self.assertEqual(result["authority"], "unchanged")

    def test_contrato_principal(self) -> None:
        """Una aserción positiva o frontera UNKNOWN por cada una de las 15 filas."""
        off = https.run()
        transport = https.run(enabled=True)
        pages = https.run(enabled=True, replies=[
            https.page(1, 2),
            https.page(2, items=[{"id": 2, "state": "closed"}]),
        ])
        forbidden = https.run(
            enabled=True, req={**https.request(), "method": "POST"})
        forbidden_reply = https.run(
            enabled=True, replies=[https.reply("{}", status=429)])
        source = installation.execute()
        signed = installation.fixture()
        untrusted = installation.execute(f=installation.seal({
            **signed, "origin": "browser",
        }))
        mismatch = installation.execute(r={
            **installation.request(), "sha": "b" * 40,
        })
        multi = installation.execute(responses=[
            installation.page(1, next_page=2),
            installation.page(2, next_page=3),
            installation.page(3),
        ])
        audit = source["audit"]
        transport_doc = (ROOT / "docs/github-readonly-https-transport.md").read_text()
        installation_doc = (
            ROOT / "docs/github-readonly-installation-source.md").read_text()
        source_php = (ROOT / "src/GitHubReadOnlyInstallationSource.php").read_text()

        with tempfile.TemporaryDirectory(prefix="cb-projection-fixture-") as root:
            disabled = pipeline.run(root)
            enabled = pipeline.run(root, enabled=True)
            written = json.loads(enabled["file"])
            snapshot = pipeline.canonical_snapshot(observed=1000)

        calls = pages["calls"]
        request = source["calls"][0]
        checks = {
            # CB-0455: opt-in explícito, sin ejecutar ningún fake por defecto.
            "CB-0455": (
                off["out"]["status"] == "UNKNOWN"
                and off["calls"] == []
                and transport["out"]["status"] == "CURRENT"
            ),
            # CB-0456: un solo envelope GET HTTPS/fixture por cada página.
            "CB-0456": (
                len(calls) == 2
                and all(c["method"] == "GET"
                        and c["mode"] == "offline_test_only"
                        and c["scheme"] == "https"
                        and c["host"] == "api.github.com"
                        and c["tls_verify_peer"] is True
                        and c["tls_verify_host"] == 2
                        and c["follow_redirects"] is False
                        and c["connect_timeout_seconds"] == 2
                        and c["timeout_seconds"] == 5
                        and c["max_response_bytes"] == 32768
                        for c in calls)
            ),
            # CB-0457: no aceptar mutaciones ni errores 429 como progreso.
            "CB-0457": (
                forbidden["out"]["status"] == "UNKNOWN"
                and forbidden["out"]["projection"] is None
                and forbidden["calls"] == []
                and forbidden_reply["out"]["status"] == "UNKNOWN"
                and forbidden_reply["out"]["projection"] is None
            ),
            # CB-0458: una proyección de fixture no delega autoridad.
            "CB-0458": (
                transport["out"]["runtime_authorized"] is False
                and transport["out"]["production_verified"] is False
                and transport["out"]["execute_actions"] is False
                and transport["out"]["authority"] == "unchanged"
            ),
            # CB-0459: decisión documentada, pero NO evidencia live.
            "CB-0459": (
                "solo para pruebas de integración sintéticas" in installation_doc
                and "D-043/D-059" in installation_doc
                and source["result"]["runtime_authorized"] is False
            ),
            # CB-0460: CURRENT se limita a fixture_only sin permisos.
            "CB-0460": (
                source["result"]["status"] == "CURRENT"
                and source["result"]["reason"] == "fixture_only"
                and source["result"]["production_verified"] is False
                and source["result"]["execute_actions"] is False
                and source["result"]["authority"] == "unchanged"
            ),
            # CB-0461: HMAC y origen de servidor, no formulario/browser.
            "CB-0461": (
                signed["origin"] == "server_fixture"
                and signed["test_only"] is True
                and signed["owner_verified"] is True
                and signed["scopes"] == ["contents:read", "metadata:read"]
                and len(signed["sha"]) == 40
                and untrusted["result"]["status"] == "UNKNOWN"
                and untrusted["calls"] == []
            ),
            # CB-0462: repo y SHA exactos, paginación pequeña, sin URL extra.
            "CB-0462": (
                source["result"]["fixture_projection"]["repository"]
                == installation.REPOSITORY
                and source["result"]["fixture_projection"]["sha"]
                == installation.SHA
                and mismatch["result"]["status"] == "UNKNOWN"
                and mismatch["calls"] == []
            ),
            # CB-0463: closure solo ve GET, ruta relativa y modo offline.
            "CB-0463": (
                set(request) == {"method", "route", "page", "per_page", "mode"}
                and request["method"] == "GET"
                and request["route"] == "/repos/pl0n3r/ControlBot/issues"
                and request["page"] == 1
                and request["per_page"] == 2
                and request["mode"] == "offline_test_only"
            ),
            # CB-0464: exactamente tres páginas completas, sin partial.
            "CB-0464": (
                multi["result"]["status"] == "CURRENT"
                and multi["result"]["fixture_projection"]["pages"] == 3
                and multi["result"]["fixture_projection"]["items_count"] == 3
                and len(multi["calls"]) == 3
            ),
            # CB-0465: audit in-memory reducido, sin URLs/payloads.
            "CB-0465": (
                audit == [{"status": "CURRENT", "reason": "fixture_only",
                           "pages": 1}]
                and all(set(e) == {"status", "reason", "pages"} for e in audit)
            ),
            # CB-0466: harness real Python -> php -r con fake inyectado.
            "CB-0466": (
                '["php", "-r", PHP, encoded]' in inspect.getsource(
                    installation.execute)
                and source["result"]["status"] == "CURRENT"
                and len(source["calls"]) == 1
            ),
            # CB-0467: ni fuente ni tests autorizan runtime real.
            "CB-0467": (
                "No existe integración al runtime" in installation_doc
                and source["result"]["runtime_authorized"] is False
                and "curl_exec(" not in source_php
                and "stream_socket_client(" not in source_php
            ),
            # CB-0468: tres componentes inyectados, OFF por defecto.
            "CB-0468": (
                disabled["result"]["status"] == "UNKNOWN"
                and disabled["source_calls"] == disabled["http_calls"] == 0
                and enabled["result"]["status"] == "CURRENT"
                and (enabled["source_calls"], enabled["http_calls"]) == (1, 1)
                and enabled["result"]["execute_actions"] is False
            ),
            # CB-0469: snapshot v1 y manifiesto exactos; sin confiar en conteos.
            "CB-0469": (
                written == {"version": 1, "projects": [snapshot]}
                and enabled["witness_calls"] == 2
                and enabled["result"]["production_verified"] is False
                and "runtime_authorized=false" in transport_doc
            ),
        }
        self.assertEqual(set(checks), set(IDS))
        for requirement, assertion in checks.items():
            with self.subTest(requirement=requirement):
                self.assertTrue(assertion, "brecha sin contraste: " + requirement)
        self.assert_sin_autoridad(source["result"])
        self.assert_sin_autoridad(transport["out"])
        self.assert_sin_autoridad(enabled["result"])

    def test_falla_cerrado(self) -> None:
        """TTL, rutas, HTTP, HMAC, página parcial y testigo nunca dan CURRENT."""
        bad_transport_requests = (
            {"method": "POST"}, {"host": "untrusted.invalid"},
            {"resource": "hooks"}, {"now": 1200},
            {"page_size": 21}, {"url": "https://untrusted.invalid"},
        )
        for update in bad_transport_requests:
            with self.subTest(adapter="https", update=update):
                output = https.run(enabled=True, req={
                    **https.request(), **update,
                })
                self.assertEqual(output["out"]["status"], "UNKNOWN")
                self.assertIsNone(output["out"]["projection"])
                self.assertEqual(output["calls"], [])
        for replies in (
            [https.reply("{}", status=403)],
            [https.reply("{}", status=429)],
            [https.reply("{not-json")],
            [https.page(1, 2)],
        ):
            with self.subTest(adapter="https_response", reply=replies[0]):
                output = https.run(enabled=True, replies=replies)
                self.assertEqual(output["out"]["status"], "UNKNOWN")
                self.assertIsNone(output["out"]["projection"])
        for request in (
            {**installation.request(), "sha": "b" * 40},
            {**installation.request(), "resource": "hooks"},
            {**installation.request(), "page_size": 26},
            {**installation.request(), "url": "/arbitrary"},
        ):
            with self.subTest(adapter="source", request=request):
                output = installation.execute(r=request)
                self.assertEqual(output["result"]["status"], "UNKNOWN")
                self.assertIsNone(output["result"]["fixture_projection"])
                self.assertEqual(output["calls"], [])
        for response in (
            {**installation.page(), "status": 429},
            {**installation.page(), "truncated": True},
            {**installation.page(), "repository": "wrong/repo"},
            {**installation.page(), "items": [
                {"id": 1, "state": "open", "email": "private@example.invalid"}]},
        ):
            with self.subTest(adapter="source_response", response=response):
                output = installation.execute(responses=[response])
                self.assertEqual(output["result"]["status"], "UNKNOWN")
                self.assertIsNone(output["result"]["fixture_projection"])
        self.assertEqual(installation.execute(now=1150)["result"]["status"],
                         "UNKNOWN")
        with tempfile.TemporaryDirectory(prefix="cb-projection-fixture-") as root:
            previous = pipeline.run(root, enabled=True)["file"]
            for changes in (
                {"source_mode": "forbidden"}, {"http_mode": "limited"},
                {"race": True}, {"omit_witness": True},
                {"expected": []}, {"now": 1150},
            ):
                with self.subTest(adapter="pipeline", changes=changes):
                    output = pipeline.run(root, enabled=True, **changes)
                    self.assertEqual(output["result"]["status"], "UNKNOWN")
                    self.assertEqual(output["file"], previous)

    def test_sin_datos_reales(self) -> None:
        """No redes, tokens, PII ni scopes mutantes dentro del harness."""
        sources = (
            ROOT / "src/GitHubReadOnlyHttpsTransport.php",
            ROOT / "src/GitHubReadOnlyInstallationSource.php",
            ROOT / "src/GitHubReadOnlyProjectionPipeline.php",
        )
        for path in sources:
            with self.subTest(path=path.name):
                content = path.read_text(encoding="utf-8")
                for banned in (
                    "curl_exec(", "stream_socket_client(", "fsockopen(",
                    "new GuzzleHttp", "getenv(",
                ):
                    self.assertNotIn(banned, content)
        http = https.run(enabled=True, mode="timeout")
        source = installation.execute(fake_mode="timeout")
        for payload in (http, source):
            text = json.dumps(payload)
            self.assertNotIn("private-token-owner@", text)
            self.assertNotIn("private@example.com", text)
            for item in payload.get("audit", []):
                self.assertEqual(
                    set(item), {"status", "reason"}
                    if payload is http else {"status", "reason", "pages"})
        self.assert_sin_autoridad(http["out"])
        self.assert_sin_autoridad(source["result"])

    def test_estados_unknown_explicitos(self) -> None:
        """Matriz permanece UNKNOWN hasta contraste post-merge y gates live."""
        rows = []
        for doc in (
            "docs/matriz-cobertura-parte-02.md",
            "docs/matriz-cobertura.md",
        ):
            rows.extend((ROOT / doc).read_text(encoding="utf-8").splitlines())
        for requirement in IDS:
            with self.subTest(requirement=requirement):
                found = [line for line in rows
                         if line.startswith("| " + requirement + " |")]
                self.assertEqual(len(found), 1)
                row = found[0].lower()
                self.assertIn("| no_verificable |", row)
                self.assertIn("offline=unknown", row)
                self.assertIn("live=unknown", row)
                self.assertIn("operando=unknown", row)
        self.assertEqual(https.run()["out"]["status"], "UNKNOWN")
        self.assertEqual(
            installation.execute(r={
                **installation.request(), "repository": "other/repo",
            })["result"]["status"],
            "UNKNOWN",
        )


if __name__ == "__main__":
    unittest.main()
