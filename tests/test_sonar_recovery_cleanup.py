import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
RECOVERY_WORKFLOW = ROOT / ".github" / "workflows" / "sonar-main-recovery.yml"
OBSOLETE_TEST = ROOT / "tests" / "test_sonar_main_recovery_workflow.py"


class SonarRecoveryCleanupTests(unittest.TestCase):
    def test_recovery_workflow_is_absent(self):
        self.assertFalse(
            RECOVERY_WORKFLOW.exists(),
            "El workflow experimental de recovery no debe reintroducirse.",
        )

    def test_obsolete_workflow_test_is_absent(self):
        self.assertFalse(
            OBSOLETE_TEST.exists(),
            "La prueba del workflow experimental no debe reintroducirse.",
        )

    def test_only_cleanup_guardrail_remains(self):
        self.assertTrue(Path(__file__).is_file())
        self.assertFalse(RECOVERY_WORKFLOW.exists())
        self.assertFalse(OBSOLETE_TEST.exists())


if __name__ == "__main__":
    unittest.main()
