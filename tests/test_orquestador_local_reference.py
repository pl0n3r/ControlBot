import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TOOL = ROOT / "tools" / "orquestador-local"
EXPECTED = (
    "orq.py", "index.html", "grafo_orq.py", "grafo.html", "vigia.sh",
    "CONTRATO-EXTENSION.md", "config.example.json", "README.md", ".gitignore",
)
STATE_FILES = (
    "acciones.log", "af_logs.jsonl", "agent_key.txt", "assigned.json",
    "agent_blocked.json", "planner_state.json", "config.json", "__pycache__/",
)
SECRET = re.compile(r"ghp_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}|BEGIN [A-Z ]*PRIVATE KEY|AKIA[0-9A-Z]{16}")


class OrquestadorLocalReferenceTests(unittest.TestCase):
    def test_reference_tool_is_versioned_and_clean(self):
        for name in EXPECTED:
            with self.subTest(archivo=name):
                self.assertTrue((TOOL / name).is_file(), name)
        for name in ("orq.py", "grafo_orq.py"):
            compile((TOOL / name).read_text(encoding="utf-8"), name, "exec")
        for name in EXPECTED:
            text = (TOOL / name).read_text(encoding="utf-8")
            with self.subTest(limpio=name):
                self.assertIsNone(SECRET.search(text), "secreto en " + name)
                self.assertNotIn("/Users/", text)
                self.assertIsNone(re.search(r"\b192\.168\.\d+\.\d+\b", text))
        ignore = (TOOL / ".gitignore").read_text(encoding="utf-8").splitlines()
        for state in STATE_FILES:
            with self.subTest(ignorado=state):
                self.assertIn(state, ignore)
        for state in ("agent_key.txt", "acciones.log", "assigned.json", "config.json"):
            self.assertFalse((TOOL / state).exists(), state + " no debe versionarse")
        readme = (TOOL / "README.md").read_text(encoding="utf-8")
        self.assertIn("referencia", readme)
        self.assertIn("no se despliega", readme)


if __name__ == "__main__":
    unittest.main()
