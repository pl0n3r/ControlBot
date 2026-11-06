"""ControlBot #1429: auditoría sintética del módulo legal privado, read-only y fail-closed."""
import base64
import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = "require 'src/LegalStatusPanel.php'; "
FIXTURE = {
    "version": 1,
    "products": [
        {
            "product": "Condor",
            "documents": [
                {
                    "path": "docs/privacidad/politica.md",
                    "review_state": "draft",
                    "source_ref": "https://github.com/pl0n3r/Condor/blob/main/docs/privacidad/politica.md",
                    "approval": None,
                },
                {
                    "path": "revision-juridica/aviso.md",
                    "review_state": "in_review",
                    "source_ref": "https://github.com/pl0n3r/Condor/blob/main/revision-juridica/aviso.md",
                    "approval": None,
                },
                {
                    "path": "docs/privacidad/contrato.md",
                    "review_state": "approved",
                    "source_ref": "https://github.com/pl0n3r/Condor/blob/main/docs/privacidad/contrato.md",
                    "approval": None,
                },
            ],
            "controller": {
                "name": "[COMPLETAR POR EL DUEÑO]",
                "identifier": "[COMPLETAR POR EL DUEÑO]",
                "address": "Dato sintético nunca mostrado",
                "rights_email": "[COMPLETAR POR EL DUEÑO]",
            },
            "gates": [
                {"issue_ref": "https://github.com/pl0n3r/Condor/issues/974", "open": True},
                {"issue_ref": "https://github.com/pl0n3r/Condor/issues/1467", "open": True},
            ],
        }
    ],
}


def run(code, snapshot=FIXTURE):
    encoded = base64.b64encode(json.dumps(snapshot).encode()).decode()
    process = subprocess.run(
        ["php", "-r", PHP + code, encoded],
        cwd=ROOT, text=True, capture_output=True, timeout=25,
    )
    if process.returncode:
        raise AssertionError(process.stdout + process.stderr)
    return process.stdout


def project(snapshot=FIXTURE):
    output = run(
        r"$s=json_decode(base64_decode($argv[1]),true,32,JSON_THROW_ON_ERROR);"
        r"echo json_encode(\ControlBot\Legal\LegalStatusPanel::project($s),JSON_THROW_ON_ERROR);",
        snapshot,
    )
    return json.loads(output)


