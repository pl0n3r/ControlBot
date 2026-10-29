#!/usr/bin/env python3
"""ControlBot #1226: 18 contract rows backed by real Market PHP behavior.

Synthetic, deterministic fixtures only. Readiness is a projection, not permission
to launch or proof that any external provider, legal gate or market is live.
"""
from __future__ import annotations

import copy
import json
import os
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOMAINS = (
    "aegis", "capital", "infrastructure", "lex", "localization", "momentum",
    "observability", "ownership", "payments", "pricing", "privacy", "product",
    "support",
)
ROWS = (
    "CB-0698", "CB-0699", "CB-0700", "CB-0701", "CB-0702", "CB-0703",
    "CB-0704", "CB-0705", "CB-0706", "CB-0707", "CB-0708", "CB-0710",
    "CB-0711", "CB-0712", "CB-0713", "CB-0714", "CB-0715", "CB-0716",
)
PHP = r"""
require 'src/MarketScope.php';
require 'src/MarketReadiness.php';
try {
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $op = $input['operation'] ?? null;
    $value = match ($op) {
        'readiness' => \ControlBot\Business\MarketReadiness::forCountry(
            $input['market'], $input['venture'], $input['country'], $input['gates']),
        'scope' => \ControlBot\Business\MarketScope::scope($input['scope']),
        'market' => \ControlBot\Business\MarketScope::market(
            $input['market'], $input['venture']),
        default => throw new \InvalidArgumentException('Unknown operation'),
    };
    echo json_encode(['ok' => true, 'value' => $value], JSON_THROW_ON_ERROR);
} catch (\Throwable $error) {
    echo json_encode(['ok' => false, 'error' => get_class($error)], JSON_THROW_ON_ERROR);
}
"""


def market(**changes: object) -> dict:
    row = {
        "version": 1, "market_id": "market-example-co",
        "venture_id": "venture-example",
        "geography": {"kind": "country", "code": "CO"},
        "status": "preparing", "priority": 1,
        "currencies": ["COP"], "locales": ["es-CO"],
        "source_ref": "controlbot:market/example/co",
        "observed_at": 2000, "freshness": "fresh",
    }
    row.update(changes)
    return row


def scope(**changes: object) -> dict:
    row = {
        "version": 1, "mode": "single_country", "primary_country": "CO",
        "target_countries": ["CO"], "excluded_countries": [],
        "launch_countries": ["CO"], "expansion_candidates": ["MX"],
        "default_currency": "COP", "default_locale": "es-CO",
    }
    row.update(changes)
    return row


def gates() -> list[dict]:
    return [{
        "domain": domain, "status": "ready",
        "evidence_refs": ["controlbot:evidence/" + format(i + 1, "032x")],
        "observed_at": 1900, "freshness": "fresh",
    } for i, domain in enumerate(DOMAINS)]


def changed_gate(rows: list[dict], domain: str, **changes: object) -> list[dict]:
    result = copy.deepcopy(rows)
    gate = next(g for g in result if g["domain"] == domain)
    gate.update(changes)
    return result


def run_php(operation: str, **payload: object) -> dict:
    """Execute the existing PHP classes in-process, without a network client."""
    php = shutil.which("php")
    if php is None:
        raise AssertionError("PHP missing: real behavior cannot be certified")
    forwarded = (
        "PATH", "LANG", "LC_ALL", "XDEBUG_MODE", "CONTROLBOT_REAL_PHP",
        "CONTROLBOT_PHP_COVERAGE_BOOTSTRAP", "CONTROLBOT_PHP_COVERAGE_DIR",
        "CONTROLBOT_REPO_ROOT",
    )
    env = {k: os.environ[k] for k in forwarded if k in os.environ}
    run = subprocess.run(
        [php, "-d", "allow_url_fopen=0", "-r", PHP],
        input=json.dumps({"operation": operation, **payload}, sort_keys=True),
        cwd=ROOT, env=env, text=True, capture_output=True, timeout=20,
        check=False,
    )
    if run.returncode != 0:
        raise AssertionError("PHP harness failed: " + run.stderr[:350])
    try:
        answer = json.loads(run.stdout)
    except json.JSONDecodeError as error:
        raise AssertionError("PHP returned no verifiable JSON") from error
    if not isinstance(answer, dict) or not isinstance(answer.get("ok"), bool):
        raise AssertionError("PHP returned an invalid contract envelope")
    return answer


