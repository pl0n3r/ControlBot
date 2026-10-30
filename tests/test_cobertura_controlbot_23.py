#!/usr/bin/env python3
"""#1206: real PHP behavior for 15 GitHub boundary clauses; three are policy.

Offline only: checked-in synthetic PHP scenarios, no GitHub API access,
secrets, live authority, irreversible action, deploy or go-live.
"""
from __future__ import annotations

import importlib.util
import sys
import unittest
from functools import lru_cache
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOCUMENTS = {
    "docs/github-control-plane-readonly-v1.md": ("0363", "0364", "0365"),
    "docs/github-control-plane-wiring.md": ("0366",),
    "docs/github-http-transport.md": ("0368", "0369", "0370"),
    "docs/github-intent-authority-requirements.md": (
        "0371", "0372", "0373", "0374",
    ),
    "docs/github-intent-envelope-v1.md": ("0375", "0376"),
    "docs/github-intent-execution-gateway.md": (
        "0377", "0378", "0379", "0380", "0381",
    ),
}
SOURCE = {"CB-" + i: doc for doc, ids in DOCUMENTS.items() for i in ids}
NON_OFFLINE = {
    "CB-0363": ("C", "Historical owner decision separating read-only visibility and privileged actions"),
    "CB-0377": ("C", "Owner choice A authorizes synthetic fixtures but cannot grant runtime authority"),
    "CB-0381": ("C", "Rollback and future approval roadmap require human and production evidence"),
}
# Existing suites execute and assert actual production PHP through synthetic
# checked-in scenarios. Running a method is mandatory, not merely checking
# the file, documenting a gap, or accepting a skipped test.
CASES = {
    "0364": ("test_github_project_snapshot.py", "GitHubProjectSnapshotTests",
             "test_snapshot_uses_project_repositories_and_get_only_allowlisted_routes"),
    "0365": ("test_github_project_snapshot.py", "GitHubProjectSnapshotTests",
             "test_lists_are_bounded_and_release_absence_is_explicit"),
    "0366": ("test_github_public_entrypoint.py", "GitHubPublicEntrypointTests",
             "test_invalid_request_config_and_projection_contracts_fail_closed"),
    "0368": ("test_github_http_transport.py", "GithubHttpTransportTests",
             "test_host_path_allowlist_rejects_before_transport"),
    "0369": ("test_github_http_transport.py", "GithubHttpTransportTests",
             "test_tls_redirect_and_oversize_fail_closed"),
    "0370": ("test_github_http_transport.py", "GithubHttpTransportTests",
             "test_local_fake_without_external_network"),
    "0371": ("test_github_intent_authority_requirements.py",
             "GitHubIntentAuthorityRequirementsTests",
             "test_observation_is_not_a_grant_or_approval"),
    "0372": ("test_github_intent_authority_requirements.py",
             "GitHubIntentAuthorityRequirementsTests",
             "test_each_known_intent_maps_to_exact_capability_scope_and_approval_class_without_granting"),
    "0373": ("test_github_intent_authority_requirements.py",
             "GitHubIntentAuthorityRequirementsTests",
             "test_unknown_type_extra_authority_or_scope_expansion_fails_closed"),
    "0374": ("test_github_intent_policy.py", "GitHubIntentPolicyTests",
             "test_trusted_evidence_without_real_grant_or_approval_never_authorizes_or_calls_adapter"),
    "0375": ("test_github_intent_envelope.py", "GitHubIntentEnvelopeTests",
             "test_known_intent_types_use_closed_params_and_preserve_project_repo_idempotency_and_evidence_refs"),
    "0376": ("test_github_intent_envelope.py", "GitHubIntentEnvelopeTests",
             "test_unknown_type_shell_git_freeform_secret_or_extra_param_fails_closed"),
    "0378": ("test_github_intent_execution_gateway.py",
             "GitHubIntentExecutionGatewayTests",
             "test_scope_expiration_and_revocation_fail_closed"),
    "0379": ("test_github_intent_execution_gateway.py",
             "GitHubIntentExecutionGatewayTests",
             "test_fake_idempotency_audit_and_no_production_side_effects"),
    "0380": ("test_github_intent_execution_gateway.py",
             "GitHubIntentExecutionGatewayTests",
             "test_ambiguous_or_failed_response_never_reports_success"),
}
BEHAVIOR = {"CB-" + key: value for key, value in CASES.items()}
NEGATIVE = (
    ("test_github_project_snapshot.py", "GitHubProjectSnapshotTests",
     "test_malformed_or_ambiguous_github_state_fails_closed"),
    ("test_github_public_entrypoint.py", "GitHubPublicEntrypointTests",
     "test_missing_stale_or_invalid_projection_is_unknown_without_github_io"),
    ("test_github_http_transport.py", "GithubHttpTransportTests",
     "test_secret_never_appears_in_error_or_evidence"),
    ("test_github_intent_execution_gateway.py",
     "GitHubIntentExecutionGatewayTests",
     "test_missing_or_untrusted_grant_denies_without_sender_call"),
)
ALL_IDS = set(SOURCE)


