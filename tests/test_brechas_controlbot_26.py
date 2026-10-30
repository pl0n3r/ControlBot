"""ControlBot #1033: quince cláusulas CB-0380..CB-0394 verificadas offline.

Fixtures sintéticos PHP. No acredita emisión real de grants, GitHub, backup,
aprobación productiva, despliegue ni go-live.
"""
from __future__ import annotations

from test_brechas_controlbot_20 import credible_offline_coverage_row

import copy
from functools import lru_cache
import json
from pathlib import Path
import re
import runpy
import unittest

ROOT = Path(__file__).resolve().parents[1]
TESTS = ROOT / "tests"
IDS = tuple(f"CB-{n:04d}" for n in range(380, 395))
FLAGS = ("execution", "authorized", "grant_issued", "approval_issued",
         "approval_verified")


@lru_cache(maxsize=4)
def helper(name: str) -> dict:
    files = {
        "gateway": "test_github_intent_execution_gateway.py",
        "verification": "test_github_intent_grant_verification.py",
        "harness": "test_github_intent_offline_grant_harness.py",
        "issuer": "test_github_intent_offline_issuer.py",
    }
    return runpy.run_path(str(TESTS / files[name]))


def source(name: str) -> str:
    return (ROOT / "src" / name).read_text(encoding="utf-8")


def document(name: str) -> str:
    return (ROOT / "docs" / name).read_text(encoding="utf-8")


def denied(record: dict, runtime: bool = False) -> bool:
    return (record.get("decision") == "deny"
            and all(record.get(key) is False for key in FLAGS)
            and (not runtime or record.get("runtime_authorized") is False))


