#!/usr/bin/env python3
"""ControlBot #1243: regression proofs for 16 RequirementIntake rows.

Every proof executes the checked-in PHP production classes through deterministic
synthetic scenario scripts or a negative-path PHP probe. No matrix access, network,
real users, secrets, database, deployment or privileged owner operations.
"""
from __future__ import annotations

from functools import lru_cache
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
ROWS = frozenset(f"CB-{i:04d}" for i in range(997, 1013))
SCRIPTS = {
    "intake": "requirement_intake_scenarios.php",
    "view": "requirement_intake_decision_ui_scenarios.php",
    "material": "requirement_materialization_scenarios.php",
}
MATERIAL_CASES = frozenset(
    {"idempotent", "rejected", "existing", "project-choice", "receipt"}
)

# Reuses the existing synthetic PHP fixture entrypoint, which loads the real
# classes and the in-memory gateway, then exercises additional negative paths.
PHP_PROBE = r"""
$argv=['probe','idempotent'];
ob_start();
require 'tests/requirement_materialization_scenarios.php';
ob_end_clean();
use ControlBot\Requirements\RequirementIntakeDecisionUi;
use ControlBot\Requirements\RequirementMaterializer;
use ControlBot\Approvals\OwnerContext;

$p=proposal('none');
$v=RequirementIntakeDecisionUi::project($p);
$a=approval($p,$v,resolution());
$badProposal=$p;
$badProposal['fingerprint']=str_repeat('f',64);
$badView=$v;
$badView['materialization_diff']['issues'][0]['title']='Other issue';
$misbound=$a;
$misbound['proposal_ref']='epic-proposal:'.str_repeat('0',40);
$incomplete=$a;
$incomplete['project_resolution']['slug']='';
$noOwner=new OwnerContext('pl0n3r',false,1000);
$stale=new OwnerContext('pl0n3r',true,1);
$g=new FakeRequirementGateway();
$m=new RequirementMaterializer($g);

$mutations=fn()=>array_sum($g->counts);
$cases=[
 'invalid_proposal'=>blocked(fn()=>RequirementIntakeDecisionUi::project($badProposal)),
 'tampered_diff'=>blocked(fn()=>$m->materialize($p,$badView,$a,new OwnerContext('pl0n3r',true,1000),1100)),
 'revise'=>blocked(fn()=>$m->materialize($p,$v,approval($p,$v,resolution(),'revise'),new OwnerContext('pl0n3r',true,1000),1100)),
 'reject'=>blocked(fn()=>$m->materialize($p,$v,approval($p,$v,resolution(),'reject'),new OwnerContext('pl0n3r',true,1000),1100)),
 'no_owner'=>blocked(fn()=>$m->materialize($p,$v,$a,$noOwner,1100)),
 'stale_owner'=>blocked(fn()=>$m->materialize($p,$v,$a,$stale,1100)),
 'misbound_approval'=>blocked(fn()=>$m->materialize($p,$v,$misbound,new OwnerContext('pl0n3r',true,1000),1100)),
 'incomplete_project'=>blocked(fn()=>$m->materialize($p,$v,$incomplete,new OwnerContext('pl0n3r',true,1000),1100)),
];
$ambiguous=proposal('ambiguous');
$ambView=RequirementIntakeDecisionUi::project($ambiguous);
echo json_encode([
 'cases'=>$cases,
 'mutations'=>$mutations(),
 'ambiguous_match'=>$ambiguous['project_match'],
 'ambiguous_operation'=>$ambView['materialization_diff']['project']['operation'],
],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
"""


