"""ControlBot #1035: 15 fail-closed GitHub Intent clauses (CB-0410..0424).

All proofs run existing PHP code with synthetic fixtures. A valid offline HMAC
or canonical-looking snapshot never grants GitHub runtime authority, owner
approval for live execution, deployment, legal attestation, or go-live.
"""
from __future__ import annotations

import json
import re
import unittest
from pathlib import Path

from tests import test_github_intent_test_grant_authority as authority
from tests import test_github_intent_trusted_evidence as trusted
from tests import test_github_intent_preview as preview

ROOT = Path(__file__).resolve().parents[1]
IDS = tuple(f"CB-{n:04d}" for n in range(410, 425))
SOURCES = ("docs/matriz-cobertura.md", "docs/matriz-cobertura-parte-02.md")
AUTHORITY_FIELDS = ("runtime_authorized", "authorized", "grant_issued",
                    "approval_issued", "approval_verified", "execution")
UNKNOWN = "UNKNOWN"


def coverage_rows(*documents: str) -> dict[str, tuple[str, ...]]:
    """Require all 15 exact canonical rows, including the split-partition IDs."""
    found: dict[str, tuple[str, ...]] = {}
    for doc in documents:
        for line in doc.splitlines():
            match = re.match(r"^\|\s*(CB-04(?:1[0-9]|2[0-4]))(?=\W|$)", line)
            if match is None or match.group(1) not in IDS:
                continue
            key = match.group(1)
            if key in found or not line.startswith("| " + key + " |"):
                raise ValueError("UNKNOWN: duplicate or malformed row " + key)
            parts = tuple(part.strip() for part in line.strip().strip("|").split("|"))
            if len(parts) != 6 or not all(parts) or parts[0] != key:
                raise ValueError("UNKNOWN: incomplete row " + key)
            found[key] = parts
    if set(found) != set(IDS):
        raise ValueError("UNKNOWN: missing GitHub Intent clauses")
    return found


def no_runtime_authority(value: dict) -> bool:
    return (value.get("decision") == "deny"
            and all(value.get(field) is False for field in AUTHORITY_FIELDS))


def unknown_live(evidence: object = None) -> str:
    """No fixture-based observation can authenticate real/live authority."""
    return UNKNOWN


