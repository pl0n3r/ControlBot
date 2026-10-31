"""D-059: contrato de precondición de despliegue en un almacén B2 simulado.

Usa las clases PHP existentes; ninguna operación llama a Hostinger o Backblaze.
El éxito sintético nunca activa una capacidad de despliegue real.
"""
from __future__ import annotations

from datetime import datetime, timedelta, timezone
from hashlib import sha256
import json
import os
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs" / "hostinger-deploy-backup-gate.md"
SAMPLE = "fixture-public_html-controlbot-solo-sintetico"
CREATED = datetime(2026, 10, 30, 12, 0, tzinfo=timezone.utc)
VERIFIED = CREATED + timedelta(minutes=1)
WRITE = VERIFIED + timedelta(minutes=1)

PHP_PROBE = r"""
$root = getenv('CB_TEST_ROOT');
require $root . '/src/CapabilityPolicy.php';
require $root . '/src/BackupReceipt.php';
require $root . '/src/BackupGate.php';
require $root . '/src/BackupIntegrityVerifier.php';

use ControlBot\Production\BackupGate;
use ControlBot\Production\BackupReceipt;
use ControlBot\Recovery\BackupIntegrityVerifier;

$request = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$record = $request['receipt'];
$scope = $request['scope'];
$when = $request['write_at'];
$sample = $request['sample'];
$restored = $request['restored'];
$gate = BackupGate::evaluate('deploy.rollback_artifact', $scope, $record, $when);
$checksum = ['status' => 'UNKNOWN', 'reason' => 'receipt_invalid'];
try {
    $backup = BackupReceipt::fromRecord($record);
    $checksum = $backup->verifyPayloadChecksumBefore($restored, $scope, $when);
} catch (\InvalidArgumentException $e) {
    // Un recibo inválido no puede abrir la puerta.
}
$manifest = [
    'version' => 1,
    'artifact_ref' => 'artifact:synthetic:controlbot-b2-restore',
    'environment' => 'testing',
    'algorithm' => 'sha256',
    'checksum' => hash('sha256', $sample),
    'size_bytes' => strlen($sample),
    'created_at' => $request['created_at'],
    'verified_at' => $request['verified_at'],
];
$restore = BackupIntegrityVerifier::verify($manifest, $restored, $when);
$destinationIsB2 = $request['destination'] === 'backblaze-b2'
    && str_starts_with($record['artifact_ref'] ?? '', 'artifact:b2:');
// Este es un contrato de test: nunca se convierte en permiso de deploy real.
$simulatedGate = $gate['allowed'] === true
    && $destinationIsB2
    && ($record['restore_capability'] ?? '') === 'validated'
    && $checksum['status'] === 'MATCH'
    && $restore['status'] === 'OK';
echo json_encode([
    'synthetic_precondition' => $simulatedGate ? 'SATISFIED' : 'DENIED',
    'backup_reason' => $gate['reason'],
    'checksum' => $checksum['status'],
    'restore' => $restore['status'],
    'runtime_authorized' => false,
    'deploy_enabled' => false,
    'production_verified' => false,
    'authority' => 'unchanged',
], JSON_THROW_ON_ERROR);
"""


def iso(value: datetime) -> str:
    return value.strftime("%Y-%m-%dT%H:%M:%SZ")


def fixture() -> dict:
    return {
        "receipt": {
            "version": 1,
            "receipt_id": "11111111-2222-4333-8444-555555555555",
            "project": "controlbot",
            "environment": "testing",
            "resource": "public_html",
            "backup_type": "release_artifact_snapshot",
            "run_id": "aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee",
            "issue": "pl0n3r/ControlBot#1359",
            "created_at": iso(CREATED),
            "verified_at": iso(VERIFIED),
            "status": "ready",
            "artifact_ref": "artifact:b2:controlbot-synthetic-restorepoint",
            "integrity": {"algorithm": "sha256", "digest": sha256(SAMPLE.encode()).hexdigest()},
            "restore_capability": "validated",
            "expires_at": iso(WRITE + timedelta(minutes=30)),
        },
        "scope": {
            "project": "controlbot",
            "environment": "testing",
            "resource": "public_html",
            "run_id": "aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee",
            "issue": "pl0n3r/ControlBot#1359",
        },
        "created_at": int(CREATED.timestamp()),
        "verified_at": int(VERIFIED.timestamp()),
        "write_at": int(WRITE.timestamp()),
        "sample": SAMPLE,
        "restored": SAMPLE,
        "destination": "backblaze-b2",
    }