class BrechasControlbot26Tests(unittest.TestCase):
    """Cada ID tiene una aserción ejecutable con evidencia PHP real."""

    def test_contrato_principal(self) -> None:
        gw, vf, hr, iss = (helper(x) for x in
                           ("gateway", "verification", "harness", "issuer"))
        gateway = gw["execute"](gw["candidate"]())
        receipt = gateway["receipts"][0]
        invalid = gw["candidate"]()
        invalid["approval"]["approved"] = False
        rejected = gw["execute"](invalid)
        verification = vf["evaluate"](vf["candidate"]())
        harness = hr["execute"](hr["candidate"]())
        issued = iss["execute"](iss["candidate"]("pr.merge"))
        issuer, verified = issued["issuer"], issued["verification"]
        bad = vf["candidate"]()
        bad["requirements"]["capability"] = "github.admin"
        bad["proof"]["capability"] = "github.admin"
        vf["sign"](bad)
        unknown = vf["evaluate"](bad)
        gateway_src = source("GitHubIntentExecutionGateway.php")
        harness_src = source("GitHubIntentOfflineGrantHarness.php")
        issuer_src = source("GitHubIntentOfflineIssuer.php")
        docs_grant = document("github-intent-grant-verification.md")
        docs_harness = document("github-intent-offline-grant-harness.md")
        docs_issuer = document("github-intent-offline-issuer.md")

        checks = {
            "CB-0380": (rejected["calls"] == 0
                        and denied(rejected["receipts"][0], True)
                        and rejected["receipts"][0]["fixture_status"] == "denied"),
            "CB-0381": (denied(receipt, True)
                        and "curl_exec(" not in gateway_src
                        and "GitHubHttpTransport" not in gateway_src),
            "CB-0382": ("#855" in docs_grant and "opción A" in docs_grant
                        and verification["decision"] == "deny"
                        and verification["execution"] is False),
            "CB-0383": (verification["proof_valid"] is True
                        and verification["reason"] == "offline_only"
                        and denied(verification)),
            "CB-0384": (unknown["proof_valid"] is False
                        and denied(unknown)
                        and "github.admin" not in source("CapabilityPolicy.php")),
            "CB-0385": (len(unittest.defaultTestLoader.getTestCaseNames(
                vf["GitHubIntentGrantVerificationTests"])) == 4
                        and verification["proof_valid"] is True
                        and "curl_init(" not in
                        source("GitHubIntentGrantVerification.php")),
            "CB-0386": ("#878/A" in docs_harness
                        and harness["test_grant_valid"] is True
                        and denied(harness, True)),
            "CB-0387": (harness["fixture_status"] == "accepted_offline"
                        and harness["fake_effect"]["status"] == "simulated"
                        and len(harness["fake_effect"]["fixture_digest"]) == 64),
            "CB-0388": (denied(harness, True) and denied(receipt, True)
                        and receipt["fixture_status"] == "simulated"),
            "CB-0389": ("idempotency" not in harness_src.lower()
                        and "fake_action" in harness_src
                        and "GitHubIntentTestGrantAuthority" in harness_src),
            "CB-0390": (len(unittest.defaultTestLoader.getTestCaseNames(
                hr["GitHubIntentOfflineGrantHarnessTests"])) == 4
                        and "GitHubIntentOfflineGrantHarness::simulate" in hr["PHP"]
                        and hr["execute"](hr["candidate"]()) == harness),
            "CB-0391": ("construccion" in docs_harness
                        and "curl_exec(" not in harness_src
                        and "proc_open(" not in harness_src
                        and denied(harness, True)),
            "CB-0392": ("#867" in docs_issuer and "opción A" in docs_issuer
                        and denied(issuer) and issuer["proof"] is not None),
            "CB-0393": ("GitHubIntentOfflineIssuer::issue" in iss["PHP"]
                        and len(issuer["proof"]["signature"]) == 64
                        and verified["proof_valid"] is True
                        and denied(verified)),
            "CB-0394": ("ttl_seconds" in issuer_src
                        and "revokedNonces" in issuer_src
                        and issuer["reason"] == "offline_only"
                        and denied(issuer)),
        }
        self.assertEqual(set(checks), set(IDS), "cláusula silenciada")
        for clause, passed in checks.items():
            with self.subTest(clause=clause):
                self.assertTrue(passed, "brecha NO_VERIFICABLE: " + clause)

    def test_falla_cerrado(self) -> None:
        gw, vf, hr, iss = (helper(x) for x in
                           ("gateway", "verification", "harness", "issuer"))
        invalid = []
        for section, key, value in (
            ("approval", "approved", False), ("approval", "origin", "client"),
            ("approval", "scope_ref", "wrong"),
            ("trusted", "mode", "production"),
            ("trusted", "ttl_seconds", 301),
            ("requirements", "grant_issued", True),
        ):
            row = gw["candidate"]()
            row[section][key] = value
            invalid.append(row)
        result = gw["execute"](
            *invalid, keys=[f"negative-{i}" for i in range(len(invalid))])
        self.assertEqual(result["calls"], 0)
        for receipt in result["receipts"]:
            self.assertTrue(denied(receipt, True))
            self.assertEqual(receipt["fixture_status"], "denied")
        for mode in ("throw", "malformed", "false_success", "mismatch", "extra"):
            with self.subTest(mode=mode):
                row = gw["execute"](gw["candidate"](), sender_mode=mode)
                self.assertTrue(denied(row["receipts"][0], True))
                self.assertEqual(row["receipts"][0]["fixture_status"], "ambiguous")
        for section, key, value in (
            ("trusted", "ttl_seconds", 0),
            ("trusted", "nonce", "invalid"),
            ("approval", "approved", False),
            ("approval", "owner", "unknown"),
            ("requirements", "capability", "github.admin"),
        ):
            with self.subTest(section=section, key=key):
                row = iss["candidate"]()
                row[section][key] = value
                issued = iss["execute"](row)["issuer"]
                self.assertTrue(denied(issued))
                self.assertIsNone(issued["proof"])
        revoked = iss["candidate"]()
        revoked["revoked"] = [revoked["trusted"]["nonce"]]
        self.assertIsNone(iss["execute"](revoked)["issuer"]["proof"])
        forged = hr["candidate"]()
        forged["fake_action"]["fixture_ref"] = "https://github.com/other"
        rejected = hr["execute"](forged)
        self.assertTrue(denied(rejected, True))
        self.assertEqual(rejected["fake_effect"]["status"], "denied")
        bad_proof = vf["candidate"]()
        bad_proof["proof"]["signature"] = "0" * 64
        inspected = vf["evaluate"](bad_proof)
        self.assertTrue(denied(inspected))
        self.assertIs(inspected["proof_valid"], False)

    def test_sin_datos_reales(self) -> None:
        gw, hr, iss = (helper(x) for x in ("gateway", "harness", "issuer"))
        receipts = (gw["execute"](gw["candidate"]()),
                    hr["execute"](hr["candidate"]()),
                    iss["execute"](iss["candidate"]())["issuer"])
        for label, record in zip(("gateway", "harness", "issuer"),
                                 receipts, strict=True):
            with self.subTest(label=label):
                serialized = json.dumps(record).lower()
                for sensitive in ("github_pat_", "authorization: bearer",
                                  "password", "client_secret", "private_key",
                                  gw["KEY"].lower()):
                    self.assertNotIn(sensitive, serialized)
        for php in ("GitHubIntentExecutionGateway.php",
                    "GitHubIntentGrantVerification.php",
                    "GitHubIntentOfflineGrantHarness.php",
                    "GitHubIntentOfflineIssuer.php"):
            content = source(php)
            for transport in ("curl_init(", "curl_exec(", "file_get_contents(",
                              "shell_exec(", "proc_open("):
                with self.subTest(source=php, transport=transport):
                    self.assertNotIn(transport, content)

    def test_estados_unknown_explicitos(self) -> None:
        rows = {clause: [] for clause in IDS}
        for name in ("matriz-cobertura.md", "matriz-cobertura-parte-02.md"):
            path = ROOT / "docs" / name
            for line in path.read_text(encoding="utf-8").splitlines():
                match = re.match(r"\| (CB-\d{4}) \|", line)
                if match and match.group(1) in rows:
                    rows[match.group(1)].append(line)
        for clause, matches in rows.items():
            with self.subTest(clause=clause):
                self.assertEqual(len(matches), 1)
                cells = tuple(x.strip() for x in matches[0].strip("|").split("|"))
                self.assertTrue(credible_offline_coverage_row(cells))
                for status in ("live=unknown", "operando=unknown"):
                    self.assertIn(status, cells[4].lower())
                if cells[3] == "NO_VERIFICABLE":
                    self.assertIn("offline=unknown", cells[4].lower())
        gw, hr = helper("gateway"), helper("harness")
        gateway = gw["execute"](gw["candidate"]())
        self.assertEqual(gateway["calls"], 1)  # Only an injected fake.
        self.assertEqual(gateway["receipts"][0]["decision"], "deny")
        self.assertIs(gateway["receipts"][0]["runtime_authorized"], False)
        self.assertEqual(hr["execute"](hr["candidate"]())["fixture_status"],
                         "accepted_offline")


if __name__ == "__main__":
    unittest.main()
