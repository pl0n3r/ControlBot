#!/usr/bin/env python3
"""#1225: behavioural ControlBot CB-0693..0697; no simulated live/go-live.

The PHP scenarios load src/MarketScope.php and src/MarketInstitutionSegment.php,
exercise the real projection with synthetic opaque references, and return JSON.
Documentary/operational rows are classified, not falsely marked as covered.
"""
from __future__ import annotations

from functools import lru_cache
import json
from pathlib import Path
import re
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCENARIO = ROOT / "tests/market_institution_segment_scenarios.php"
CLASS = ROOT / "src/MarketInstitutionSegment.php"
SCOPE = ROOT / "src/MarketScope.php"
ALL_IDS = {f"CB-{n:04d}" for n in range(684, 692)} | {
    "CB-0683", "CB-0693", "CB-0694", "CB-0695", "CB-0696", "CB-0697",
}
# B: evidence from authorized live operator/real deployments is essential.
# C: documentary policy, status classification or source attribution.
# Neither category is treated as an executable behavioral pass.
NON_OFFLINE = {
    "CB-0683": ("C", "NO-GO document is a governance decision, not deploy permission"),
    "CB-0684": ("B", "LISTO requires fresh authenticated live evidence and exact deployed identity"),
    "CB-0685": ("C", "EN_CURSO eligibility is a documented issue/lease classification"),
    "CB-0686": ("C", "DEL_DUEÑO identifies missing human authority and never grants it"),
    "CB-0687": ("C", "NO_APLICA requires a reason; editorial policy cannot waive gates"),
    "CB-0688": ("C", "Zero new leaves is a historical audit statement for that cut"),
    "CB-0689": ("B", "Hostinger, restore, DNS, credentials, consent and owner checks require real operator receipts"),
    "CB-0690": ("C", "Safe route is a procedure retaining human decision and provenance"),
    "CB-0691": ("C", "Sources paragraph is attribution; not an executable behavior"),
}
OFFLINE = ALL_IDS - set(NON_OFFLINE)
CASES = {"scoped", "invalid", "markets", "freshness", "authority", "pure"}


@lru_cache(maxsize=6)
def real_php(case: str) -> dict:
    """Fail closed if PHP, the real class or the approved fixture is missing."""
    if case not in CASES:
        raise AssertionError("Unapproved PHP scenario")
    php = shutil.which("php")
    if php is None or not all(x.is_file() and not x.is_symlink()
                              for x in (SCENARIO, CLASS, SCOPE)):
        raise AssertionError("PHP or real ControlBot implementation is absent")
    p = subprocess.run(
        [php, "-d", "allow_url_fopen=0", str(SCENARIO), case],
        cwd=ROOT, capture_output=True, text=True, timeout=25, check=False,
    )
    if p.returncode != 0 or p.stderr.strip():
        raise AssertionError("Real PHP scenario failed: " + p.stderr[-300:])
    try:
        value = json.loads(p.stdout)
    except (ValueError, TypeError) as exc:
        raise AssertionError("Real PHP scenario emitted non-JSON") from exc
    if not isinstance(value, dict):
        raise AssertionError("Real PHP scenario emitted non-object")
    return value


