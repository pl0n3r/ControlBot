#!/usr/bin/env python3
"""ControlBot #1076: behavioral contrast for CB-1057..CB-1071.

Offline, synthetic PHP fixtures only. LOCAL_CONTRACT is not production, live,
deployment, delivery, legal authorization, or a promotion of the coverage matrix.
"""
from __future__ import annotations

from test_brechas_controlbot_20 import credible_e4_coverage_row

from copy import deepcopy
from functools import lru_cache
import json
from pathlib import Path
import re
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_CONTRACT"
MAIN = "docs/matriz-cobertura.md"
PART = "docs/matriz-cobertura-parte-04.md"
IDS = tuple(f"CB-{number:04d}" for number in range(1057, 1072))
DOCS = {
    "alert": "docs/security-alert-channel-v1.md",
    "retention": "docs/security-event-retention-v1.md",
    "inbox": "docs/security-event-ui-v1.md",
    "event": "docs/security-event-v1.md",
}
SCRIPTS = {
    "alert": "tests/security_alert_scenarios.php",
    "retention": "tests/security_event_retention_scenarios.php",
    "inbox": "tests/security_event_ui_scenarios.php",
    "event": "tests/security_event_scenarios.php",
}
MODULES = {
    "alert": "src/SecurityAlertChannel.php",
    "retention": "src/SecurityEventRetention.php",
    "inbox": "src/SecurityEventInbox.php",
    "event": "src/SecurityEvent.php",
}
CLAUSES = {
    "CB-1057": ("alert", "Si el estado primario requerido es"),
    "CB-1058": ("alert", "Para una intención que requiere atención"),
    "CB-1059": ("retention", "`SecurityEventRetention` proyecta"),
    "CB-1060": ("retention", "el plan conserva identidad"),
    "CB-1061": ("retention", "`legal_hold=true` es explícito"),
    "CB-1062": ("retention", "acknowledge solo se acepta"),
    "CB-1063": ("retention", "No hay DB, filesystem writes"),
    "CB-1064": ("inbox", "orden: `critical"),
    "CB-1065": ("inbox", "acknowledge genera un `SecurityEvent v1`"),
    "CB-1066": ("inbox", "El projector es puro"),
    "CB-1067": ("event", "Solo acepta `version`"),
    "CB-1068": ("event", "`severity` (`info/warning/critical`)"),
    "CB-1069": ("event", "Con `event_id`, `event_identity`"),
    "CB-1070": ("event", "`device` es opcional"),
    "CB-1071": ("event", "Acciones sensibles de ControlBot"),
}
MATRIX_FILES = (MAIN, PART)
SOURCE_FILES = set(MATRIX_FILES) | set(DOCS.values()) | set(MODULES.values())
FORBIDDEN_IO = (
    "curl_", "fsockopen", "new pdo", "mysqli", "file_put_contents",
    "file_get_contents", "unlink(", "shell_exec", "proc_open",
    "exec(", "system(", "mail(", "http://", "https://",
)


def sources() -> dict[str, str]:
    """Read tracked code and normative contracts only; never write."""
    return {path: (ROOT / path).read_text(encoding="utf-8")
            for path in SOURCE_FILES}


def matrix_rows(src: dict[str, str]) -> dict[str, tuple[str, ...]]:
    """Duplicates, absent rows, changed claims and promotions are UNKNOWN."""
    found = {}
    for path in MATRIX_FILES:
        for line in src[path].splitlines():
            match = re.match(r"^\s*\|\s*(CB-\d{4})\s*\|", line)
            if not match or match.group(1) not in IDS:
                continue
            ident = match.group(1)
            cells = tuple(x.strip() for x in line.strip().strip("|").split("|"))
            group, clause = CLAUSES[ident]
            if (ident in found or len(cells) != 6 or cells[0] != ident
                    or DOCS[group] not in cells[1] or clause not in cells[2]
                    or not credible_e4_coverage_row(cells)
                    or "/issues/1" not in cells[5]
                    or (path == MAIN) != (ident in {"CB-1059", "CB-1064", "CB-1067"})):
                raise ValueError("UNKNOWN: invalid/duplicate row " + ident)
            found[ident] = cells
    if set(found) != set(IDS):
        raise ValueError("UNKNOWN: missing IDs")
    return found


