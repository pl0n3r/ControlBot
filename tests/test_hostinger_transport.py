"""ControlBot #1368: Hostinger SSH/API transport security contract, offline fakes."""
import json
import os
from pathlib import Path
import re
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs" / "hostinger-transport.md"


def load_contract():
    source = DOC.read_text(encoding="utf-8")
    match = re.search(r"\x60\x60\x60python\n(# BEGIN HOSTINGER_TRANSPORT_CONTRACT.*?# END HOSTINGER_TRANSPORT_CONTRACT)\n\x60\x60\x60", source, re.S)
    if match is None:
        raise AssertionError("Missing executable Hostinger contract")
    scope = {"__name__": "hostinger_contract_test"}
    exec(compile(match.group(1), str(DOC), "exec"), scope)
    return scope


CONTRACT = load_contract()
HostingerTransport = CONTRACT["HostingerTransport"]
FileSecretsBroker = CONTRACT["FileSecretsBroker"]


class FakeSSH:
    def __init__(self):
        self.match = True
        self.verifications = []
        self.probes = []

    def verify_host(self, fingerprint):
        self.verifications.append(fingerprint)
        return self.match

    def readonly_probe(self, secret):
        self.probes.append(secret)
        return {"evidence": "ssh_readonly_probe_ok"}


class FakeAPI:
    def __init__(self):
        self.calls = []

    def read(self, operation, credential):
        self.calls.append((operation, credential))
        return {"source": "hostinger-public-api", "capability": operation, "ok": True}