class LegalStatusPanelTests(unittest.TestCase):
    def test_drafts_never_shown_as_approved(self):
        model = project()
        self.assertEqual([p["product"] for p in model["products"]],
                         ["Condor", "GrindFlow", "BRVTAL", "ControlBot", "AutoFactory"])
        docs = model["products"][0]["documents"]
        self.assertEqual([d["status"] for d in docs],
                         ["BORRADOR", "EN REVISIÓN", "BORRADOR"])
        self.assertEqual([d["approval"] for d in docs], [None, None, None])
        self.assertTrue(all(p["source_state"] == "UNKNOWN" for p in model["products"][1:]))
        valid = json.loads(json.dumps(FIXTURE))
        valid["products"][0]["documents"][2]["approval"] = {
            "by": "Revisor autorizado (fixture)",
            "at": "2026-11-05T10:00:00Z",
            "evidence_ref": "https://github.com/pl0n3r/Condor/issues/974#issuecomment-123",
        }
        self.assertEqual(project(valid)["products"][0]["documents"][2]["status"], "APROBADO")
        html = run(
            r"$s=json_decode(base64_decode($argv[1]),true);"
            r"echo \ControlBot\Legal\LegalStatusPanel::render($s);",
            FIXTURE,
        )
        self.assertIn("BORRADOR pendiente de aprobación", html)
        self.assertNotIn("APROBADO", html)
        self.assertIn("UNKNOWN", html)

    def test_pending_owner_fields_and_open_gates_reported(self):
        snapshot = project()["products"][0]
        self.assertEqual(snapshot["pending_fields"],
                         ["Responsable", "NIT / identificación", "Correo de derechos"])
        self.assertEqual([g["number"] for g in snapshot["gates"]], ["974", "1467"])
        html = run(
            r"$s=json_decode(base64_decode($argv[1]),true);"
            r"echo \ControlBot\Legal\LegalStatusPanel::render($s);"
        )
        self.assertIn("Issue #974", html)
        self.assertIn("Issue #1467", html)
        self.assertNotIn("Dato sintético nunca mostrado", html)
        self.assertNotIn("[COMPLETAR POR EL DUEÑO]", html)

    def test_owner_only_and_read_only(self):
        source = (ROOT / "src/LegalStatusPanel.php").read_text(encoding="utf-8")
        bootstrap = (ROOT / "public/index.php").read_text(encoding="utf-8")
        shell = (ROOT / "src/ControlCenterShell.php").read_text(encoding="utf-8")
        self.assertIn("OwnerAuthenticatedRouter::verifiedOwner", bootstrap)
        self.assertIn("$legalPath === '/legal'", bootstrap)
        self.assertIn("OwnerLoginGate::requireOwner", bootstrap)
        self.assertIn("'legal' => 'Legal'", shell)
        self.assertNotIn("curl_exec", source)
        for mutation in ("file_put_contents", "unlink(", "fwrite(", "shell_exec",
                         "git push", "update_issue", "merge_pull_request"):
            self.assertNotIn(mutation, source)

        php = r"""
require 'src/Approvals.php';
require 'src/OwnerSession.php';
use ControlBot\Security\OwnerSessionService;
use ControlBot\Security\TokenVault;
use ControlBot\Security\Totp;
$scenario=$argv[1]; $now=time();
putenv('CONTROLBOT_OWNER_LOGIN=pl0n3r');
putenv('CONTROLBOT_OWNER_PASSWORD_HASH=');
putenv('CONTROLBOT_LEGAL_SNAPSHOT_PATH=');
$key=base64_encode(str_repeat('K',SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
putenv('CONTROLBOT_VAULT_KEY='.$key);
$_SERVER['REQUEST_METHOD']=($scenario==='post'?'POST':'GET');
$_SERVER['REQUEST_URI']='/legal';
$_SERVER['REMOTE_USER']='pl0n3r';
register_shutdown_function(static function () {
    file_put_contents('php://stderr', json_encode(['status'=>http_response_code()]));
});
if($scenario!=='anonymous'){
    ini_set('session.use_strict_mode','1');
    session_start();
    $id=session_id();
    $auth=new OwnerSessionService('pl0n3r',new TokenVault($key));
    $auth->establishTrustedOAuthSession($_SESSION,'pl0n3r','gho_synthetic_fixture');
    if($scenario!=='before'){
        $secret='JBSWY3DPEHPK3PXP';
        $auth->reauthenticateTotp($_SESSION,Totp::code($secret,$now),$secret,$now);
    }
    if($scenario==='expired')$_SESSION['_reauthenticated_at']=$now-600;
    session_write_close();
    $_COOKIE[session_name()]=$id;
}
require 'public/index.php';
"""
        results = {}
        for scenario in ("anonymous", "before", "allowed", "post", "expired"):
            response = subprocess.run(["php", "-r", php, scenario], cwd=ROOT,
                                      text=True, capture_output=True, timeout=25)
            self.assertEqual(response.returncode, 0, response.stdout + response.stderr)
            results[scenario] = (json.loads(response.stderr)["status"], response.stdout)
        self.assertEqual([results[k][0] for k in results],
                         [403, 403, 200, 405, 403])
        self.assertTrue(all("Forbidden." in results[k][1] for k in ("anonymous", "before", "expired")))
        self.assertIn("Legal y privacidad", results["allowed"][1])
        self.assertIn("UNKNOWN", results["allowed"][1])
        self.assertNotIn("gho_synthetic_fixture", results["allowed"][1])
        self.assertNotIn("Legal y privacidad", results["post"][1])

    def test_invalid_and_untrusted_documents_fail_closed(self):
        sample = json.loads(json.dumps(FIXTURE))
        sample["products"][0]["documents"][0]["path"] = "../secret.md"
        proc = run(
            r"$s=json_decode(base64_decode($argv[1]),true);"
            r"try{\ControlBot\Legal\LegalStatusPanel::project($s);echo 'BAD';}"
            r"catch(\InvalidArgumentException $e){echo 'DENIED';}",
            sample,
        )
        self.assertEqual(proc, "DENIED")
        x = json.loads(json.dumps(FIXTURE))
        x["products"][0]["documents"][2]["approval"] = {
            "by": "Fake", "at": "2026-11-05T10:00:00Z",
            "evidence_ref": "https://example.com/not-a-journal"
        }
        self.assertEqual(project(x)["products"][0]["documents"][2]["status"], "BORRADOR")
        x["products"][0]["documents"][2]["approval"] = {
            "by": "<img src=x onerror=alert(1)>",
            "at": "2026-11-05T10:00:00Z",
            "evidence_ref": "https://github.com/pl0n3r/Condor/issues/974#issuecomment-123"
        }
        html = run(
            r"$s=json_decode(base64_decode($argv[1]),true);"
            r"echo \ControlBot\Legal\LegalStatusPanel::render($s);", x,
        )
        self.assertNotIn("<img", html)
        self.assertIn("&lt;img", html)


if __name__ == "__main__":
    unittest.main()
