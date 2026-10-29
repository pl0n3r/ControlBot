#!/usr/bin/env python3
"""ControlBot #1220: regresiones offline que invocan PHP real de infraestructura.

El descubrimiento/ejecución de adapters reales y las responsabilidades globales
de los sistemas requieren evidencia separada; no se acredita producción.
"""
from __future__ import annotations

import json
import os
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = r"""
require 'src/InfrastructureProvider.php';
require 'src/InfrastructureResource.php';
$raw = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
try {
    $mode = $raw['mode'] ?? null;
    if ($mode === 'provider') {
        $result = \ControlBot\Infrastructure\InfrastructureProvider::normalizeProvider($raw['data']);
    } elseif ($mode === 'inventory') {
        $result = \ControlBot\Infrastructure\InfrastructureProvider::normalizeInventory(
            $raw['providers'], $raw['accounts']
        );
    } elseif ($mode === 'resource') {
        $result = \ControlBot\Infrastructure\InfrastructureResource::normalize($raw['data']);
    } elseif ($mode === 'resources') {
        $result = \ControlBot\Infrastructure\InfrastructureResource::normalizeInventory($raw['rows']);
    } else {
        throw new \InvalidArgumentException('mode');
    }
    echo json_encode(['ok' => true, 'data' => $result], JSON_THROW_ON_ERROR);
} catch (\InvalidArgumentException | \TypeError $e) {
    echo json_encode(['ok' => false, 'type' => get_class($e)], JSON_THROW_ON_ERROR);
}
"""
KINDS = ("environment", "service", "database", "storage", "dns", "certificate", "backup", "network")
CAPABILITIES = (
    "inventory.read", "health.read", "deploy.read", "backup.read", "backup.write",
    "database.read", "storage.read", "dns.read", "dns.write", "certificate.read",
    "network.read", "cost.read",
)
SCOPES = ("provider", "project", "environment", "resource")


def provider(identifier: str = "provider-primary") -> dict:
    return {
        "version": 1, "provider_id": identifier, "kind": "hosting", "vendor": "vendor-one",
        "adapter_ref": f"controlbot:adapter/{identifier}",
        "capabilities": [{"capability": cap, "scopes": list(SCOPES)} for cap in CAPABILITIES],
        "source_ref": f"controlbot:provider/{identifier}", "observed_at": 2000,
    }


def account(identifier: str = "account-primary", provider_id: str = "provider-primary") -> dict:
    return {
        "version": 1, "account_id": identifier, "provider_id": provider_id,
        "alias": identifier, "source_ref": f"controlbot:account/{identifier}",
        "observed_at": 2000,
    }


def resource(identifier: str = "service-web", kind: str = "service") -> dict:
    return {
        "version": 1, "resource_id": identifier, "kind": kind,
        "provider_id": "provider-primary", "account_id": "account-primary",
        "project_ref": "controlbot:project/project-example",
        "venture_ref": "controlbot:venture/venture-example",
        "environment_ref": "controlbot:environment/environment-example",
        "service_ref": None, "parent_ref": None, "release_evidence": None,
        "cost_ref": "controlbot:cost/project-example",
        "backup_refs": ["controlbot:backup/backup-b", "controlbot:backup/backup-a"],
        "source_ref": f"controlbot:resource/{identifier}", "observed_at": 2000,
    }


def run(mode: str, **payload: object) -> dict:
    php = shutil.which("php")
    if php is None:
        raise AssertionError("PHP unavailable: real code was not exercised")
    env = {key: value for key, value in os.environ.items()
           if key in ("PATH", "LANG", "LC_ALL", "XDEBUG_MODE",
                      "CONTROLBOT_REAL_PHP", "CONTROLBOT_PHP_COVERAGE_BOOTSTRAP",
                      "CONTROLBOT_PHP_COVERAGE_DIR", "CONTROLBOT_REPO_ROOT")}
    result = subprocess.run(
        [php, "-d", "allow_url_fopen=0", "-r", PHP],
        cwd=ROOT, env=env, input=json.dumps({"mode": mode, **payload}, sort_keys=True),
        text=True, capture_output=True, timeout=30, check=False,
    )
    if result.returncode:
        raise AssertionError("Real PHP contract failed: " + result.stderr[:250])
    return json.loads(result.stdout)


