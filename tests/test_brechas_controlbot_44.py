"""ControlBot #1051: 15 offline regressions for CB-0660..0675 (except CB-0673).

These checks execute the actual PHP LEX contracts with synthetic fixtures.
They do not certify legal approval, real customer data, deployment or live state.
"""
from __future__ import annotations

from copy import deepcopy
from functools import lru_cache
import json
import os
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
IDS = frozenset(
    [f"CB-{n:04d}" for n in range(660, 673)] + ["CB-0674", "CB-0675"]
)
PHP_EVAL = r"""
require 'src/LexJurisdiction.php';
$input=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
try {
    $result=\ControlBot\Legal\LexJurisdiction::normalize($input['pack'], $input['now']);
    echo json_encode(['accepted'=>true, 'result'=>$result], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    echo json_encode(['accepted'=>false, 'reason'=>$e->getMessage()], JSON_THROW_ON_ERROR);
}
"""


def php_command(args: list[str], *, payload: dict | None = None) -> dict:
    """Run local PHP without inherited credentials or access to a provider."""
    php = shutil.which("php")
    if not php:
        raise AssertionError("PHP CLI required for executable LEX acceptance")
    run = subprocess.run(
        [php, *args], cwd=ROOT, text=True, capture_output=True,
        input=None if payload is None else json.dumps(payload),
        env={"PATH": os.environ.get("PATH", "/usr/local/bin:/usr/bin:/bin")},
        timeout=30, check=False,
    )
    if run.returncode:
        raise AssertionError(f"Offline PHP scenario failed (exit {run.returncode}): {run.stderr[:300]}")
    return json.loads(run.stdout)


@lru_cache(maxsize=16)
def scenario(domain: str, name: str) -> dict:
    scripts = {
        "jurisdiction": "lex_jurisdiction_scenarios.php",
        "lifecycle": "lex_lifecycle_scenarios.php",
        "watch": "lex_watch_scenarios.php",
    }
    return php_command([str(ROOT / "tests" / scripts[domain]), name])


def normalize_pack(pack: dict, now: int = 1200) -> dict:
    return php_command(["-r", PHP_EVAL], payload={"pack": pack, "now": now})


def synthetic_pack() -> dict:
    """Remove only derived fields from the existing generic MX fixture."""
    pack = deepcopy(scenario("jurisdiction", "generic"))
    pack.pop("evidence_state")
    pack.pop("reasons")
    return pack


def matrix_rows() -> dict[str, list[str]]:
    rows: dict[str, list[str]] = {}
    for filename in (
        "docs/matriz-cobertura.md",
        "docs/matriz-cobertura-parte-03.md",
    ):
        for line in (ROOT / filename).read_text(encoding="utf-8").splitlines():
            if not line.startswith("| CB-"):
                continue
            cells = [cell.strip() for cell in line.strip("|").split("|")]
            if cells[0] not in IDS:
                continue
            if cells[0] in rows:
                raise AssertionError(f"Duplicate coverage row {cells[0]}")
            if len(cells) != 6:
                raise AssertionError(f"Invalid coverage row {cells[0]}")
            rows[cells[0]] = cells
    return rows


