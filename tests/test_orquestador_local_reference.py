import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TOOL = ROOT / "tools" / "orquestador-local"
EXPECTED = (
    "orq.py", "index.html", "grafo_orq.py", "grafo.html", "vigia.sh",
    "CONTRATO-EXTENSION.md", "config.example.json", "README.md", ".gitignore",
)
STATE_FILES = (
    "acciones.log", "af_logs.jsonl", "agent_key.txt", "assigned.json",
    "agent_blocked.json", "planner_state.json", "config.json", "__pycache__/",
)
SECRET = re.compile(r"ghp_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}|BEGIN [A-Z ]*PRIVATE KEY|AKIA[0-9A-Z]{16}")


class OrquestadorLocalReferenceTests(unittest.TestCase):
    def test_extension_cors_origin_cannot_inject_http_headers(self):
        # Testar el validador puro, sin iniciar el scheduler de referencia.
        import ast
        tree = ast.parse((TOOL / "orq.py").read_text(encoding="utf-8"))
        target = next(node for node in tree.body if isinstance(node, ast.FunctionDef) and node.name == "safe_extension_origin")
        namespace = {"re": re}
        exec(compile(ast.Module(body=[target], type_ignores=[]), "<origin-test>", "exec"), namespace)
        check = namespace["safe_extension_origin"]
        for good in ("chrome-extension://abcdefghijklmnopabcdefghijklmnop",
                     "moz-extension://12345678-abcd-1234-abcd-123456789abc",
                     "safari-web-extension://12345678-abcd-1234-abcd-123456789abc"):
            self.assertEqual(check(good), good)
        for bad in ("https://example.com", "chrome-extension://",
                    "chrome-extension://abc\r\nX-Evil: injected",
                    "moz-extension://abc\nSet-Cookie: hijacked",
                    "safari-web-extension://abc/path",
                    "chrome-extension://abc:8777",
                    "chrome-extension://abc,https://evil.test",
                    "chrome-extension://a" + "b" * 200):
            with self.subTest(origin=bad):
                self.assertIsNone(check(bad))

    def test_extension_json_response_rechecks_origin_at_sink(self):
        # El parámetro de _json_ext no debe servir para inyectar headers.
        import ast
        import io
        import json
        tree = ast.parse((TOOL / "orq.py").read_text(encoding="utf-8"))
        sanitizer = next(n for n in tree.body if isinstance(n, ast.FunctionDef)
                         and n.name == "safe_extension_origin")
        handler = next(n for n in tree.body if isinstance(n, ast.ClassDef)
                       and n.name == "Handler")
        emitter = next(n for n in handler.body if isinstance(n, ast.FunctionDef)
                       and n.name == "_json_ext")
        namespace = {"re": re, "json": json}
        exec(compile(ast.Module(body=[sanitizer, emitter], type_ignores=[]),
                     "<headers-test>", "exec"), namespace)

        class FakeResponse:
            def __init__(self):
                self.headers = []
                self.wfile = io.BytesIO()

            def send_response(self, status):
                self.status = status

            def send_header(self, name, value):
                self.headers.append((name, value))

            def end_headers(self):
                pass

        good = "chrome-extension://abcdefghijklmnopabcdefghijklmnop"
        bad = ("chrome-extension://abc\r\nX-Evil: injected", 
               "moz-extension://abc\nSet-Cookie: hijacked",
               "https://evil.example", "chrome-extension://abc/path")
        # La ruta GET sin Origin ya autenticada conserva wildcard legítimo.
        for origin in (good, "*", *bad):
            with self.subTest(origin=origin):
                reply = FakeResponse()
                namespace["_json_ext"](reply, 200, {"ok": True}, origin)
                expected = [("Access-Control-Allow-Origin", origin)] if origin in (good, "*") else []
                self.assertEqual(
                    [x for x in reply.headers if x[0] == "Access-Control-Allow-Origin"],
                    expected,
                )
                self.assertEqual(reply.status, 200)
                self.assertEqual(reply.wfile.getvalue(), b'{"ok": true}')

    def test_cors_headers_use_only_validated_emitters(self):
        source = (TOOL / "orq.py").read_text(encoding="utf-8")
        # Evita que un tercer sink directo restablezca una ruta de inyección.
        self.assertEqual(
            source.count('self.send_header("Access-Control-Allow-Origin",'), 2
        )
        self.assertIn('self.send_header("Access-Control-Allow-Origin", origin.replace(', source)
        self.assertIn('self.send_header("Access-Control-Allow-Origin", allowed.replace(', source)
        self.assertIn('return self._json_ext(200, {"ok": True}, origin)', source)

    def test_lan_routes_require_session_without_starting_server(self):
        import ast
        source = (TOOL / 'orq.py').read_text(encoding='utf-8')
        module = ast.parse(source)
        handler = next(n for n in module.body if isinstance(n, ast.ClassDef) and n.name == 'Handler')
        method = next(n for n in handler.body if isinstance(n, ast.FunctionDef) and n.name == 'do_GET')
        isolated = ast.Module(body=[ast.ClassDef(name='Isolated', bases=[], keywords=[], body=[method], decorator_list=[])], type_ignores=[])
        namespace = {'LOGIN_HTML': '<form>Acceso LAN</form>'}
        exec(compile(ast.fix_missing_locations(isolated), '<lan-test>', 'exec'), namespace)
        for path in ('/', '/index.html', '/grafo', '/grafo.html', '/data.json', '/api/data'):
            with self.subTest(path=path):
                class Client(namespace['Isolated']):
                    def _is_local(self): return False
                    def _lan_viewer(self): return False
                    def _send(self, status, body, kind):
                        self.result = (status, body, kind)
                client = Client()
                client.path = path
                client.do_GET()
                self.assertEqual(client.result[0], 401)
                self.assertNotIn('"data"', client.result[1])

    def test_dashboard_escapes_server_text_and_avoids_inline_actions(self):
        page = (TOOL / 'index.html').read_text(encoding='utf-8')
        graph = (TOOL / 'grafo.html').read_text(encoding='utf-8')
        self.assertIn('trustedHtml?m:esc(m)', page)
        self.assertNotIn("m.includes('<button')", page)
        self.assertIn('data-unblock=', page)
        self.assertNotIn('onclick="act(\'unblock\'', page)
        self.assertIn("g.addEventListener('keydown'", page)
        self.assertIn('pc.textContent="Condor "', graph)

    def test_contract_declares_remote_log_and_assign(self):
        contract = (TOOL / 'CONTRATO-EXTENSION.md').read_text(encoding='utf-8')
        self.assertIn('POST /api/af/log', contract)
        self.assertIn('"assign"', contract)

    def test_reference_tool_is_versioned_and_clean(self):
        for name in EXPECTED:
            with self.subTest(archivo=name):
                self.assertTrue((TOOL / name).is_file(), name)
        for name in ("orq.py", "grafo_orq.py"):
            compile((TOOL / name).read_text(encoding="utf-8"), name, "exec")
        for name in EXPECTED:
            text = (TOOL / name).read_text(encoding="utf-8")
            with self.subTest(limpio=name):
                self.assertIsNone(SECRET.search(text), "secreto en " + name)
                self.assertNotIn("/Users/", text)
                self.assertIsNone(re.search(r"\b192\.168\.\d+\.\d+\b", text))
        ignore = (TOOL / ".gitignore").read_text(encoding="utf-8").splitlines()
        for state in STATE_FILES:
            with self.subTest(ignorado=state):
                self.assertIn(state, ignore)
        import subprocess
        for state in ("agent_key.txt", "acciones.log", "assigned.json", "config.json"):
            tracked = subprocess.run(
                ["git", "ls-files", "--error-unmatch", str(TOOL / state)],
                cwd=ROOT, capture_output=True,
            ).returncode == 0
            self.assertFalse(tracked, state + " no debe versionarse")
        readme = (TOOL / "README.md").read_text(encoding="utf-8")
        self.assertIn("referencia", readme)
        self.assertIn("no se despliega", readme)


if __name__ == "__main__":
    unittest.main()