def probe(payload: dict) -> dict:
    run = subprocess.run(
        ["php", "-r", PHP_PROBE],
        cwd=ROOT,
        input=json.dumps(payload),
        text=True,
        capture_output=True,
        env={**os.environ, "CB_TEST_ROOT": str(ROOT)},
        timeout=15,
        check=True,
    )
    return json.loads(run.stdout)


class DeployBackupGateTests(unittest.TestCase):
    def test_recibo_backup_restauracion_sintetica(self):
        output = probe(fixture())
        self.assertEqual(output["synthetic_precondition"], "SATISFIED")
        self.assertEqual(output["backup_reason"], "backup_verified")
        self.assertEqual(output["checksum"], "MATCH")
        self.assertEqual(output["restore"], "OK")
        self.assertFalse(output["runtime_authorized"])
        self.assertFalse(output["deploy_enabled"])
        self.assertFalse(output["production_verified"])

    def test_falla_cerrado_si_copia_no_es_previa_reciente_o_en_su_alcance(self):
        mutations = [
            ("copia no preparada", lambda x: x["receipt"].update(status="pending")),
            ("fecha posterior", lambda x: x.update(write_at=int(VERIFIED.timestamp()))),
            ("sin vencimiento", lambda x: x["receipt"].update(expires_at=None)),
            ("copia caducada", lambda x: x.update(write_at=int((WRITE + timedelta(hours=1)).timestamp()))),
            ("sin tipo snapshot", lambda x: x["receipt"].update(backup_type="database_dump")),
            ("scope distinto", lambda x: x["scope"].update(resource="public_html_other")),
            ("ID ajeno", lambda x: x["receipt"].update(run_id="bbbbbbbb-cccc-4ddd-8eee-ffffffffffff")),
            ("sin destino", lambda x: x.update(destination="unverified")),
            ("destino no referenciado", lambda x: x["receipt"].update(artifact_ref="artifact:other:synthetic")),
            ("restore no verificado", lambda x: x["receipt"].update(restore_capability="unknown")),
            ("digest alterado", lambda x: x["receipt"]["integrity"].update(digest="a" * 64)),
        ]
        for name, mutate in mutations:
            with self.subTest(name=name):
                sample = fixture()
                mutate(sample)
                result = probe(sample)
                self.assertEqual(result["synthetic_precondition"], "DENIED")
                self.assertFalse(result["deploy_enabled"])

    def test_restore_descartable_detecta_corrupcion_y_truncamiento(self):
        for sample in (SAMPLE + "alterado", SAMPLE[:-6], ""):
            with self.subTest(size=len(sample)):
                payload = fixture()
                payload["restored"] = sample
                result = probe(payload)
                self.assertEqual(result["synthetic_precondition"], "DENIED")
                self.assertNotEqual(result["restore"], "OK")
        self.assertEqual(probe(fixture())["restore"], "OK")

    def test_documentacion_no_convierte_fixture_en_autorizacion(self):
        doc = DOC.read_text(encoding="utf-8")
        for phrase in ("Backblaze B2", "SHA-256", "NAME_FILE", "restore", "D-059", "UNKNOWN", "sin red"):
            with self.subTest(phrase=phrase):
                self.assertIn(phrase, doc)
        result = probe(fixture())
        self.assertEqual(result["authority"], "unchanged")
        self.assertFalse(result["runtime_authorized"])
        self.assertNotIn(SAMPLE, json.dumps(result))
        self.assertNotIn(fixture()["receipt"]["integrity"]["digest"], json.dumps(result))


if __name__ == "__main__":
    unittest.main()