def scenario(filename: str) -> dict:
    allowed = {
        "infrastructure_control_center_scenarios.php",
        "infrastructure_action_projection_scenarios.php",
    }
    if filename not in allowed:
        raise ValueError("Only reviewed, offline PHP fixtures are allowed")
    php = shutil.which("php")
    path = ROOT / "tests" / filename
    if php is None or not path.is_file():
        raise AssertionError("Required real PHP scenario not available")
    result = subprocess.run(
        [php, "-d", "allow_url_fopen=0", str(path)], cwd=ROOT,
        text=True, capture_output=True, timeout=40, check=False,
    )
    if result.returncode:
        raise AssertionError("Real PHP scenario failed: " + result.stderr[:250])
    return json.loads(result.stdout)


class CoberturaControlbot37Tests(unittest.TestCase):
    def accepted(self, mode: str, **payload: object) -> object:
        response = run(mode, **payload)
        self.assertTrue(response["ok"], response)
        return response["data"]

    def rejected(self, mode: str, **payload: object) -> None:
        response = run(mode, **payload)
        self.assertIs(response["ok"], False, response)
        self.assertIn(response["type"], ("InvalidArgumentException", "TypeError"))

    def test_contrato_principal(self) -> None:
        # CB-0595/0596/0597: el modelo real normaliza tipos, identidad y refs.
        values = [resource(f"resource-{i}", kind) for i, kind in enumerate(KINDS)]
        rows = self.accepted("resources", rows=list(reversed(values)))
        self.assertEqual([item["kind"] for item in rows], list(KINDS))
        self.assertEqual([item["resource_id"] for item in rows],
                         sorted(item["resource_id"] for item in values))
        self.assertEqual(rows[0]["backup_refs"], sorted(values[0]["backup_refs"]))
        self.assertEqual(rows[0]["cost_ref"], "controlbot:cost/project-example")
        self.assertEqual(rows[0]["provider_id"], "provider-primary")
        self.assertEqual(rows[0]["account_id"], "account-primary")
        self.assertEqual(rows[0]["project_ref"], "controlbot:project/project-example")
        self.assertEqual(rows[0]["venture_ref"], "controlbot:venture/venture-example")

        # CB-0599: catálogo completo y scopes permitidos ejecutados con la clase PHP.
        p = self.accepted("provider", data=provider())
        self.assertEqual([x["capability"] for x in p["capabilities"]], sorted(CAPABILITIES))
        self.assertTrue(all(x["scopes"] == sorted(SCOPES) for x in p["capabilities"]))
        self.assertEqual(p["vendor"], "vendor-one")
        self.assertEqual(p["adapter_ref"], "controlbot:adapter/provider-primary")

        # CB-0603: determinismo por identidad, no por orden del input.
        a, b = provider("provider-a"), provider("provider-b")
        aa, bb = account("account-a", "provider-a"), account("account-b", "provider-b")
        first = self.accepted("inventory", providers=[b, a], accounts=[bb, aa])
        self.assertEqual(first, self.accepted("inventory", providers=[a, b],
                                              accounts=[aa, bb]))
        self.assertEqual(rows, self.accepted("resources", rows=values))

        # CB-0604/0605: WorkItem y RunnerRequest gobernados, proyección read-only.
        e2e = scenario("infrastructure_control_center_scenarios.php")
        plan = e2e["plan"]
        self.assertEqual(plan["status"], "planned")
        self.assertFalse(plan["execution"])
        self.assertEqual(plan["work_item"]["origin_system"], "controlbot")
        self.assertEqual(plan["runner_request"]["work_item_id"], plan["work_item"]["work_id"])
        self.assertEqual(e2e["order"]["work_item_id"], plan["work_item"]["work_id"])
        self.assertEqual(e2e["completed"]["evidence"]["code"], "verified")
        action = e2e["action"]
        self.assertFalse(action["execution"])
        self.assertEqual(action["status"], "planned")
        self.assertTrue(action["runner_request_ready"])
        self.assertEqual(action["work_item_id"], "infra-intent-action-191")

    def test_falla_cerrado(self) -> None:
        # CB-0596/0599/0600: desconocidos y scopes globales no se convierten en permisos.
        for patch in (
            {"kind": "hostinger"},
            {"kind": "cloud-provider"},
            {"capabilities": [{"capability": "inventory.list", "scopes": ["project"]}]},
            {"capabilities": [{"capability": "inventory.read", "scopes": ["global"]}]},
            {"capabilities": [{"capability": "inventory.read", "scopes": ["project"]}] * 2},
        ):
            with self.subTest(patch=patch):
                self.rejected("provider", data={**provider(), **patch})
        self.rejected("resource", data=resource("queue-one", "queue"))
        self.rejected("inventory", providers=[provider()],
                      accounts=[account(provider_id="missing-provider")])

        # CB-0601: material sensible, refs mal formadas, duplicados y campos extra.
        self.rejected("resource", data={**resource(), "password": "fictional"})
        self.rejected("resource", data={**resource(), "source_ref": "controlbot:secret/fixture"})
        self.rejected("resource", data={**resource(), "project_ref": "project-example"})
        self.rejected("resource", data={**resource(), "backup_refs": ["controlbot:backup/a"] * 2})
        self.rejected("resources", rows=[resource(), resource()])
        self.rejected("provider", data={**provider(), "adapter_ref": "controlbot:token/fixture"})

        # CB-0602/0605/0608: sin autoridad/freshness/costos no existe ruta ejecutable.
        e2e = scenario("infrastructure_control_center_scenarios.php")
        for key in ("authority_denied", "budget_denied"):
            self.assertEqual(e2e[key]["status"], "denied")
            self.assertIsNone(e2e[key]["runner_request"])
        self.assertIn("financial_evidence_unknown", e2e["budget_denied"]["reasons"])
        self.assertEqual(e2e["owner"]["status"], "owner_decision_required")
        self.assertIsNone(e2e["owner"]["runner_request"])
        for freshness in ("stale", "unknown"):
            self.assertEqual(e2e["non_fresh"][freshness]["effective"], "unknown")
            self.assertFalse(e2e["non_fresh"][freshness]["route"]["authority_used"])
            self.assertIsNone(e2e["non_fresh"][freshness]["route"]["order"])
        self.assertIsNone(e2e["ineligible_order"])
        projected = scenario("infrastructure_action_projection_scenarios.php")
        self.assertFalse(projected["owner"]["execution"])
        self.assertFalse(projected["owner"]["runner_request_ready"])
        self.assertIsNotNone(projected["owner"]["approval_ref"])
        for key in ("fake_plan_rejected", "fabricated_array_rejected",
                    "fabricated_object_rejected", "cross_capability_rejected"):
            self.assertTrue(projected[key], key)

    def test_sin_datos_reales(self) -> None:
        # CB-0601/0604/0608: fixtures sintéticos y rechazo en código real.
        value = self.accepted("resource", data=resource())
        self.assertEqual(value["source_ref"], "controlbot:resource/service-web")
        self.assertEqual(value["backup_refs"], sorted(resource()["backup_refs"]))
        self.assertEqual(set(value), set(resource()))
        e2e = scenario("infrastructure_control_center_scenarios.php")
        self.assertTrue(e2e["secret_instruction_rejected"])
        self.assertTrue(e2e["secret_evidence_rejected"])
        projected = scenario("infrastructure_action_projection_scenarios.php")
        self.assertTrue(projected["secret_rejected"])
        for item in (value, projected["planned"], e2e["action"]):
            serialized = json.dumps(item, sort_keys=True).lower()
            for forbidden in ("password", "bearer ", "github_pat_", "supersecret", "private_key"):
                self.assertNotIn(forbidden, serialized)
            self.assertFalse(item.get("execution", False))

    def test_ci_gate_contract(self) -> None:
        """AC-04: comportamiento ejecutable; Validar exact-HEAD es la autoridad CI."""
        checked = self.accepted("provider", data=provider())
        self.assertEqual(checked["version"], 1)
        self.assertEqual(checked["provider_id"], "provider-primary")
        self.rejected("provider", data={**provider(), "version": 2})


if __name__ == "__main__":
    unittest.main()
