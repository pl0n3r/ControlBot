import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github" / "workflows" / "roles.yml"
REUSABLE = "pl0n3r/factory/.github/workflows/roles.yml@v1"


def jobs(text: str) -> dict[str, str]:
    """Bloques de cada job de primer nivel (clave -> texto) sin depender de un parser YAML."""
    body = text.split("\njobs:\n", 1)[1]
    parts = re.split(r"\n(?=  [A-Za-z0-9_-]+:\n)", "\n" + body)
    return {m.group(1): part for part in parts if (m := re.match(r"\s*([A-Za-z0-9_-]+):\n", part))}


class RolesWorkflowChecksPermissionTests(unittest.TestCase):
    def test_every_roles_job_grants_checks_read(self) -> None:
        blocks = jobs(WORKFLOW.read_text(encoding="utf-8"))
        callers = {name: block for name, block in blocks.items() if REUSABLE in block}
        self.assertEqual(sorted(callers), ["sugerir-issue", "sync", "validar-pr"])
        for name, block in callers.items():
            with self.subTest(job=name):
                perms = dict(re.findall(r"^      ([a-z-]+): (read|write|none)$", block.split("uses:", 1)[0], re.M))
                self.assertEqual(perms.get("checks"), "read")
                # Nada más se amplía: solo lectura de contents/pull-requests/checks e issues:write ya existente.
                self.assertEqual(perms.get("contents"), "read")
                self.assertEqual(perms.get("pull-requests"), "read")
                self.assertEqual(perms.get("issues"), "write")
                self.assertEqual(set(perms), {"contents", "issues", "pull-requests", "checks"})


if __name__ == "__main__":
    unittest.main()