class CoberturaControlbot43Tests(unittest.TestCase):
    def ready(self, rows: list[dict] | None = None,
              current: dict | None = None) -> dict:
        result = run_php(
            "readiness", market=current if current is not None else market(),
            venture="venture-example", country="CO",
            gates=rows if rows is not None else gates(),
        )
        self.assertTrue(result["ok"], result)
        return result["value"]

    def rejected(self, operation: str, **payload: object) -> None:
        response = run_php(operation, **payload)
        self.assertFalse(response["ok"], "unexpected permission/readiness")
        self.assertEqual(response["error"], "InvalidArgumentException")

    def test_contrato_principal(self) -> None:
        base = gates()
        # CB-0698: pure, deterministic, country-scoped 13-gate projection.
        projection = self.ready(base)
        self.assertEqual(projection, self.ready(copy.deepcopy(base)))
        self.assertEqual(projection["venture_id"], "venture-example")
        self.assertEqual(projection["country"], "CO")
        self.assertEqual(len(projection["gates"]), 13)
        self.assertFalse(projection["execution"])
        self.assertNotIn("score", projection)

        # CB-0699: fresh retains each upstream status as effective.
        for status in ("ready", "blocked", "unknown", "not_applicable"):
            observed = self.ready(changed_gate(base, "lex", status=status))
            lex = next(g for g in observed["gates"] if g["domain"] == "lex")
            self.assertEqual(lex["status"], status)
            self.assertEqual(lex["effective_status"], status)

        # CB-0700, CB-0703, CB-0704: stale/unknown cannot assert readiness.
        for freshness, observed_at in (("stale", 1800), ("unknown", None)):
            evidence = self.ready(changed_gate(
                base, "lex", status="ready", freshness=freshness,
                observed_at=observed_at))
            lex = next(g for g in evidence["gates"] if g["domain"] == "lex")
            self.assertEqual(lex["status"], "ready")
            self.assertEqual(lex["effective_status"], "unknown")
            self.assertEqual(lex["observed_at"], observed_at)
            self.assertEqual(evidence["launch_state"], "unknown")

        # CB-0705: only unknown gates => unknown; missing is exact.
        u = self.ready(changed_gate(base, "support", status="unknown"))
        self.assertEqual(u["launch_state"], "unknown")
        self.assertEqual(u["missing_domains"], ["support"])

        # CB-0706: not_applicable with fresh evidence is non-blocking.
        na = self.ready(changed_gate(base, "payments", status="not_applicable"))
        self.assertEqual(na["launch_state"], "ready")
        self.assertEqual(na["missing_domains"], [])

        # CB-0707: blocked dominates unknown; Market.status cannot override.
        mixed = changed_gate(base, "payments", status="blocked")
        mixed = changed_gate(mixed, "support", status="unknown")
        live = self.ready(mixed, market(status="live"))
        self.assertEqual(live["market_status"], "live")
        self.assertEqual(live["launch_state"], "blocked")
        self.assertEqual(live["missing_domains"], ["payments", "support"])

        # CB-0708: scope/freshness required; projection is not execution.
        self.assertEqual(self.ready(current=market(status="launch_ready"))[
            "launch_state"], "ready")
        self.assertFalse(self.ready()["execution"])

        # CB-0710: single country explicitly matches primary and only target.
        single = run_php("scope", scope=scope())
        self.assertTrue(single["ok"], single)
        self.assertEqual(single["value"]["target_countries"], ["CO"])
        self.assertEqual(single["value"]["primary_country"], "CO")

        # CB-0711: two explicit targets; primary belongs to them.
        multi = run_php("scope", scope=scope(
            mode="multi_country", target_countries=["MX", "CO"],
            expansion_candidates=["PE"]))
        self.assertTrue(multi["ok"], multi)
        self.assertEqual(multi["value"]["target_countries"], ["CO", "MX"])

        # CB-0712: global never creates implicit countries or permission.
        global_scope = run_php("scope", scope=scope(
            mode="global", target_countries=[], launch_countries=["US"]))
        self.assertTrue(global_scope["ok"], global_scope)
        self.assertEqual(global_scope["value"]["target_countries"], [])
        self.assertEqual(global_scope["value"]["launch_countries"], ["US"])
        self.assertNotIn("lex_status", global_scope["value"])
        self.assertNotIn("go_live", global_scope["value"])

        # CB-0713: only disjoint country sets are accepted.
        self.assertEqual(single["value"]["excluded_countries"], [])

        # CB-0714/15: Market state is metadata, not legal/go-live evidence.
        for state in ("launch_ready", "live"):
            m = run_php("market", market=market(status=state),
                        venture="venture-example")
            self.assertTrue(m["ok"], m)
            self.assertEqual(m["value"]["status"], state)
            self.assertNotIn("readiness", m["value"])
            self.assertNotIn("lex_status", m["value"])
            blocked = self.ready(
                changed_gate(base, "lex", status="blocked"),
                market(status=state),
            )
            self.assertEqual(blocked["launch_state"], "blocked")

        # CB-0716: normalized contract is deterministic and side-effect free.
        self.assertEqual(single, run_php("scope", scope=scope()))
        self.assertEqual(self.ready()["execution"], False)

    def test_falla_cerrado(self) -> None:
        base = gates()
        # CB-0701: fresh or stale evidence is mandatory, even for blocked.
        for status in ("ready", "blocked", "unknown", "not_applicable"):
            for freshness in ("fresh", "stale"):
                invalid = changed_gate(base, "lex", status=status,
                                       freshness=freshness, evidence_refs=[])
                self.rejected("readiness", market=market(),
                              venture="venture-example", country="CO", gates=invalid)

        # CB-0702: ready/not_applicable without fresh evidence never effective.
        for status in ("ready", "not_applicable"):
            self.rejected("readiness", market=market(), venture="venture-example",
                          country="CO", gates=changed_gate(
                              base, "lex", status=status, freshness="unknown",
                              observed_at=None, evidence_refs=[]))

        # CB-0703: unknown timestamps must be explicitly null.
        self.rejected("readiness", market=market(), venture="venture-example",
                      country="CO", gates=changed_gate(
                          base, "lex", freshness="unknown", observed_at=1800))

        # CB-0708: fail closed on non-country, stale Market or wrong Venture.
        for invalid_market in (
            market(geography={"kind": "global", "code": None}),
            market(geography={"kind": "region", "code": "LATAM"}),
            market(freshness="stale"),
        ):
            self.rejected("readiness", market=invalid_market,
                          venture="venture-example", country="CO", gates=base)
        self.rejected("readiness", market=market(), venture="venture-other",
                      country="CO", gates=base)
        self.rejected("readiness", market=market(), venture="venture-example",
                      country="MX", gates=base)
        self.rejected("readiness", market=market(), venture="venture-example",
                      country="CO", gates=base[:-1])

        # CB-0710: missing primary or a different single target is invalid.
        for invalid in (
            scope(primary_country=None),
            scope(target_countries=["MX"], launch_countries=["MX"]),
        ):
            self.rejected("scope", scope=invalid)

        # CB-0711: multi target count and primary membership enforced.
        for invalid in (
            scope(mode="multi_country", target_countries=["CO"]),
            scope(mode="multi_country", target_countries=["MX", "US"],
                  launch_countries=["MX"], expansion_candidates=["FR"]),
        ):
            self.rejected("scope", scope=invalid)

        # CB-0713: exclusion cannot intersect target or launch, including global.
        self.rejected("scope", scope=scope(excluded_countries=["CO"]))
        self.rejected("scope", scope=scope(
            mode="global", target_countries=[], launch_countries=["US"],
            excluded_countries=["US"]))

        # CB-0714/16: invalid target and unknown fields do not bypass validation.
        self.rejected("scope", scope=scope(primary_country="ZZ",
                      target_countries=["ZZ"], launch_countries=["ZZ"]))
        invalid = scope()
        invalid["lex_compliant"] = True
        self.rejected("scope", scope=invalid)

    def test_sin_datos_reales(self) -> None:
        # CB-0698/0708/0716: only disposable fixture values are passed to PHP.
        data = {"market": market(), "gates": gates(), "scope": scope()}
        rendered = json.dumps(data)
        self.assertNotIn("password", rendered.lower())
        self.assertNotIn("authorization", rendered.lower())
        self.assertNotIn("email", rendered.lower())
        self.assertNotIn("https://", rendered.lower())
        for name in ("MarketReadiness.php", "MarketScope.php"):
            source = (ROOT / "src" / name).read_text(encoding="utf-8").lower()
            for forbidden in (
                "curl_exec(", "file_put_contents(", "mysqli_connect(",
                "shell_exec(", "workitem::", "scheduler::",
                "lexcore::", "capitalpolicy::", "momentumcampaign::",
            ):
                self.assertNotIn(forbidden, source, name)
        self.assertTrue(run_php("scope", scope=scope())["ok"])
        self.assertFalse(self.ready()["execution"])

    def test_validar_exigido(self) -> None:
        # AC-04 verifies the enforced CI contract, not a simulated green check.
        workflow = (ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        self.assertIn("name: Validar", workflow)
        self.assertIn("needs: [factory, contrato]", workflow)
        self.assertIn(
            "ref: $" + "{{ github.event_name == 'pull_request' && "
            "github.event.pull_request.head.sha || github.sha }}", workflow)
        self.assertIn("name: Pruebas de contrato e integración", workflow)


if __name__ == "__main__":
    unittest.main()