@lru_cache(maxsize=None)
def scenario(group: str) -> dict:
    """Run an allowlisted local PHP scenario; no arbitrary commands or providers."""
    if group not in SCRIPTS:
        raise ValueError("UNKNOWN: unknown synthetic scenario")
    php = shutil.which("php")
    if php is None:
        raise RuntimeError("UNKNOWN: PHP unavailable")
    run = subprocess.run(
        [php, str(ROOT / SCRIPTS[group])], cwd=ROOT,
        capture_output=True, text=True, check=False, timeout=30,
    )
    if run.returncode:
        raise RuntimeError("UNKNOWN: failed synthetic scenario " + group)
    try:
        value = json.loads(run.stdout)
    except ValueError as exc:
        raise RuntimeError("UNKNOWN: unreadable scenario output") from exc
    if not isinstance(value, dict) or not value:
        raise RuntimeError("UNKNOWN: empty scenario evidence")
    return value


def observations() -> dict[str, dict]:
    return {group: scenario(group) for group in SCRIPTS}


def pure_module(group: str, src: dict[str, str]) -> bool:
    body = src[MODULES[group]].lower()
    return not any(item in body for item in FORBIDDEN_IO)


def behavior(ident: str, evidence: dict[str, dict], src: dict[str, str]) -> bool:
    """One specific observable contract for each of the 15 coverage gaps."""
    a, r, u, e = (evidence[name] for name in ("alert", "retention", "inbox", "event"))
    expired, hold = r["expired"], r["held"]
    minimum = expired["retain_minimum"]
    if ident == "CB-1057":
        return (all(a[key]["decision"] == a[key]["channel"] == "owner_review"
                    for key in ("unknown_primary", "unknown_independent"))
                and a["critical_independent"]["decision"] == "independent_channel")
    if ident == "CB-1058":
        allowed = {"event_identity", "event_type", "severity", "confidence", "timestamp", "context"}
        return all(
            set(a[key]["payload"]) == allowed
            and set(a[key]["payload"]["context"]) == {"account_scope"}
            and a[key]["payload"]["context"]["account_scope"].startswith("controlbot:")
            for key in ("critical_independent", "unknown_primary", "warning_primary")
        ) and a["warning_no_alert"]["payload"] is None
    if ident == "CB-1059":
        return (expired == r["replay"] and expired["expired"] is True
                and r["login_plan"]["expired"] is False
                and expired["retention_window_seconds"] == 50
                and r["login_plan"]["retention_window_seconds"] == 150
                and expired["purge_plan_id"].startswith("security-retention:"))
    if ident == "CB-1060":
        return (set(minimum) == {"event_identity", "fingerprint", "event_type",
                                "severity", "confidence", "observed_at", "occurred_at",
                                "account_scope", "acknowledge"}
                and minimum["event_identity"] == expired["event_identity"]
                and minimum["account_scope"].startswith("controlbot:")
                and all(expired["classifications"][key] == "retain_minimum"
                        for key in ("fingerprint", "event_type", "severity", "confidence",
                                    "observed_at", "occurred_at")))
    if ident == "CB-1061":
        return (hold["legal_hold"] is True and expired["legal_hold"] is False
                and hold["retain_minimum"] == minimum
                and all(hold["classifications"][key] == "purge"
                        for key in ("raw_payload", "secrets", "ip", "precise_location", "credentials"))
                and hold["retained_artifact_references"] == ["controlbot:evidence/security-evt-retention"])
    if ident == "CB-1062":
        ack = minimum["acknowledge"]
        return (r["wrong_ack_rejected"] is True and r["pre_event_ack_rejected"] is True
                and set(ack) == {"state", "event_identity", "observed_at"}
                and ack["state"] == "acknowledged" and ack["observed_at"] == 120
                and ack["event_identity"].startswith("security-event:"))
    if ident == "CB-1063":
        return (pure_module("retention", src)
                and expired["policy_version"] == r["same_version_changed"]["policy_version"] == 1
                and expired["policy_fingerprint"] != r["same_version_changed"]["policy_fingerprint"]
                and expired["purge_plan_id"] != r["same_version_changed"]["purge_plan_id"]
                and r["changed"]["policy_version"] == 2
                and expired["purge_plan_id"] != r["changed"]["purge_plan_id"])
    if ident == "CB-1064":
        cards = u["inbox"]["cards"]
        return ([c["severity"] for c in cards] == ["critical", "warning", "warning", "info"]
                and [c["event_type"] for c in cards] ==
                    ["new_device", "new_login", "new_login", "passkey_added"]
                and cards[1]["observed_at"] < cards[2]["observed_at"]
                and all(c["event_identity"].startswith("security-event:") for c in cards)
                and "event_identity" in src[MODULES["inbox"]])
    if ident == "CB-1065":
        return (u["ack"] == u["ack_replay"]
                and u["ack"]["event_type"] == "security_alert_acknowledged"
                and u["ack"]["provider"] == "controlbot"
                and u["ack"]["event_identity"].startswith("security-event:provider:"))
    if ident == "CB-1066":
        return (pure_module("inbox", src)
                and u["critical"]["event_type"] == "new_device"
                and u["inbox"]["cards"][0]["state"] == "acknowledged"
                and u["unread"]["cards"][0]["state"] == "unread")
    if ident == "CB-1067":
        return (e["reject_extra_field"] is True
                and set(e["new_device"]) == {"version", "provider", "event_id",
                    "observed_at", "occurred_at", "event_type", "severity",
                    "account_scope", "confidence", "device", "actor",
                    "event_identity", "fingerprint"})
    if ident == "CB-1068":
        return (e["new_device"]["severity"] == "warning"
                and e["new_device"]["confidence"] == "provider_reported"
                and e["incomplete_unknown"]["confidence"] == "unknown"
                and e["reject_confirmed_incomplete"] is True
                and e["fallback_a"]["event_id"] is None
                and e["fallback_a"]["occurred_at"] == 1850)
    if ident == "CB-1069":
        return (e["new_device"]["event_identity"] == e["repeat"]["event_identity"]
                == e["reordered"]["event_identity"]
                and e["fallback_a"]["event_identity"] == e["fallback_b"]["event_identity"]
                and e["new_device"]["fingerprint"] == e["reordered"]["fingerprint"]
                and e["new_device"]["event_id"] not in e["new_device"]["event_identity"])
    if ident == "CB-1070":
        device = e["new_device"]["device"]
        return (e["reject_precise_location"] is True
                and set(device) == {"type", "platform", "browser", "location"}
                and set(device["location"]) == {"country", "region", "city"}
                and e["controlbot"]["device"] is None)
    if ident == "CB-1071":
        actor = e["controlbot"]["actor"]
        return (e["reject_secret"] is True and e["reject_missing_actor"] is True
                and set(actor) == {"actor_ref", "context_ref"}
                and all(ref.startswith("controlbot:") for ref in actor.values())
                and not any(key in json.dumps(actor).lower()
                            for key in ("password", "token", "cookie", "otp", "email", "@")))
    return False


