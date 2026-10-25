import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name):
    p = subprocess.run(
        ["php", str(ROOT / "tests" / "backup_integrity_scenarios.php"), name],
        cwd=ROOT, check=True, capture_output=True, text=True,
    )
    return json.loads(p.stdout)


class BackupIntegrityTests(unittest.TestCase):
    def test_sha256_digest_shape_fail_closed(self):
        d = scenario("shape")
        self.assertTrue(d["valid"])
        self.assertTrue(d["uppercase"])
        for invalid in ("short", "non_hex", "long"):
            self.assertEqual(
                d[invalid],
                {"status": "UNKNOWN", "reason": "digest_invalid"},
                invalid,
            )

    def test_in_memory_checksum_verification(self):
        d = scenario("content")
        good = {"status": "MATCH", "reason": "checksum_match"}
        self.assertEqual(d["match"], good)
        self.assertEqual(d["uppercase"], good)
        self.assertEqual(d["mismatch"], {"status": "MISMATCH", "reason": "checksum_mismatch"})

    def test_scope_time_and_invalid_inputs_fail_closed(self):
        d = scenario("guard")
        for case, reason in (("scope", "scope_mismatch"),
                             ("premature", "receipt_unusable"),
                             ("expired", "receipt_unusable"),
                             ("stale", "receipt_stale")):
            self.assertEqual(d[case], {"status": "UNKNOWN", "reason": reason})
        self.assertTrue(d["invalid_scope"])
        self.assertTrue(d["invalid_time"])

    def test_no_payload_leak_or_provider_io(self):
        d = scenario("privacy")
        serialized = json.dumps(d)
        self.assertNotIn("synthetic-backup-data-only/no-real-records", serialized)
        self.assertNotIn("integrity", d["safe_receipt"])
        self.assertEqual(set(d["result"]), {"status", "reason"})
        source = (ROOT / "src" / "BackupReceipt.php").read_text()
        method = source.split("public function verifyPayloadChecksumBefore", 1)[1].split(
            "public function safeEvidence", 1
        )[0]
        for forbidden in ("file_get_contents", "fopen(", "curl_", "shell_exec",
                          "passthru(", "mysqli", "PDO(", "echo ", "print "):
            self.assertNotIn(forbidden, method)


if __name__ == "__main__":
    unittest.main()