def _execute(args: list[str]) -> dict:
    """Execute source PHP, fail closed for missing CLI/fixture or bad result."""
    binary = shutil.which("php")
    if binary is None:
        raise AssertionError("UNKNOWN: PHP CLI unavailable")
    env = {"PATH": os.environ.get("PATH", "/usr/bin:/bin"), "LANG": "C"}
    # Retain only sanctioned CI coverage instrumentation; never pass API tokens.
    for key in (
        "CONTROLBOT_REAL_PHP", "CONTROLBOT_PHP_COVERAGE_BOOTSTRAP",
        "CONTROLBOT_PHP_COVERAGE_DIR", "CONTROLBOT_REPO_ROOT", "XDEBUG_MODE",
    ):
        if key in os.environ:
            env[key] = os.environ[key]
    with tempfile.TemporaryDirectory(prefix="controlbot-1243-") as home:
        env["HOME"] = home
        run = subprocess.run(
            [binary, "-d", "allow_url_fopen=0", "-d", "enable_dl=0",
             "-d", "disable_functions=curl_exec,curl_multi_exec,fsockopen,"
             "pfsockopen,stream_socket_client,shell_exec,system,exec,passthru,"
             "proc_open,popen", *args],
            cwd=ROOT, env=env, capture_output=True, text=True,
            timeout=60, check=False,
        )
    if run.returncode != 0 or run.stderr.strip():
        raise AssertionError(
            f"UNKNOWN: PHP failed (exit={run.returncode}): {run.stderr[-600:]}"
        )
    try:
        result = json.loads(run.stdout)
    except json.JSONDecodeError as exc:
        raise AssertionError("UNKNOWN: PHP returned malformed JSON") from exc
    if not isinstance(result, dict):
        raise AssertionError("UNKNOWN: PHP output is not an object")
    return result


@lru_cache(maxsize=12)
def scenario(name: str, mode: str = "") -> dict:
    if name == "probe":
        if mode:
            raise AssertionError("UNKNOWN: unsupported probe mode")
        return _execute(["-r", PHP_PROBE])
    if name not in SCRIPTS or (name == "material" and mode not in MATERIAL_CASES):
        raise AssertionError("UNKNOWN: unsupported test scenario")
    if name != "material" and mode:
        raise AssertionError("UNKNOWN: unsupported fixture mode")
    path = ROOT / "tests" / SCRIPTS[name]
    if not path.is_file():
        raise AssertionError("UNKNOWN: checked-in PHP fixture missing")
    return _execute([str(path), *([mode] if mode else [])])


