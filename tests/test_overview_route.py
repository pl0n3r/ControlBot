import json
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def status_for(path: str) -> int:
    code = (
        "require 'src/FactoryOrchestratorLiveEndpoint.php';"
        "$cfg=['owner_login'=>'USUARIO','cache_path'=>$argv[2],'ttl_seconds'=>60,'stale_seconds'=>600,"
        "'refresh_budget_seconds'=>1];"
        "$r=ControlBot\\Business\\FactoryOrchestratorLiveEndpoint::handle("
        "['method'=>'GET','path'=>$argv[1],'remote_user'=>'USUARIO'],$cfg,time(),"
        "static function(){ throw new RuntimeException('no snapshot'); });"
        "echo json_encode($r['status']);"
    )
    with tempfile.TemporaryDirectory() as d:
        out = subprocess.run(["php", "-r", code, path, str(Path(d) / "cache.json")], cwd=ROOT,
                             capture_output=True, text=True, timeout=20)
    if out.returncode != 0:
        raise AssertionError(out.stderr[:300])
    return json.loads(out.stdout)


class OverviewRouteTests(unittest.TestCase):
    def test_overview_is_a_known_owner_route_unknown_paths_stay_404(self) -> None:
        # /overview is where the login sends the owner: it must not be a 404.
        self.assertNotEqual(404, status_for("/overview"))
        self.assertNotEqual(404, status_for("/"))
        self.assertEqual(404, status_for("/no-existe"))


if __name__ == "__main__":
    unittest.main()