class CoberturaControlbot42Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: five executable rows call real PHP, other nine stay B/C."""
        self.assertEqual(len(ALL_IDS), 14)
        self.assertEqual(OFFLINE, {
            "CB-0693", "CB-0694", "CB-0695", "CB-0696", "CB-0697",
        })
        self.assertEqual(ALL_IDS, OFFLINE | set(NON_OFFLINE))
        self.assertTrue(all(kind in {"B", "C"} and len(reason) > 20
                            for kind, reason in NON_OFFLINE.values()))

        freshness = real_php("freshness")
        signals = {s["institution"]: s for s in freshness["projection"]["signals"]}
        # CB-0693: observed_at is an integer for stale/fresh, null for unknown.
        self.assertEqual(1500, signals["momentum"]["observed_at"])
        self.assertIsNone(signals["capital"]["observed_at"])
        self.assertIs(type(signals["momentum"]["observed_at"]), int)
        self.assertTrue(freshness["unknown_with_time"])
        self.assertTrue(freshness["known_without_time"])

        # CB-0694: freshness vocabulary preserved; no invented timestamp.
        self.assertEqual("stale", signals["momentum"]["freshness"])
        self.assertEqual("unknown", signals["capital"]["freshness"])
        scoped = real_php("scoped")["projection"]
        self.assertEqual({"fresh"}, {s["freshness"] for s in scoped["signals"]})
        self.assertEqual(scoped, real_php("scoped")["reordered"])

        # CB-0695: the real class rejects forbidden budget/authority payload.
        authority = real_php("authority")
        forbidden = {
            "authority", "policy", "approval", "budget", "spend",
            "forecast", "revenue", "campaign_payload", "decision",
        }
        self.assertEqual(forbidden, set(authority["rejected"]))
        self.assertTrue(all(authority["rejected"].values()))
        projection = json.dumps(authority["projection"], sort_keys=True).lower()
        self.assertTrue(all(token not in projection for token in forbidden))

        # CB-0696: stateless pure projection, no provider/queue calls.
        pure = real_php("pure")
        self.assertEqual(["project"], pure["methods"])
        self.assertEqual([], pure["projection"]["signals"])
        self.assertEqual(pure["projection"], real_php("pure")["projection"])
        code = CLASS.read_text(encoding="utf-8").lower()
        for token in ("curl_", "http://", "https://", "mysqli", "pdo(",
                      "factoryrunner", "scheduler", "workitem", "provider",
                      "persist", "queue", "file_put_contents", "exec("):
            self.assertNotIn(token, code)

        # CB-0697: only syntactically opaque signal/evidence refs accepted.
        invalid = real_php("invalid")
        self.assertEqual({"namespace", "institution", "duplicate_signal",
                          "duplicate_evidence", "extra"}, set(invalid))
        self.assertTrue(all(invalid.values()))
        evidence = signals["momentum"]["evidence_refs"]
        self.assertEqual(2, len(evidence))
        self.assertEqual(sorted(evidence), evidence)
        self.assertTrue(all(re.fullmatch(r"controlbot:evidence/[a-f0-9]{32}", x)
                            for x in evidence))
        self.assertTrue(all(re.fullmatch(
            r"controlbot:(?:momentum|capital)/[a-f0-9]{32}", s["signal_ref"])
            for s in signals.values()))

    def test_falla_cerrado(self) -> None:
        """AC-02: unknown timestamp, bad venture, duplicate or extra input."""
        f = real_php("freshness")
        self.assertTrue(f["known_without_time"])
        self.assertTrue(f["unknown_with_time"])
        a = real_php("authority")
        self.assertTrue(all(a["rejected"].values()))
        bad = real_php("invalid")
        self.assertTrue(all(bad.values()))
        self.assertTrue(real_php("scoped")["cross_venture"])
        with self.assertRaises(AssertionError):
            real_php("not-approved")
        # Fail-closed for missing classes/fixtures, no passing empty fallback.
        self.assertTrue(SCENARIO.is_file() and CLASS.is_file() and SCOPE.is_file())

    def test_sin_datos_reales(self) -> None:
        """AC-03: no live data, keys, budget grants or provider execution."""
        self.assertEqual("C", NON_OFFLINE["CB-0683"][0])
        self.assertEqual("B", NON_OFFLINE["CB-0689"][0])
        projected = real_php("markets")
        self.assertEqual("market-colombia", projected["country"]["market_id"])
        self.assertEqual("market-mexico", projected["mexico"]["market_id"])
        self.assertEqual({"kind": "global", "code": None},
                         projected["global"]["geography"])
        self.assertEqual("market-global", projected["global"]["market_id"])
        self.assertTrue(all("signals" in p for p in projected.values()))
        self.assertTrue(all("authority" not in p and "approval" not in p
                            for p in projected.values()))
        own = Path(__file__).read_text(encoding="utf-8")
        # Construct prohibited markers from separate fragments: embedding each
        # full marker in this very test would otherwise be a false positive.
        private_marker = "-----BEGIN " + "PRIVATE KEY-----"
        api_marker = "api" + ".github.com"
        host_marker = "Hostinger " + "API key"
        for marker in (private_marker, api_marker, host_marker):
            self.assertNotIn(marker, own)


if __name__ == "__main__":
    unittest.main()
