"""Cableado de la página de login del dueño (#1346), solo con datos sintéticos."""
import os
import socket
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.request
from pathlib import Path

from tests.test_owner_login_gate import BASE, ROOT, php_run


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def _serve(extra_env):
    """Arranca public/index.php con el servidor integrado de PHP y devuelve (proceso, puerto)."""
    with socket.socket() as probe:
        probe.bind(("127.0.0.1", 0))
        port = probe.getsockname()[1]
    env = {**os.environ, "CONTROLBOT_OWNER_LOGIN": "pl0n3r", **extra_env}
    proc = subprocess.Popen(["php", "-S", f"127.0.0.1:{port}", "-t", str(ROOT / "public")],
                            env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    for _ in range(50):
        try:
            socket.create_connection(("127.0.0.1", port), timeout=0.2).close()
            return proc, port
        except OSError:
            time.sleep(0.1)
    proc.kill(); proc.wait()
    raise AssertionError("El servidor de prueba no arrancó.")


def _get(port, path, accept):
    opener = urllib.request.build_opener(_NoRedirect)
    request = urllib.request.Request(f"http://127.0.0.1:{port}{path}", headers={"Accept": accept})
    try:
        response = opener.open(request, timeout=5)
    except urllib.error.HTTPError as error:
        response = error
    return response.status if hasattr(response, "status") else response.code, response.headers


class OwnerLoginRedirectTests(unittest.TestCase):
    def test_unauthenticated_browser_is_sent_to_login_and_never_gets_basic_prompt(self):
        with tempfile.TemporaryDirectory(prefix="controlbot-1346-") as limits:
            hash_ = subprocess.run(
                ["php", "-r", "echo password_hash('fixture-only', PASSWORD_ARGON2ID, "
                 "['memory_cost'=>4096,'time_cost'=>1,'threads'=>1]);"],
                text=True, capture_output=True, check=True).stdout
            proc, port = _serve({"CONTROLBOT_OWNER_PASSWORD_HASH": hash_,
                                 "CONTROLBOT_OWNER_RATE_LIMIT_DIR": limits})
            try:
                status, headers = _get(port, "/overview", "text/html")
                self.assertEqual(status, 302)
                self.assertEqual(headers["Location"], "/login?next=%2Foverview")
                self.assertIsNone(headers["WWW-Authenticate"])
                status, headers = _get(port, "/overview", "application/json")
                self.assertEqual(status, 403)
                self.assertIsNone(headers["Location"])
                self.assertIsNone(headers["WWW-Authenticate"])
            finally:
                proc.kill(); proc.wait()

    def test_missing_owner_password_hash_never_exposes_private_routes(self):
        proc, port = _serve({"CONTROLBOT_OWNER_PASSWORD_HASH": ""})
        try:
            for accept in ("text/html", "application/json"):
                status, headers = _get(port, "/overview", accept)
                self.assertIn(status, (403, 503))
                self.assertIsNone(headers["Location"])
        finally:
            proc.kill(); proc.wait()
        with tempfile.TemporaryDirectory(prefix="controlbot-1346-") as limits:
            proc, port = _serve({"CONTROLBOT_OWNER_PASSWORD_HASH": "no-es-argon2",
                                 "CONTROLBOT_OWNER_RATE_LIMIT_DIR": limits})
            try:
                status, _ = _get(port, "/overview", "text/html")
                self.assertEqual(status, 503)
            finally:
                proc.kill(); proc.wait()

    def test_post_logout_csrf_and_safe_internal_return(self):
        php_run(r"""
foreach (['/overview', '/github', '/projects/condor/github'] as $ok)
    check(OwnerLoginGate::safeNext($ok) === $ok);
foreach (['//evil.com', 'https://evil.com', '/\\evil', '/login', '/login?x=1', '/../x',
          'javascript:alert(1)', '/a b', "/a\r\nb", '', null, 7, str_repeat('/a', 200)] as $bad)
    check(OwnerLoginGate::safeNext($bad) === '');

$get['QUERY_STRING'] = 'next=' . rawurlencode('/github');
$form = OwnerLoginGate::handle($get, [], $s, $cfg, $now);
check(str_contains($form['body'], 'name="next" value="/github"'));
$csrf = $s['_cb_login_csrf'];
$evil = OwnerLoginGate::handle($post, ['csrf'=>$csrf,'username'=>'pl0n3r','password'=>'fixture-only','next'=>'//evil.com'], $s, $cfg, $now, $regenerate);
check($evil['status']===303 && $evil['headers']['Location']==='/');
$s = [];
OwnerLoginGate::handle($get, [], $s, $cfg, $now);
$csrf = $s['_cb_login_csrf'];
$back = OwnerLoginGate::handle($post, ['csrf'=>$csrf,'username'=>'pl0n3r','password'=>'fixture-only','next'=>'/github'], $s, $cfg, $now, $regenerate);
check($back['status']===303 && $back['headers']['Location']==='/github');

$noCsrf = OwnerLoginGate::handle($post, ['action'=>'logout'], $s, $cfg, $now, $regenerate);
check($noCsrf['status']===403 && OwnerLoginGate::isAuthenticated($s, $now));
$badCsrf = OwnerLoginGate::handle($post, ['action'=>'logout','csrf'=>'x'], $s, $cfg, $now, $regenerate);
check($badCsrf['status']===403 && OwnerLoginGate::isAuthenticated($s, $now));
$getLogout = OwnerLoginGate::handle(array_merge($get, ['QUERY_STRING'=>'action=logout']), [], $s, $cfg, $now);
check(OwnerLoginGate::isAuthenticated($s, $now));
$out = OwnerLoginGate::handle($post, ['action'=>'logout','csrf'=>$s['_cb_login_csrf']], $s, $cfg, $now, $regenerate);
check($out['status']===303 && $out['headers']['Location']==='/login' && !OwnerLoginGate::isAuthenticated($s, $now));
""")
