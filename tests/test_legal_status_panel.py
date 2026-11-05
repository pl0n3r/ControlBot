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
        entry = (ROOT / "src/ControlBotWebEntrypoint.php").read_text(encoding="utf-8")
        shell = (ROOT / "src/ControlCenterShell.php").read_text(encoding="utf-8")
        self.assertIn("OwnerAuthenticatedRouter::verifiedOwner", entry)
        self.assertIn("self::legalResponse($server)", entry)
        self.assertIn("'legal' => 'Legal'", shell)
        self.assertNotIn("curl_exec", source)
        for method in ("file_put_contents", "unlink(", "fwrite(", "shell_exec",
                       "git push", "update_issue", "merge_pull_request"):
            self.assertNotIn(method, source)

        code = r"""
require 'src/Approvals.php';
require 'src/OwnerSession.php';
require 'src/ControlBotWebEntrypoint.php';
use ControlBot\Security\OwnerSessionService;
use ControlBot\Security\TokenVault;
use ControlBot\Security\Totp;
use ControlBot\Web\ControlBotWebEntrypoint;
$now=1800000000;
$owner='pl0n3r';
$key=base64_encode(str_repeat('K',SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
$auth=new OwnerSessionService($owner,new TokenVault($key));
$session=[];
$auth->establishTrustedOAuthSession($session,$owner,'gho_synthetic_fixture');
$env=['CONTROLBOT_OWNER_LOGIN'=>$owner];
$server=['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/legal','REMOTE_USER'=>$owner];
$public=ControlBotWebEntrypoint::handle($server,$env,$now);
$denied=ControlBotWebEntrypoint::handleAuthenticated($server,$env,$now,[],$auth);
$beforeTotp=ControlBotWebEntrypoint::handleAuthenticated($server,$env,$now,$session,$auth);
$secret='JBSWY3DPEHPK3PXP';
$auth->reauthenticateTotp($session,Totp::code($secret,$now),$secret,$now);
$allowed=ControlBotWebEntrypoint::handleAuthenticated($server,$env,$now,$session,$auth);
$post=$server; $post['REQUEST_METHOD']='POST';
$mutation=ControlBotWebEntrypoint::handleAuthenticated($post,$env,$now,$session,$auth);
$expired=ControlBotWebEntrypoint::handleAuthenticated($server,$env,$now+301,$session,$auth);
echo json_encode([
 'public'=>$public['status'],'denied'=>$denied['status'],
 'before_totp'=>$beforeTotp['status'],'allowed'=>$allowed['status'],
 'method'=>$mutation['status'],'expired'=>$expired['status'],
 'no_store'=>$allowed['headers']['Cache-Control']??null,
 'owner_only_body'=>str_contains($allowed['body'],'Legal y privacidad'),
 'has_unknown'=>str_contains($allowed['body'],'UNKNOWN'),
 'no_secret'=>!str_contains($allowed['body'],'gho_synthetic_fixture')
],JSON_THROW_ON_ERROR);
"""
        result = subprocess.run(["php", "-r", code], cwd=ROOT, text=True,
                                capture_output=True, timeout=25)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        output = json.loads(result.stdout)
        self.assertEqual((output["public"], output["denied"], output["before_totp"]),
                         (403, 403, 403))
        self.assertEqual((output["allowed"], output["method"], output["expired"]),
                         (200, 405, 403))
        self.assertEqual(output["no_store"], "no-store")
        self.assertTrue(all(output[k] for k in ("owner_only_body", "has_unknown", "no_secret")))

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