class CoberturaControlbot60Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: every CB row has a distinct runtime predicate over real PHP."""
        intake = scenario("intake")
        ui = scenario("view")
        idempotent = scenario("material", "idempotent")
        rejected = scenario("material", "rejected")
        existing = scenario("material", "existing")
        choice = scenario("material", "project-choice")
        receipt = scenario("material", "receipt")
        probe = scenario("probe")

        proposal, view = ui["proposal"], ui["view"]
        free = intake["free"]
        secret = intake["secret_draft"]
        ops = view["decision_options"]
        first = idempotent["first"]
        keys = first["idempotency_keys"]
        r = receipt["receipt"]
        checks = {
            "CB-0997": (
                view == ui["replay"] and view["execution"] is False
                and view["proposal_ref"] == proposal["proposal_ref"]
                and view["proposal_fingerprint"] == proposal["fingerprint"]
                and view["provenance"] == {"draft_ref": proposal["draft_ref"]}
            ),
            "CB-0998": (
                view["summary"]["problem"] == proposal["problem"]
                and view["summary"]["user"] == proposal["user"]
                and view["summary"]["objectives"] == proposal["objectives"]
                and view["summary"]["out_of_scope"] == proposal["out_of_scope"]
                and "objectives" in ui["unknown_view"]["summary"]["unknown_fields"]
                and ui["unknown_view"]["summary"]["objectives"]["status"] == "unknown"
            ),
            "CB-0999": (
                [o["code"] for o in ops] == ["approve", "revise", "reject"]
                and [o["intent"] for o in ops] == [
                    "allow_later_materialization", "request_changes", "decline_proposal"
                ]
                and all(o["execution"] is False for o in ops)
            ),
            "CB-1000": (
                probe["cases"]["invalid_proposal"]
                and not any(s in json.dumps(ui["secret_view"]).lower()
                            for s in ("hunter2", "supersecrettoken",
                                      "abcdefghijklmnopqrstuvwxyz"))
                and ui["secret_view"]["execution"] is False
            ),
            "CB-1001": (
                free["problem"] == {
                    "status": "known", "value": "Orders are tracked manually"}
                and free["user"] == {"status": "known", "value": "operations team"}
                and free["client"] == {"status": "unknown", "value": None}
                and free["budget"]["status"] == "unknown"
            ),
            "CB-1002": (
                intake["text"]["fingerprint"] == intake["transcript"]["fingerprint"]
                and intake["text"]["draft_ref"] == intake["transcript"]["draft_ref"]
                and intake["text"]["problem"] == intake["transcript"]["problem"]
                and (intake["text"]["source_kind"],
                     intake["transcript"]["source_kind"]) == ("text", "transcript")
                and "raw_audio" not in intake["transcript"]
            ),
            "CB-1003": (
                secret["redaction_count"] >= 3
                and "[REDACTED]" in secret["intent"]
                and not any(s in json.dumps(secret).lower()
                            for s in ("hunter2", "supersecrettoken",
                                      "abcdefghijklmnopqrstuvwxyz"))
            ),
            "CB-1004": (
                any("client" in q for q in intake["proposal"]["questions"])
                and "unknown_requirements" in intake["proposal"]["risks"]
                and intake["proposal"]["execution"] is False
            ),
            "CB-1005": (
                intake["proposal"]["project_match"]["status"] == "matched"
                and intake["proposal"]["project_match"]["auto_create"] is False
                and probe["ambiguous_match"]["status"] == "ambiguous"
                and probe["ambiguous_match"]["auto_create"] is False
                and probe["ambiguous_operation"] == "review_matches"
            ),
            "CB-1006": (
                probe["cases"]["tampered_diff"] and probe["mutations"] == 0
            ),
            "CB-1007": (
                probe["cases"]["stale_owner"] and probe["cases"]["no_owner"]
                and probe["mutations"] == 0
            ),
            "CB-1008": (
                probe["cases"]["revise"] and probe["cases"]["reject"]
                and probe["mutations"] == 0
            ),
            "CB-1009": (
                choice["missing_blocked"] and choice["ambiguous_blocked"]
                and choice["ambiguous_mutations"] == 0
                and choice["receipt"]["project_ref"] ==
                "controlbot:project/new-venture"
                and choice["counts"]["create"] == 1
                and probe["cases"]["incomplete_project"]
            ),
            "CB-1010": (
                idempotent["first"] == idempotent["second"]
                and idempotent["counts"] ==
                {"link": 0, "create": 1, "epic": 1, "issue": 1}
                and len(keys["project"]) == len(keys["epic"]) == 64
                and len(first["issues"][0]["idempotency_key"]) == 64
                and existing["counts"]["create"] == 0
                and existing["counts"]["link"] == 1
            ),
            "CB-1011": (
                set(r) == {
                    "receipt_ref", "version", "proposal_ref",
                    "proposal_fingerprint", "decision_ref",
                    "decision_fingerprint", "project_ref", "epic_ref",
                    "issues", "idempotency_keys", "replay_safe"
                }
                and not any(term in json.dumps(r).lower()
                            for term in ("transcript_text", "raw_audio",
                                         "password=", "github_pat_", "bearer "))
                and receipt["secret_blocked"]
            ),
            "CB-1012": (
                probe["cases"]["tampered_diff"]
                and probe["cases"]["misbound_approval"]
                and probe["cases"]["incomplete_project"]
                and rejected["blocked"] == [True, True, True]
                and rejected["mutations"] == [0, 0, 0]
            ),
        }
        self.assertEqual(set(checks), ROWS)
        for row, result in sorted(checks.items()):
            with self.subTest(row=row):
                self.assertTrue(result, row)

    def test_falla_cerrado(self) -> None:
        """AC-02: stale, ambiguous, unapproved, invalid and unauthorised."""
        probe = scenario("probe")
        self.assertEqual(probe["mutations"], 0)
        self.assertTrue(all(probe["cases"].values()))
        rejected = scenario("material", "rejected")
        self.assertEqual(rejected["blocked"], [True, True, True])
        self.assertEqual(rejected["mutations"], [0, 0, 0])
        chosen = scenario("material", "project-choice")
        self.assertTrue(chosen["missing_blocked"])
        self.assertTrue(chosen["ambiguous_blocked"])
        self.assertEqual(chosen["ambiguous_mutations"], 0)

    def test_sin_datos_reales(self) -> None:
        """AC-03: synthetic fixtures and sanitized receipts, no live authority."""
        data = scenario("intake")
        view = scenario("view")
        receipt = scenario("material", "receipt")["receipt"]
        for value in (data["secret_draft"], data["secret_proposal"],
                      view["secret_view"], receipt):
            serialized = json.dumps(value).lower()
            for sensitive in (
                "hunter2", "supersecrettoken", "abcdefghijklmnopqrstuvwxyz",
                "raw_transcript", "raw_audio", "github_pat_", "password=",
            ):
                self.assertNotIn(sensitive, serialized)
        self.assertFalse(view["view"]["execution"])
        self.assertFalse(data["proposal"]["execution"])
        self.assertTrue(data["proposal"]["requires_approval"])
        self.assertEqual(len(ROWS), 16)


if __name__ == "__main__":
    unittest.main()