def verdict(ident: object, src: object, evidence: object) -> str:
    """A missing source, incomplete probe or ambiguous clause is never success."""
    if (not isinstance(ident, str) or ident not in IDS
            or not isinstance(src, dict) or set(src) != SOURCE_FILES
            or not isinstance(evidence, dict) or set(evidence) != set(SCRIPTS)):
        return UNKNOWN
    try:
        if any(not isinstance(value, str) or not value for value in src.values()):
            return UNKNOWN
        rows = matrix_rows(src)
        group, clause = CLAUSES[ident]
        # Matrix phrasing and normative document syntax can differ.
        doc_clause = ("`severity` (`info|warning|critical`)"
                      if ident == "CB-1068" else clause)
        if doc_clause not in src[DOCS[group]]:
            return UNKNOWN
        if not pure_module(group, src) or not pure_module("event", src):
            return UNKNOWN
        if not behavior(ident, evidence, src):
            return UNKNOWN
        if not credible_e4_coverage_row(rows[ident]):
            return UNKNOWN
    except (KeyError, TypeError, ValueError, IndexError, AttributeError):
        return UNKNOWN
    return LOCAL


def operational_state(_ident: object, _evidence: object = None) -> str:
    """Local synthetic evidence never certifies CI, live or production."""
    return UNKNOWN


