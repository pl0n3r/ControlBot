import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def scenario(name):
    run=subprocess.run(
        ["php",str(ROOT/"tests"/"capacity_ui_scenarios.php"),name],
        cwd=ROOT,check=True,text=True,capture_output=True,
    )
    return json.loads(run.stdout)


class CapacityUiTests(unittest.TestCase):
    def test_ui_separates_open_healthy_idle_and_dispatchable_metrics(self):
        html=scenario("metrics")["html"]
        self.assertIn('data-metric="open-sessions"',html)
        self.assertIn('data-metric="healthy-sessions"',html)
        self.assertIn('data-metric="idle-capacity"',html)
        self.assertIn('data-metric="dispatchable-capacity"',html)
        self.assertIn(">3</strong>",html)
        self.assertIn(">2</strong>",html)
        self.assertIn(">1</strong>",html)
        self.assertIn('data-provider="chatgpt-web"',html)

    def test_mixed_degraded_and_unknown_preserve_trusted_capacity_without_rendering_healthy(self):
        data=scenario("mixed")
        self.assertIn('data-capacity-state="degraded"',data["degraded"])
        self.assertIn('data-capacity-state="unknown"',data["unknown"])
        for html in data.values():
            self.assertIn('data-metric="idle-capacity"><span>Idle capacity</span><strong>2',html)
            self.assertIn('data-metric="dispatchable-capacity"><span>Dispatchable capacity</span><strong>1',html)
            self.assertIn('data-provider="chatgpt-web"',html)
            self.assertIn('data-provider="claude-web"',html)

    def test_rate_limit_and_login_are_visible_non_capacity_states(self):
        html=scenario("degraded")["html"]
        self.assertIn("rate limited",html)
        self.assertIn("login required",html)
        self.assertIn('data-metric="idle-capacity"><span>Idle capacity</span><strong>0',html)
        self.assertIn('data-metric="dispatchable-capacity"><span>Dispatchable capacity</span><strong>0',html)

    def test_ui_does_not_derive_capacity_from_declared_plan(self):
        self.assertTrue(scenario("declared")["rejected"])
        authority=scenario("authority")
        self.assertTrue(authority["mismatch"])
        self.assertTrue(authority["overflow"])

    def test_capacity_view_supports_mobile_and_desktop(self):
        html=scenario("responsive")["html"]
        self.assertIn("@media(max-width:640px)",html)
        self.assertIn("grid-template-columns:repeat(4",html)
        self.assertIn("grid-template-columns:repeat(2",html)
        self.assertIn("capacity-columns",html)

    def test_loading_empty_and_error_states_are_explicit(self):
        data=scenario("states")
        for state in ("loading","empty","error"):
            self.assertIn(f'data-state="{state}"',data[state])
        self.assertIn("retry later",data["error"])


if __name__=="__main__":
    unittest.main()
