"""Pruebas offline de contrato de grants GitHub (#856, decisión #855=A)."""
import copy
import hashlib
import hmac
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
KEY = bytes(range(32, 64))  # Fixture determinista, no credencial real.
SIGNED = ("capability", "scope", "scope_ref", "owner", "issued_at",
          "expires_at", "nonce", "issuer")
PHP = r"""
require 'src/GitHubIntentGrantVerification.php';
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$key = hex2bin($input['key']);
echo json_encode(
    \ControlBot\GitHub\GitHubIntentGrantVerification::inspect(
        $input['requirements'], $input['proof'], $input['trusted'],
        $input['seen'], $input['now'], $key
    ),
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
);
"""


def candidate():
    req = {
        "version": 1, "state": "requirements", "type": "issue.update",
        "capability": "github.issue.write", "scope": "repository",
        "scope_ref": "pl0n3r/ControlBot",
        "scope_parameters": {"repository_ref": "pl0n3r/ControlBot"},
        "approval_class": "owner_required", "authorized": False,
        "grant_issued": False, "approval_issued": False,
        "approval_verified": False, "execution": False,
    }
    proof = {
        "capability": "github.issue.write", "scope": "repository",
        "scope_ref": "pl0n3r/ControlBot", "owner": "pl0n3r",
        "issued_at": 1000, "expires_at": 1200,
        "nonce": "ab" * 16, "issuer": "controlbot:authority",
    }
    out = {
        "requirements": req, "proof": proof,
        "trusted": {
            "origin": "server", "issuer": "controlbot:authority",
            "owner": "pl0n3r", "project_ref": "controlbot:project/controlbot",
            "repository_ref": "pl0n3r/ControlBot",
        },
        "seen": [], "now": 1100, "key": KEY.hex(),
    }
    sign(out)
    return out


def sign(case):
    payload = json.dumps([case["proof"][name] for name in SIGNED],
                         separators=(",", ":"), ensure_ascii=False)
    case["proof"]["signature"] = hmac.new(
        KEY, payload.encode("utf-8"), hashlib.sha256
    ).hexdigest()


def evaluate(case):
    run = subprocess.run(
        ["php", "-r", PHP], cwd=ROOT,
        input=json.dumps(case), capture_output=True, text=True, check=False,
    )
    if run.returncode:
        raise AssertionError(run.stderr.strip() or run.stdout.strip())
    return json.loads(run.stdout)


class GitHubIntentGrantVerificationTests(unittest.TestCase):
    def test_unknown_github_capability_is_never_registered_by_verification(self):
        original = candidate()
        valid = evaluate(original)
        self.assertIs(valid["proof_valid"], True)
        self.assertEqual(valid["decision"], "deny")
        for bad in ("github.admin", "github.issue.mutate", "github:fake"):
            with self.subTest(bad=bad):
                case = copy.deepcopy(original)
                case["requirements"]["capability"] = bad
                case["proof"]["capability"] = bad
                sign(case)
                result = evaluate(case)
                self.assertEqual(result["decision"], "deny")
                self.assertIs(result["proof_valid"], False)
                self.assertIs(result["execution"], False)

    def test_missing_or_unverifiable_signature_and_client_origin_fail_closed(self):
        for alteration in ("missing", "bad", "client", "unknown_issuer", "extra"):
            with self.subTest(alteration=alteration):
                case = candidate()
                if alteration == "missing":
                    case["proof"].pop("signature")
                elif alteration == "bad":
                    case["proof"]["signature"] = "0" * 64
                elif alteration == "client":
                    case["trusted"]["origin"] = "client"
                elif alteration == "unknown_issuer":
                    case["proof"]["issuer"] = "client:claimed"
                    sign(case)
                else:
                    case["proof"]["authorized"] = True
                result = evaluate(case)
                self.assertIs(result["proof_valid"], False)
                self.assertIs(result["authorized"], False)

    def test_scope_owner_expiration_replay_and_stale_proofs_are_denied(self):
        for alteration in ("scope", "owner", "expired", "stale", "replay",
                           "future", "too_long", "wrong_project"):
            with self.subTest(alteration=alteration):
                case = candidate()
                if alteration == "scope":
                    case["proof"]["scope_ref"] = "pl0n3r/Elsewhere"
                elif alteration == "owner":
                    case["proof"]["owner"] = "not-owner"
                elif alteration == "expired":
                    case["proof"]["expires_at"] = case["now"]
                elif alteration == "stale":
                    case["proof"]["issued_at"] = 700
                elif alteration == "replay":
                    case["seen"] = [case["proof"]["nonce"]]
                elif alteration == "future":
                    case["proof"]["issued_at"] = case["now"] + 1
                elif alteration == "too_long":
                    case["proof"]["expires_at"] = 1400
                else:
                    case["trusted"]["project_ref"] = "controlbot:other"
                    case["requirements"]["type"] = "project.freeze"
                sign(case)
                result = evaluate(case)
                self.assertIs(result["proof_valid"], False)
                self.assertIs(result["execution"], False)

    def test_offline_proof_never_grants_execution_or_tokens(self):
        result = evaluate(candidate())
        self.assertEqual(result["reason"], "offline_only")
        self.assertIs(result["proof_valid"], True)
        self.assertEqual(result["decision"], "deny")
        for key in ("authorized", "grant_issued", "approval_issued",
                    "approval_verified", "execution"):
            self.assertIs(result[key], False)
        source = (ROOT / "src/GitHubIntentGrantVerification.php").read_text()
        for forbidden in ("curl_init(", "file_get_contents(", "shell_exec(",
                          "CapabilityGrant::issue(", "GithubClient", "github_pat_"):
            self.assertNotIn(forbidden, source)
        cap = (ROOT / "src/CapabilityPolicy.php").read_text()
        self.assertNotIn("'github.issue.write' =>", cap)


if __name__ == "__main__":
    unittest.main()
