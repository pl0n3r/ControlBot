"""Contrato offline de canales de soporte por proyecto (#1358).

Esta suite valida documentación y ejemplos; NO pretende que los endpoints
ni la bitácora estén implementados, persistidos o en producción.
"""
import json
import re
import unittest
from pathlib import Path
from urllib.parse import urlsplit, parse_qsl

DOC = Path(__file__).resolve().parents[1] / "docs" / "support-channels.md"
PLACEHOLDER = "[COMPLETAR POR EL DUEÑO]"
PROJECTS = {"Condor", "GrindFlow", "brvtal"}
KINDS = {"email", "form", "messaging"}
CHANNEL_FIELDS = {
    "id", "type", "destination", "schedule", "responsible_ref",
    "status", "sla_text", "revision",
}
SCHEDULE_FIELDS = {"timezone", "hours"}
SLA = "mejor esfuerzo, sin SLA"


def initial_catalog(text):
    match = re.search(
        r"<!-- support-channels-initial-v1 -->\s*"
        r"\x60{3}json\s*(.*?)\s*\x60{3}\s*"
        r"<!-- /support-channels-initial-v1 -->", text, re.S
    )
    if not match:
        raise ValueError("Missing versioned JSON support catalog")
    return json.loads(match.group(1))


def validate_channel(channel):
    if not isinstance(channel, dict) or set(channel) != CHANNEL_FIELDS:
        raise ValueError("Unexpected or missing channel field")
    if not isinstance(channel["id"], str) or not re.fullmatch(
        r"[a-z0-9][a-z0-9-]{0,63}", channel["id"]
    ):
        raise ValueError("Invalid channel identifier")
    if channel["type"] not in KINDS:
        raise ValueError("Unsupported channel type")
    if not isinstance(channel["revision"], int) or isinstance(
        channel["revision"], bool
    ) or channel["revision"] < 0:
        raise ValueError("Invalid revision")
    if channel["sla_text"] != SLA:
        raise ValueError("Unapproved SLA")
    if set(channel["schedule"]) != SCHEDULE_FIELDS:
        raise ValueError("Invalid schedule shape")
    if channel["schedule"]["timezone"] != "America/Bogota":
        raise ValueError("Unexpected timezone")
    for key in ("hours",):
        if not isinstance(channel["schedule"][key], str) or not channel["schedule"][key]:
            raise ValueError("Missing support hours")
    if not isinstance(channel["responsible_ref"], str) or not channel["responsible_ref"]:
        raise ValueError("Missing responsible reference")
    destination = channel["destination"]
    if not isinstance(destination, str) or not destination:
        raise ValueError("Missing destination")
    if re.search(r"(?i)(password|secret|bearer|api[_-]?key|token)\s*[=:]", destination):
        raise ValueError("Sensitive destination")
    if destination != PLACEHOLDER:
        if channel["type"] == "form":
            parts = urlsplit(destination)
            if parts.scheme != "https" or not parts.netloc or parts.username or parts.password:
                raise ValueError("Untrusted form destination")
            if any(re.search(r"(?i)(token|secret|key|password|auth)", k)
                   for k, _ in parse_qsl(parts.query)):
                raise ValueError("Credentials in form URL")
        elif any(ch.isspace() for ch in destination):
            raise ValueError("Malformed public contact")
    unconfigured = (
        destination == PLACEHOLDER or
        channel["schedule"]["hours"] == PLACEHOLDER or
        channel["responsible_ref"] == PLACEHOLDER
    )
    expected_status = "unconfigured" if unconfigured else "configured"
    if channel["status"] != expected_status:
        raise ValueError("Configuration state misrepresented")
    return channel


def read_projection(catalog, project, authorized_scope):
    if project not in PROJECTS or authorized_scope != f"controlbot:venture/{project}":
        raise PermissionError("Forbidden cross-project read")
    channels = catalog["projects"][project]
    return {
        "project": project,
        "channels": [validate_channel(ch).copy() for ch in channels],
        "revision": max((ch["revision"] for ch in channels), default=0),
    }


class SupportChannelsTests(unittest.TestCase):
    def test_criterio_principal(self):
        text = DOC.read_text(encoding="utf-8")
        catalog = initial_catalog(text)
        self.assertEqual(catalog["version"], 1)
        self.assertEqual(set(catalog["projects"]), PROJECTS)
        for project in PROJECTS:
            channels = catalog["projects"][project]
            self.assertGreaterEqual(len(channels), 1)
            ids = set()
            for channel in channels:
                validate_channel(channel)
                self.assertNotIn(channel["id"], ids)
                ids.add(channel["id"])
                self.assertEqual(channel["destination"], PLACEHOLDER)
                self.assertEqual(channel["schedule"]["hours"], PLACEHOLDER)
                self.assertEqual(channel["responsible_ref"], PLACEHOLDER)
                self.assertEqual(channel["status"], "unconfigured")
                self.assertEqual(channel["sla_text"], SLA)
                self.assertEqual(channel["revision"], 0)
            response = read_projection(catalog, project, f"controlbot:venture/{project}")
            self.assertEqual(response["project"], project)
            self.assertEqual(response["channels"], channels)
            self.assertEqual(response["revision"], 0)
        self.assertEqual(
            {c[0]["type"] for c in catalog["projects"].values()}, KINDS
        )
        for required in (
            "PATCH /internal/v1/support-channels/{project}/{id}",
            "GET /api/v1/support-channels?project={project}",
            "support_channels.manage", "support_channels.read",
            "Identity Center", "expected_revision", "previous_value",
            "new_value", "actor_ref", "occurred_at", "append-only",
            "Cache-Control: no-store", "403/404", "America/Bogota",
            "mejor esfuerzo, sin SLA", "sin efecto lateral",
        ):
            self.assertIn(required, text)

    def test_negative_cases_fail_closed(self):
        import copy
        catalog = initial_catalog(DOC.read_text(encoding="utf-8"))
        original = catalog["projects"]["Condor"][0]
        mutations = [
            {"type": "telephone"},
            {"status": "configured"},
            {"revision": -1},
            {"revision": True},
            {"sla_text": "24 horas"},
            {"destination": "password=abc"},
            {"destination": "https://example.org/?token=abc"},
            {"schedule": {"timezone": "UTC", "hours": PLACEHOLDER}},
            {"responsible_ref": ""},
            {"id": "../other"},
            {"session": "secret"},
        ]
        for change in mutations:
            with self.subTest(change=change):
                bad = copy.deepcopy(original)
                bad.update(change)
                # An https URL is not an email destination either: only
                # test credentials here with form type.
                if bad.get("destination") == "https://example.org/?token=abc":
                    bad["type"] = "form"
                with self.assertRaises(ValueError):
                    validate_channel(bad)
        for project, scope in (
            ("Condor", "controlbot:venture/GrindFlow"),
            ("missing", "controlbot:venture/missing"),
            ("Condor", ""),
        ):
            with self.subTest(project=project, scope=scope):
                with self.assertRaises(PermissionError):
                    read_projection(catalog, project, scope)

    def test_contract_has_no_live_data_or_runtime_claim(self):
        text = DOC.read_text(encoding="utf-8")
        self.assertIn("no servicio HTTP/persistencia desplegado", text)
        self.assertIn("NO configuración activa", text)
        self.assertIn("no implementación", text.lower())
        self.assertIn("sin SLA", text)
        self.assertNotRegex(text, r"(?i)\bSLA\s*(?:de|:|=)\s*\d+")
        self.assertNotIn("mailto:[COMPLETAR POR EL DUEÑO]", text)


if __name__ == "__main__":
    unittest.main()
