import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str):
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "infrastructure_inventory_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)


class InfrastructureInventoryTests(unittest.TestCase):
    def test_contract_models_multiple_providers_and_typed_resources_without_credentials(self):
        providers = scenario("multi-provider")
        self.assertEqual(
            [row["provider_id"] for row in providers["providers"]],
            ["provider-backup", "provider-primary"],
        )
        self.assertEqual(len(providers["accounts"]), 2)
        self.assertNotIn("password", json.dumps(providers).lower())
        self.assertNotIn("token", json.dumps(providers).lower())

        resources = scenario("typed-resources")
        self.assertEqual(
            {row["kind"] for row in resources},
            {
                "environment", "service", "database", "storage",
                "dns", "certificate", "backup", "network",
            },
        )

    def test_provider_contract_is_capability_based_and_unknown_values_fail_closed(self):
        providers = scenario("multi-provider")
        primary = next(
            row for row in providers["providers"]
            if row["provider_id"] == "provider-primary"
        )
        self.assertEqual(primary["kind"], "hosting")
        self.assertEqual(primary["vendor"], "vendor-one")
        self.assertEqual(
            [row["capability"] for row in primary["capabilities"]],
            ["health.read", "inventory.read"],
        )
        for case in (
            "unknown-provider-kind",
            "unknown-capability",
            "unknown-scope",
            "unknown-resource-kind",
            "account-provider-missing",
        ):
            with self.subTest(case=case):
                self.assertTrue(scenario(case)["blocked"])

    def test_resource_relationships_and_release_evidence_are_deterministic(self):
        data = scenario("deterministic")
        self.assertEqual(data["first"], data["second"])
        service = next(
            row for row in data["first"]
            if row["resource_id"] == "service-web"
        )
        self.assertEqual(
            service["project_ref"],
            "controlbot:project/project-controlbot",
        )
        self.assertEqual(
            service["environment_ref"],
            "controlbot:environment/controlbot-prod",
        )
        self.assertEqual(service["release_evidence"]["sha"], "b" * 40)
        self.assertEqual(
            service["release_evidence"]["source_ref"],
            "https://github.com/pl0n3r/ControlBot",
        )
        self.assertTrue(scenario("bad-release-sha")["blocked"])

    def test_reference_validation_accepts_normal_github_urls_and_rejects_sensitive_urls(self):
        data = scenario("github-reference-guard")
        self.assertEqual(
            data["valid"],
            "https://github.com/pl0n3r/ControlBot/issues/258",
        )
        self.assertTrue(all(data["sensitive_urls"]))
        self.assertTrue(data["controlbot_sensitive"])

    def test_sensitive_reference_guard_lives_in_common_provider_boundary(self):
        data = scenario("github-reference-guard")
        self.assertTrue(data["provider_sensitive"])
        self.assertTrue(data["resource_sensitive"])
        resource_source = (
            ROOT / "src" / "InfrastructureResource.php"
        ).read_text(encoding="utf-8")
        self.assertNotIn("SENSITIVE_PATTERN", resource_source)

    def test_invalid_duplicate_or_sensitive_inventory_fails_closed(self):
        for case in (
            "duplicate-resource",
            "duplicate-provider",
            "unknown-field",
            "invalid-ref",
            "provider-secret-ref",
            "secret-ref",
            "secret-id",
        ):
            with self.subTest(case=case):
                self.assertTrue(scenario(case)["blocked"])


if __name__ == "__main__":
    unittest.main()