class BrechasControlbot44Tests(unittest.TestCase):
    """Four executable acceptance methods; one verdict per claimed CB ID."""

    def test_contrato_principal(self):
        """AC-01: every row has a distinct behavioral assertion."""
        rows = matrix_rows()
        self.assertEqual(set(rows), IDS)
        generic = scenario("jurisdiction", "generic")
        co = scenario("jurisdiction", "co")
        fresh = scenario("jurisdiction", "freshness")
        invalid = scenario("jurisdiction", "invalid")
        construction = scenario("lifecycle", "construction")
        live = scenario("lifecycle", "live")
        risk = scenario("lifecycle", "risk")
        watch = scenario("watch", "impact")
        publication = scenario("watch", "publication")
        untrusted = scenario("watch", "fail_closed")
        signals = {s["signal_ids"][0]: s for s in watch["signals"]}
        trusted = {s["signal_ids"][0]: s for s in publication["signals"]}
        base = synthetic_pack()
        region, supranational = deepcopy(base), deepcopy(base)
        region.update(jurisdiction="region:ANDES", kind="region")
        supranational.update(jurisdiction="supranational:EU", kind="supranational")
        region_result = normalize_pack(region)
        supranational_result = normalize_pack(supranational)
        incompatible = deepcopy(base)
        incompatible["compatibility"]["lex_core_contract"] = 2
        incompatible_result = normalize_pack(incompatible)
        evidence_ref = "controlbot:lex/evidence/legal-review"

        verdicts = {
            # CB-0660: the same contract accepts country, region and supranational.
            "CB-0660": (
                generic["jurisdiction"] == "country:MX"
                and region_result["accepted"]
                and region_result["result"]["evidence_state"] == "current"
                and supranational_result["accepted"]
                and supranational_result["result"]["evidence_state"] == "current"
            ),
            # CB-0661: stale, unknown, expired and core incompatibility deny currency.
            "CB-0661": (
                all(p["evidence_state"] == "not_current" and p["reasons"]
                    for p in fresh.values())
                and incompatible_result["accepted"]
                and incompatible_result["result"]["evidence_state"] == "not_current"
                and "lex_core_contract_incompatible" in incompatible_result["result"]["reasons"]
            ),
            # CB-0662: every referenced pack control requires independent human review.
            "CB-0662": (
                bool(co["controls"]) and bool(co["sources"])
                and all(c["human_review_required"] is True for c in co["controls"])
                and all(set(c["source_refs"]) <=
                        {s["source_id"] for s in co["sources"]} for c in co["controls"])
            ),
            # CB-0663: schema, refs, time and HTTPS constraints reject bad fixtures.
            "CB-0663": (
                set(invalid) == {"missing_field", "unknown_source",
                                 "future_review", "non_https_source", "kind_mismatch"}
                and all(reason != "accepted" for reason in invalid.values())
            ),
            # CB-0664: jurisdiction normalization is data-only, not a provider/handoff.
            "CB-0664": (
                set(generic) == {
                    "schema_version", "pack_id", "jurisdiction", "kind",
                    "pack_version", "state", "freshness", "reviewed_at",
                    "expires_at", "evidence_state", "reasons",
                    "responsible_refs", "sources", "controls",
                    "assumption_refs", "exclusion_refs", "compatibility",
                }
            ),
            # CB-0665: lifecycle projects legal gates, not legal advice or execution.
            "CB-0665": (
                construction["stage"] == "construction"
                and construction["authority_effect"] == "none"
                and construction["auto_execute"] is False
                and construction["live_legal_gate"]["state"] == "blocked"
            ),
            # CB-0666: pending construction review is documented, never COMPLIANT.
            "CB-0666": (
                construction["construction_legal_status"]
                == "documented_not_legally_approved"
                and construction["legal_state"] == "unknown"
                and construction["live_legal_gate"]["required_now"] is False
            ),
            # CB-0667: only safe reversible work continues while review is pending.
            "CB-0667": (
                construction["construction_work_allowed"] is True
                and risk["material"]["construction_work_allowed"] is False
                and risk["rejected"]["construction_work_allowed"] is False
            ),
            # CB-0668: live needs compatible state + human approval + no material risk.
            "CB-0668": (
                live["approved"]["live_legal_gate"]["state"] == "pass"
                and live["gap"]["live_legal_gate"]["state"] == "blocked"
                and risk["material_approved"]["live_legal_gate"]["state"] == "blocked"
            ),
            # CB-0669: approval carries evidence; approval without it is rejected.
            "CB-0669": (
                live["approved"]["live_legal_gate"]["evidence_refs"] == [evidence_ref]
                and live["approved_without_evidence_blocked"] is True
            ),
            # CB-0670: live and real-data transitions are scoped gate checks.
            "CB-0670": (
                live["pending"]["transition_allowed"] is False
                and live["real_data"]["transition_allowed"] is False
                and live["approved"]["transition_allowed"] is True
            ),
            # CB-0671: no projection gains authority or executes anything.
            "CB-0671": all(
                obj["authority_effect"] == "none" and obj["auto_execute"] is False
                for obj in [construction, *list(live.values())[:0],
                            live["approved"], live["pending"], risk["material"]]
            ),
            # CB-0672: human review is limited to its approved scope.
            "CB-0672": (
                live["wrong_scope"]["transition_allowed"] is False
                and "human_review_scope_mismatch" in
                live["wrong_scope"]["live_legal_gate"]["reasons"]
            ),
            # CB-0674: traceable plausible impact alone yields a review candidate.
            "CB-0674": (
                signals["signal-traceable"]["impact_state"] == "plausible"
                and signals["signal-traceable"]["impact_refs"] == ["controlbot:venture/condor"]
                and signals["signal-traceable"]["review_candidate"] is True
                and signals["signal-none"]["review_candidate"] is False
                and signals["signal-unknown-impact"]["review_candidate"] is False
                and len(watch["review_candidates"]) == 1
            ),
            # CB-0675: only verified + fresh can assert publication.
            "CB-0675": (
                trusted["signal-effective"]["publication_state"] == "published"
                and trusted["signal-future"]["effective_state"] == "not_yet_effective"
                and all(s["publication_state"] == "unknown"
                        and s["effective_state"] == "unknown"
                        and s["review_candidate"] is False
                        for s in untrusted["signals"])
            ),
        }
        self.assertEqual(set(verdicts), IDS)
        for identifier, passed in verdicts.items():
            with self.subTest(identifier=identifier):
                self.assertIn("docs/", rows[identifier][1])
                self.assertTrue(passed, identifier)

    def test_falla_cerrado(self):
        """AC-02: malicious/ambiguous inputs are rejected by the real PHP guard."""
        base = synthetic_pack()
        mutations = {
            "unknown_field": lambda p: p.update(unknown_field=1),
            "missing_field": lambda p: p.pop("controls"),
            "wrong_kind": lambda p: p.update(kind="region"),
            "unknown_source": lambda p: p["controls"][0].update(source_refs=["not-there"]),
            "sensitive_id": lambda p: p["sources"][0].update(source_id="api-token-fixture"),
            "non_https": lambda p: p["sources"][0].update(uri="http://example.invalid"),
            "embedded_credentials": lambda p: p["sources"][0].update(
                uri="https://someone@example.invalid/fixture"),
            "future_review": lambda p: p.update(reviewed_at=1400),
            "duplicate_source_conflict": lambda p: p["sources"].append(
                {**p["sources"][0], "title": "conflicting fixture title"}),
            "duplicate_control_conflict": lambda p: p["controls"].append(
                {**p["controls"][0], "domain": "consumer"}),
        }
        for name, mutate in mutations.items():
            with self.subTest(case=name):
                pack = deepcopy(base)
                mutate(pack)
                result = normalize_pack(pack)
                self.assertFalse(result["accepted"], (name, result))
                self.assertTrue(result["reason"])

        live = scenario("lifecycle", "live")
        invalid_evidence = scenario("lifecycle", "invalid-evidence")
        self.assertTrue(live["approved_without_evidence_blocked"])
        self.assertTrue(all(invalid_evidence.values()))
        for candidate in ("pending", "gap", "wrong_scope", "real_data"):
            with self.subTest(candidate=candidate):
                self.assertFalse(live[candidate]["transition_allowed"])
        self.assertEqual(scenario("watch", "fail_closed")["review_candidates"], [])

    def test_sin_datos_reales(self):
        """AC-03: synthetic input and sanitized child environment, no external IO."""
        fixture = scenario("jurisdiction", "generic")
        self.assertEqual(fixture["jurisdiction"], "country:MX")
        self.assertEqual(fixture["sources"][0]["uri"], "https://example.invalid/legal-source")
        self.assertEqual(fixture["sources"][0]["authority"], "Synthetic fixture authority")
        self.assertTrue(all(c["human_review_required"] for c in fixture["controls"]))
        # No HTTP client, remote provider, or actual legal review is invoked.
        scripts = (
            "tests/lex_jurisdiction_scenarios.php",
            "tests/lex_lifecycle_scenarios.php",
            "tests/lex_watch_scenarios.php",
        )
        for path in scripts:
            with self.subTest(fixture=path):
                source = (ROOT / path).read_text(encoding="utf-8")
                self.assertNotIn("curl_exec(", source)
                self.assertNotIn("file_get_contents('https://", source)
        for source in (fixture, scenario("lifecycle", "construction"),
                       scenario("watch", "impact")):
            rendered = json.dumps(source).lower()
            for key in ('"password"', '"api_key"', '"access_token"',
                        '"credentials"', '"raw_payload"'):
                self.assertNotIn(key, rendered)

    def test_estados_unknown_explicitos(self):
        """AC-04: synthetic success never turns missing live/legal evidence into green."""
        rows = matrix_rows()
        self.assertEqual(set(rows), IDS)
        for identifier, cells in rows.items():
            with self.subTest(identifier=identifier):
                if cells[3] == "NO_VERIFICABLE":
                    self.assertIn("UNKNOWN", cells[4])
                    self.assertIn("live=UNKNOWN", cells[4])
                    self.assertIn("operando=UNKNOWN", cells[4])
                else:
                    # A later reconciliation may move a row to CUBIERTO;
                    # only a separate evidence-backed leaf may do that.
                    self.assertEqual(cells[3], "CUBIERTO")
        for kind, result in scenario("jurisdiction", "freshness").items():
            with self.subTest(pack=kind):
                self.assertEqual(result["evidence_state"], "not_current")
                self.assertTrue(result["reasons"])
        pending = scenario("lifecycle", "construction")
        self.assertEqual(pending["legal_state"], "unknown")
        self.assertEqual(pending["live_legal_gate"]["state"], "blocked")
        self.assertEqual(pending["authority_effect"], "none")
        for signal in scenario("watch", "fail_closed")["signals"]:
            with self.subTest(signal=signal["signal_ids"][0]):
                self.assertEqual(signal["publication_state"], "unknown")
                self.assertEqual(signal["effective_state"], "unknown")
                self.assertFalse(signal["review_candidate"])


if __name__ == "__main__":
    unittest.main()