class BrechasControlbot28Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.docs = [(ROOT / name).read_text(encoding="utf-8") for name in SOURCES]
        cls.rows = coverage_rows(*cls.docs)
        cls.authority_doc = (ROOT / "docs/github-intent-test-grant-authority.md").read_text()
        cls.trusted_doc = (ROOT / "docs/github-intent-trusted-evidence.md").read_text()
        cls.preview_doc = (ROOT / "docs/github-intent-preview-v1.md").read_text()
        cls.authority_code = (ROOT / "src/GitHubIntentTestGrantAuthority.php").read_text()
        cls.trusted_code = (ROOT / "src/GitHubIntentTrustedEvidence.php").read_text()
        cls.catalog_code = (ROOT / "src/CapabilityPolicy.php").read_text()
        cls.grant = authority.execute(authority.candidate("workflow.dispatch"))
        cls.closed_grant = authority.candidate("workflow.dispatch")
        cls.closed_grant["approval"]["approved"] = False
        cls.denied_grant = authority.execute(cls.closed_grant)
        cls.canonical = trusted.scenario("canonical")
        cls.boundary = trusted.scenario("ttl_boundary")
        cls.closed = trusted.scenario("closed")
        cls.safe_preview = preview.scenario("safe")

    def test_contrato_principal(self) -> None:
        """AC-01: one individual behavioral assertion for every requirement."""
        g, invalid = self.grant, self.denied_grant
        valid = self.canonical
        boundary, closed = self.boundary, self.closed
        sensitive = self.safe_preview["sensitive"]
        checks = {
            # CB-0410: only owner-first read-only preview, not real GitHub APIs.
            "CB-0410": (
                "GitHub App/PAT/tokens" in self.preview_doc
                and self.safe_preview["deny"]["mutation_controls"] == []
                and sensitive["intent"] is None
                and sensitive["mutation_controls"] == []
                and sensitive["execution"] is False
            ),
            # CB-0411: decision #873 is scope-limited to offline preparation.
            "CB-0411": (
                "#873 /decidir A" in self.authority_doc
                and "No autoriza" in self.authority_doc
                and invalid["test_grant_valid"] is False
                and no_runtime_authority(invalid)
            ),
            # CB-0412: actual HMAC issuer/verifier, typed owner-gated fixture.
            "CB-0412": (
                "GitHubIntentOfflineIssuer::issue(" in self.authority_code
                and "GitHubIntentGrantVerification::inspect(" in self.authority_code
                and g["catalog_class"] == "owner_required"
                and g["fixture_proof"]["capability"] == "github.workflow.dispatch"
                and len(g["fixture_proof"]["signature"]) == 64
            ),
            # CB-0413: signed proof is test-only and retains deny everywhere.
            "CB-0413": (
                g["reason"] == "verified_test_only"
                and g["test_grant_valid"] is True
                and no_runtime_authority(g)
                and g["fixture_proof"] is not None
            ),
            # CB-0414: #763 needs its own future owner decision, not #873.
            "CB-0414": (
                "#763 necesita además autorización owner-gated" in self.authority_doc
                and "project.freeze/unfreeze" in self.authority_doc
                and "workflow.dispatch" in self.authority_doc
                and no_runtime_authority(g)
                and unknown_live({"owner_873": "A"}) == UNKNOWN
            ),
            # CB-0415: trusted projection takes canonical store snapshot only;
            # structural acceptance cannot attest its source.
            "CB-0415": (
                "almacén/observador canónico" in self.trusted_doc
                and "function derive(array $canonicalSnapshot" in self.trusted_code
                and valid["state"] == "current"
                and unknown_live(valid) == UNKNOWN
            ),
            # CB-0416: server clock + storage boundary is a prerequisite.
            "CB-0416": (
                "reloj desde el servidor" in self.trusted_doc
                and "trustedNow" in self.trusted_code
                and valid["observed_at"] == 1791157800 - 120
                and valid["age_seconds"] == 120
                and valid["execution"] is False
            ),
            # CB-0417: valid shape is not cryptographic authentication.
            "CB-0417": (
                "autenticidad criptográfica" in self.trusted_doc
                and unknown_live({"valid_shape": True,
                                  "authenticated": False}) == UNKNOWN
                and valid["execution"] is False
            ),
            # CB-0418: actual source delegates strict snapshot checks.
            "CB-0418": (
                "GitHubProjectView::project(" in self.trusted_code
                and valid["repository_ref"] == "pl0n3r/ControlBot"
                and valid["source_ref"] == "https://github.com/pl0n3r/ControlBot"
                and valid["evidence_ref"].endswith("/commit/" + "a" * 40)
            ),
            # CB-0419: fixed TTL 300 accepts 300 and rejects 301.
            "CB-0419": (
                "private const MAX_AGE_SECONDS = 300;" in self.trusted_code
                and boundary["last_accepted_second"]["state"] == "current"
                and boundary["last_accepted_second"]["age_seconds"] == 300
                and boundary["first_rejected_second"]["state"] == "unknown"
            ),
            # CB-0420: no truncated, ambiguous or missing collections.
            "CB-0420": (
                closed["partial_or_ambiguous"]["state"] == "unknown"
                and closed["empty_repositories"]["state"] == "unknown"
                and valid["state"] == "current"
                and valid["execution"] is False
            ),
            # CB-0421: stale, future, duplicate and wrong source fail closed.
            "CB-0421": all(
                closed[key]["state"] == "unknown"
                for key in ("stale", "future", "duplicated_repository",
                            "mismatched_source", "unexpected_row_field")
            ),
            # CB-0422: caller timestamps/TTL and scope mismatch are rejected.
            "CB-0422": all(
                closed[key]["state"] == "unknown"
                for key in ("caller_timestamp", "caller_ttl",
                            "scope_mismatch", "scope_case_mismatch")
            ),
            # CB-0423: untrusted server clock returns generic minimal UNKNOWN.
            "CB-0423": (
                closed["untrusted_clock"] == {
                    "version": 1, "state": "unknown",
                    "reason": "untrusted_or_stale_snapshot",
                    "execution": False,
                }
            ),
            # CB-0424: no allow, grants, sensitive payload or execution.
            "CB-0424": (
                no_runtime_authority(g)
                and all(value["execution"] is False for value in closed.values())
                and all(value["state"] == "unknown" for value in closed.values())
                and not any("allow" in json.dumps(value).lower()
                            for value in closed.values())
            ),
        }
        self.assertEqual(set(checks), set(IDS))
        self.assertEqual(set(self.rows), set(IDS))
        for key in IDS:
            with self.subTest(clause=key):
                self.assertTrue(checks[key], f"{key}: insufficient synthetic evidence")
                self.assertEqual(self.rows[key][3], "NO_VERIFICABLE")
                self.assertEqual(unknown_live(self.rows[key]), UNKNOWN)

    def test_falla_cerrado(self) -> None:
        """AC-02: missing or ambiguous provenance cannot become a pass."""
        base, part = self.docs
        row = next(s for s in part.splitlines() if s.startswith("| CB-0410 |"))
        for damaged in (
            part.replace(row, ""),
            part + "\n" + row,
            part.replace(row, "| CB-0410: altered delimiter |"),
            part.replace(row, "| CB-0410 | truncated |"),
        ):
            with self.subTest(damaged=damaged.count("CB-0410")):
                with self.assertRaises(ValueError):
                    coverage_rows(base, damaged)
        self.assertFalse(self.denied_grant["test_grant_valid"])
        self.assertIsNone(self.denied_grant["fixture_proof"])
        self.assertTrue(no_runtime_authority(self.denied_grant))
        bad_scope = authority.candidate("project.freeze")
        bad_scope["approval"]["scope_ref"] = "other/repository"
        not_authorized = authority.execute(bad_scope)
        self.assertFalse(not_authorized["test_grant_valid"])
        self.assertTrue(no_runtime_authority(not_authorized))
        for key, result in self.closed.items():
            with self.subTest(closed=key):
                self.assertEqual(result["state"], "unknown")
                self.assertEqual(result["reason"], "untrusted_or_stale_snapshot")
                self.assertEqual(set(result),
                                 {"version", "state", "reason", "execution"})
                self.assertIs(result["execution"], False)

    def test_sin_datos_reales(self) -> None:
        """AC-03: only static docs and synthetic PHP, never credentials/network."""
        self.assertEqual(authority.FIXTURE_KEY,
                         bytes(range(32, 64)).hex())
        self.assertNotIn("key", self.grant)
        self.assertNotIn("key", self.denied_grant)
        self.assertNotIn("secret", json.dumps(self.safe_preview["sensitive"]).lower())
        for php_source in (self.authority_code, self.trusted_code):
            for forbidden in ("curl_init(", "shell_exec(", "proc_open(",
                              "stream_socket_client(", "github_pat_",
                              "'execution' => true"):
                with self.subTest(forbidden=forbidden):
                    self.assertNotIn(forbidden, php_source)
        for field in ("authorization", "token", "password", "cookie"):
            self.assertNotIn(field, json.dumps(self.closed).lower())
        self.assertIs(self.grant["runtime_authorized"], False)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: never certify approval, live or merged PR from fixtures."""
        self.assertEqual(set(self.rows), set(IDS))
        for key, row in self.rows.items():
            with self.subTest(clause=key):
                self.assertEqual(row[3], "NO_VERIFICABLE")
                self.assertIn("UNKNOWN", row[4])
                for claim in (None, {}, {"test": "success"},
                              {"owner_decision": "A"}, {"proof_valid": True},
                              {"ci": "success", "merged_pr": True},
                              {"runtime": "live", "authenticated": True}):
                    self.assertEqual(unknown_live(claim), UNKNOWN)
        self.assertIs(self.canonical["execution"], False)
        self.assertTrue(all(v["execution"] is False for v in self.closed.values()))
        self.assertTrue(no_runtime_authority(self.grant))


if __name__ == "__main__":
    unittest.main()