@lru_cache(maxsize=32)
def real_php_regression(file: str, cls_name: str, method: str) -> bool:
    """Use unittest to execute real PHP-backed checks, deny skip/error/failure."""
    if (file, cls_name, method) not in set(BEHAVIOR.values()) | set(NEGATIVE):
        raise AssertionError("UNKNOWN: test not allowlisted")
    source = ROOT / "tests" / file
    if not source.is_file() or source.is_symlink():
        raise AssertionError("UNKNOWN: PHP-backed suite unavailable")
    module_name = "_cb1206_" + source.stem
    spec = importlib.util.spec_from_file_location(module_name, source)
    if spec is None or spec.loader is None:
        raise AssertionError("UNKNOWN: cannot import real PHP regression")
    module = importlib.util.module_from_spec(spec)
    sys.modules[module_name] = module
    try:
        spec.loader.exec_module(module)
        cls = getattr(module, cls_name, None)
        if (not isinstance(cls, type)
                or not issubclass(cls, unittest.TestCase)
                or method not in unittest.defaultTestLoader.getTestCaseNames(cls)):
            raise AssertionError("UNKNOWN: real regression missing")
        result = unittest.TestResult()
        cls(method).run(result)
        if (result.testsRun != 1 or not result.wasSuccessful() or result.skipped
                or result.expectedFailures or result.unexpectedSuccesses):
            raise AssertionError(
                "UNKNOWN: PHP regression not green: "
                + f"{file}::{method}: "
                + str((result.failures + result.errors)[:1])
                + ", skipped=" + str(result.skipped)
            )
    finally:
        sys.modules.pop(module_name, None)
    return True


class CoberturaControlbot23Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: 15 real PHP behaviors; 3 policy-only lines are not passes."""
        self.assertEqual(18, len(ALL_IDS))
        self.assertEqual(ALL_IDS, set(BEHAVIOR) | set(NON_OFFLINE))
        self.assertFalse(set(BEHAVIOR) & set(NON_OFFLINE))
        for key, doc in SOURCE.items():
            with self.subTest(requirement=key):
                self.assertTrue((ROOT / doc).is_file())
        for key, contract in BEHAVIOR.items():
            with self.subTest(real_behavior=key):
                self.assertTrue(real_php_regression(*contract))
        for key, (kind, reason) in NON_OFFLINE.items():
            with self.subTest(documentary=key):
                self.assertEqual("C", kind)
                self.assertGreater(len(reason), 35)

    def test_falla_cerrado(self) -> None:
        """AC-02: untrusted scopes, TLS and GitHub grants fail closed."""
        for test in NEGATIVE:
            with self.subTest(negative=test[0]):
                self.assertTrue(real_php_regression(*test))
        with self.assertRaises(AssertionError):
            real_php_regression("test_github_http_transport.py",
                                "GithubHttpTransportTests", "unknown_unsafe_test")

    def test_sin_datos_reales(self) -> None:
        """AC-03: only offline fixture scenarios, never runtime GitHub grants."""
        self.assertEqual(15, len(BEHAVIOR))
        self.assertTrue(real_php_regression(*BEHAVIOR["CB-0379"]))
        self.assertTrue(real_php_regression(*BEHAVIOR["CB-0374"]))
        for contract in BEHAVIOR.values():
            with self.subTest(fixture=contract[0]):
                self.assertTrue(contract[0].startswith("test_github_"))
                self.assertTrue((ROOT / "tests" / contract[0]).is_file())
        own = Path(__file__).read_text(encoding="utf-8")
        for marker in (
            "-----BEGIN " + "PRIVATE KEY-----",
            "github" + "_pat_", "GITHUB" + "_TOKEN=", "gh" + "p_",
        ):
            self.assertNotIn(marker, own)

    def test_validar_exigido(self) -> None:
        """AC-04: CI discovers this test; green exact-SHA comes from GitHub."""
        workflow = (ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        generator = (ROOT / "scripts/generate_coverage.py").read_text(encoding="utf-8")
        for text in ("name: Validar", "pull_request:",
                     "github.event.pull_request.head.sha",
                     "scripts/generate_coverage.py"):
            self.assertIn(text, workflow)
        for text in ('"unittest"', '"discover"', '"test_*.py"'):
            self.assertIn(text, generator)


if __name__ == "__main__":
    unittest.main()