class FixtureBase:
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.root.chmod(0o700)
        self.key = self.root / "ssh.key"
        self.token = self.root / "api.token"
        self.key.write_text("fixture-SSH-material-no-real")
        self.token.write_text("fixture-API-material-no-real")
        self.key.chmod(0o600)
        self.token.chmod(0o600)
        self.env = {"HOSTINGER_SSH_KEY_FILE": str(self.key), "HOSTINGER_API_TOKEN_FILE": str(self.token)}
        self.ssh = FakeSSH()
        self.api = FakeAPI()
        self.audit = []
        self.fingerprint = "SHA256:Abcdefghi12345ABCDEFG1234567+/="

    def gateway(self, mode="none", enabled=False, fingerprint=None, env=None, audit=None):
        return HostingerTransport(
            {"transport": mode, "enabled": enabled, "expected_fingerprint": fingerprint},
            FileSecretsBroker(self.root, self.env if env is None else env),
            self.ssh, self.api, self.audit.append if audit is None else audit,
            lambda: "2026-10-30T22:00:00-05:00",
        )

    def test_criterio_principal(self):
        disabled = self.gateway()
        self.assertEqual("transport_disabled", disabled.dispatch("owner", "hostinger.read")["reason"])
        api = self.gateway(mode="api", enabled=True)
        self.assertTrue(api.dispatch("owner", "hostinger.read")["ok"])
        self.assertTrue(api.dispatch("owner", "cron.snapshot")["ok"])
        self.assertEqual(["hostinger.read", "cron.snapshot"], [v[0] for v in self.api.calls])
        self.assertEqual("operation_denied", api.dispatch("owner", "cron.write")["reason"])
        self.assertEqual("operation_denied", api.dispatch("owner", "ssh.readonly")["reason"])
        ssh = self.gateway(mode="ssh", enabled=True, fingerprint=self.fingerprint)
        self.assertTrue(ssh.dispatch("owner", "ssh.readonly")["ok"])
        self.assertEqual([self.fingerprint], self.ssh.verifications)
        self.assertEqual(1, len(self.ssh.probes))
        self.assertEqual(0, len(self.api.calls) - 2)
        self.assertTrue(all(set(v) == {"actor", "operation", "transport", "at", "ok", "reason"} for v in self.audit))
        self.assertFalse(any("fixture-" in json.dumps(v) for v in self.audit))

    def test_unknown_operations_and_invalid_actor_are_denied_before_secrets(self):
        gateway = self.gateway(mode="api", enabled=True)
        for operation in ("cron.write", "site.delete", "dns.write", "shell.arbitrary", "deploy", "GET /invalid", "ssh.readonly"):
            self.assertFalse(gateway.dispatch("agent", operation)["ok"])
        self.assertEqual("invalid_request", gateway.dispatch("invalid actor!", "hostinger.read")["reason"])
        self.assertEqual([], self.api.calls)

    def test_ssh_host_key_mismatch_fails_before_probe(self):
        self.assertEqual("host_fingerprint_missing", self.gateway(mode="ssh", enabled=True).dispatch("owner", "ssh.readonly")["reason"])
        self.ssh.match = False
        self.assertFalse(self.gateway(mode="ssh", enabled=True, fingerprint=self.fingerprint).dispatch("owner", "ssh.readonly")["ok"])
        self.assertEqual([], self.ssh.probes)
        self.assertEqual([self.fingerprint], self.ssh.verifications)

    def test_file_paths_perms_and_noncanonical_modes_fail_closed(self):
        for mode in ("shell", "none", None, "hostinger-api"):
            self.assertFalse(self.gateway(mode=mode, enabled=True).dispatch("owner", "hostinger.read")["ok"])
        self.assertFalse(self.gateway(mode="api", enabled=True, env={}).dispatch("owner", "hostinger.read")["ok"])
        self.token.chmod(0o644)
        self.assertFalse(self.gateway(mode="api", enabled=True).dispatch("owner", "hostinger.read")["ok"])
        self.token.chmod(0o600)
        alias = self.root / "alias.token"
        alias.symlink_to(self.token)
        self.assertFalse(self.gateway(mode="api", enabled=True, env={"HOSTINGER_API_TOKEN_FILE": str(alias)}).dispatch("owner", "hostinger.read")["ok"])
        external = self.root.parent / (self.root.name + "-outside")
        external.write_text("fixture-SECRET-no-real")
        external.chmod(0o600)
        self.addCleanup(lambda: external.unlink(missing_ok=True))
        self.assertFalse(self.gateway(mode="api", enabled=True, env={"HOSTINGER_API_TOKEN_FILE": str(external)}).dispatch("owner", "hostinger.read")["ok"])
        other = self.root / "second-link"
        os.link(self.token, other)
        self.assertFalse(self.gateway(mode="api", enabled=True).dispatch("owner", "hostinger.read")["ok"])
        self.assertEqual([], self.api.calls)

    def test_broker_redacts_sensitive_results_and_audit_failures(self):
        class LeakyAPI(FakeAPI):
            def read(self, op, secret):
                return {"token": secret, "message": "received " + secret, "nested": [secret]}
        self.api = LeakyAPI()
        data = self.gateway(mode="api", enabled=True).dispatch("owner", "hostinger.read")
        self.assertTrue(data["ok"])
        safe = json.dumps(data) + json.dumps(self.audit)
        self.assertNotIn("fixture-API-material", safe)
        self.assertIn("[REDACTED]", safe)
        def failed_audit(row):
            raise OSError("ledger offline")
        result = self.gateway(mode="api", enabled=True, audit=failed_audit).dispatch("owner", "hostinger.read")
        self.assertEqual("audit_unavailable", result["reason"])
        self.assertFalse(result["ok"])

    def test_canonical_document_and_offline_only(self):
        content = DOC.read_text(encoding="utf-8")
        for item in ("HostingerSshRuntime.php", "HostingerPublicApiRuntime.php", "SecretsBroker.php", "StrictHostKeyChecking", "go-live", "Hostinger-transportTests::test_criterio_principal"):
            self.assertIn(item, content)
        body = content.split("# BEGIN HOSTINGER_TRANSPORT_CONTRACT", 1)[1].split("# END HOSTINGER_TRANSPORT_CONTRACT", 1)[0]
        for forbidden in ("urllib.request", "requests.", "paramiko.", "subprocess.", "socket.connect"):
            self.assertNotIn(forbidden, body)


globals()["Hostinger-transportTests"] = type("Hostinger-transportTests", (FixtureBase, unittest.TestCase), {})

if __name__ == "__main__":
    unittest.main()