class BrechasControlbot69Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.src = sources()
        cls.evidence = observations()

    def test_contrato_principal(self) -> None:
        self.assertEqual(15, len(IDS))
        self.assertEqual(set(IDS), set(CLAUSES))
        self.assertEqual(set(IDS), set(matrix_rows(self.src)))
        for ident in IDS:
            with self.subTest(ident=ident):
                self.assertEqual(LOCAL, verdict(ident, self.src, self.evidence))

    def test_falla_cerrado(self) -> None:
        for ident in IDS:
            path = MAIN if ident in {"CB-1059", "CB-1064", "CB-1067"} else PART
            row = next(line for line in self.src[path].splitlines()
                       if line.startswith(f"| {ident} |"))
            group, _ = CLAUSES[ident]
            for change in ("missing", "duplicate", "promoted"):
                changed = dict(self.src)
                if change == "missing":
                    changed[path] = changed[path].replace(row, "", 1)
                elif change == "duplicate":
                    changed[path] += "\n" + row
                else:
                    changed[path] = changed[path].replace(
                        row, row.replace("NO_VERIFICABLE", "CUBIERTO"), 1)
                with self.subTest(ident=ident, change=change):
                    self.assertEqual(UNKNOWN, verdict(ident, changed, self.evidence))
            without_probe = dict(self.evidence)
            without_probe.pop(group)
            self.assertEqual(UNKNOWN, verdict(ident, self.src, without_probe))
            changed_probe = deepcopy(self.evidence)
            changed_probe[group] = {}
            self.assertEqual(UNKNOWN, verdict(ident, self.src, changed_probe))

    def test_sin_datos_reales(self) -> None:
        for group, path in MODULES.items():
            with self.subTest(group=group):
                self.assertTrue(pure_module(group, self.src))
                self.assertTrue(path.startswith("src/"))
                self.assertTrue(SCRIPTS[group].startswith("tests/"))
        alert_payload = self.evidence["alert"]["critical_independent"]["payload"]
        self.assertEqual(set(alert_payload["context"]), {"account_scope"})
        retention = json.dumps(self.evidence["retention"]).lower()
        for forbidden in ("password=never-retain", "github_pat_not_retained",
                          "203.0.113.4", "private key", "bearer "):
            self.assertNotIn(forbidden, retention)
        self.assertNotIn("@", json.dumps(alert_payload))
        self.assertFalse((ROOT / "tests/test_brechas_controlbot_69.py").is_dir())

    def test_estados_unknown_explicitos(self) -> None:
        for ident in IDS:
            self.assertEqual(UNKNOWN, operational_state(ident, self.evidence))
            self.assertEqual(UNKNOWN, verdict(ident, self.src, None))
            self.assertEqual(UNKNOWN, verdict(ident, {}, self.evidence))
            group, _ = CLAUSES[ident]
            corrupt = dict(self.src)
            corrupt[DOCS[group]] = ""
            self.assertEqual(UNKNOWN, verdict(ident, corrupt, self.evidence))
        self.assertEqual(UNKNOWN, verdict("CB-9999", self.src, self.evidence))
        self.assertEqual(UNKNOWN, operational_state("live", {"workflow": "success"}))


if __name__ == "__main__":
    unittest.main()
