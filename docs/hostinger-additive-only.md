# ControlBot #1369 — despliegues Hostinger solo aditivos

> **Contrato de pruebas offline.** No crea un ejecutor real, no conecta Hostinger, no configura permisos, no borra archivos, no emite credenciales y no habilita despliegues. Los permisos del dueño, copias y restauros de producción siguen sujetos a D-043, D-059, #1359, #625 y go-live autorizado.

## Política de operación segura

- **Predeterminado:** solo crear archivos inexistentes con ruta canónica validada; escribir un nombre existente equivale a **sobrescritura destructiva** y nunca es aditivo.
- Operaciones tipadas destructivas: `remove`, `overwrite`, `drop_table` y `truncate_table`. Deben tener aprobación explícita del **dueño** por **operación exacta**, de un solo uso, asociada a run, destino y contenido. Una aprobación no cubre otra ruta, otro SQL, otro run ni una modificación posterior.
- Rechazar incondicionalmente `rm`, `rmdir`, `DROP`, `TRUNCATE`, `DELETE`, `mv`, shell, SQL o comandos arbitrarios sin parseo tipado. **No ejecutar strings de shell/SQL aunque exista un supuesto permiso**. Toda eliminación autorizada solo se representa como acción tipada de la capa superior.
- Cualquier operación (también create) requiere primero **recibo de copia reciente y verificada** para el run/alcance de la operación y restore probado, conforme a la puerta D-059 de #1359. Si falta la evidencia auténtica, es antigua, pertenece a otro destino o es UNKNOWN, negar sin mutar.
- El simulador usa un callback de backup inyectado únicamente por el test. En producción el proceso confiable debe verificar por sí mismo evidencia SHA-256, restauración, firma/autoridad, caducidad y scope; nunca aceptar un objeto creado por el caller como prueba de un respaldo real.
- Toda acción denegada registra un recibo mínimo sin payload, secretos, grants ni credenciales. Fallo de auditoría -> fallo cerrado. Si una autorización destructiva se consume, no se reutiliza aunque se produzca un error posterior.
- No se conserva estado de aprobación entre procesos de este fixture: **un ejecutor real debe tener almacenamiento transaccional compartido y atómico** para evitar doble uso simultáneo. No se afirma que tal ejecutor exista en este PR.

## Contrato ejecutable de simulación sin red

El bloque de Python siguiente se carga solo por `tests/test_hostinger_additive_only.py`. Altera exclusivamente diccionarios en memoria; nunca llama sockets, SSH, API, shell, subprocess ni base de datos.

```python
# BEGIN HOSTINGER_ADDITIVE_ONLY_CONTRACT
import hashlib
import json
import re

RUN_ID = re.compile(r"^[a-z0-9-]{8,80}$")
TABLE = re.compile(r"^[a-zA-Z_][a-zA-Z0-9_]{0,62}$")
KINDS = frozenset({"create", "remove", "overwrite", "drop_table", "truncate_table"})
DESTRUCTIVE = frozenset({"remove", "overwrite", "drop_table", "truncate_table"})


def fingerprint(op, run_id):
    payload = json.dumps({"operation": op, "run_id": run_id}, sort_keys=True, separators=(",", ":"))
    return hashlib.sha256(payload.encode("utf-8")).hexdigest()


def safe_path(path):
    if not isinstance(path, str) or not 1 <= len(path) <= 200:
        return False
    if path.startswith("/") or "\\" in path or "\x00" in path or "//" in path:
        return False
    parts = path.split("/")
    return all(re.fullmatch(r"[a-zA-Z0-9_.-]{1,64}", part) and part not in {".", ".."} for part in parts)


class AdditiveOnlySimulator:
    """Memory-only fail-closed policy fixture; NEVER an actual deploy adapter."""

    def __init__(self, backup_gate, audit, now=lambda: 100, files=None, tables=None):
        self.backup_gate = backup_gate
        self.audit = audit
        self.now = now
        self.files = dict(files or {})
        self.tables = {name: list(rows) for name, rows in (tables or {}).items()}
        self.used_grants = set()
        self.runtime_authorized = False
        self.deploy_enabled = False
        self.production_verified = False

    def dispatch(self, actor, operation, run_id, backup_evidence=None, grant=None):
        kind = operation.get("kind") if type(operation) is dict else None
        target = operation.get("target") if type(operation) is dict else None
        reason = "invalid_operation"
        destructive = kind in DESTRUCTIVE
        valid = type(operation) is dict and set(operation) == ({"kind", "target", "content"} if kind in {"create", "overwrite"} else {"kind", "target"})
        if valid and kind in KINDS and isinstance(run_id, str) and RUN_ID.fullmatch(run_id):
            valid = safe_path(target) if kind in {"create", "remove", "overwrite"} else (isinstance(target, str) and TABLE.fullmatch(target) is not None)
            if kind in {"create", "overwrite"}:
                valid = valid and isinstance(operation["content"], str) and len(operation["content"].encode("utf-8")) <= 4096

        if not valid or kind not in KINDS:
            reason = "invalid_operation"
        elif actor != "owner":
            reason = "owner_authority_missing"
        elif not self.backup_gate(run_id, backup_evidence):
            reason = "backup_not_verified"
        elif kind == "create" and target in self.files:
            reason = "overwrite_requires_explicit_grant"
        elif kind == "remove" and target not in self.files:
            reason = "target_missing"
        elif kind == "overwrite" and target not in self.files:
            reason = "target_missing"
        elif kind in {"drop_table", "truncate_table"} and target not in self.tables:
            reason = "target_missing"
        elif destructive and not self._grant_valid(actor, operation, run_id, grant):
            reason = "one_time_owner_approval_required"
        else:
            reason = "synthetic_only"

        receipt = {"actor": actor if actor == "owner" else "untrusted", "kind": kind if kind in KINDS else "denied",
                   "target": target if valid else "[INVALID]", "run_id": run_id if isinstance(run_id, str) and RUN_ID.fullmatch(run_id) else "[INVALID]",
                   "status": "PLANNED" if reason == "synthetic_only" else "DENIED", "reason": reason}
        try:
            self.audit(receipt)
        except Exception:
            return {"ok": False, "reason": "audit_unavailable", "runtime_authorized": False}

        if reason != "synthetic_only":
            return {"ok": False, "reason": reason, "runtime_authorized": False}
        # Consume approval once, before applying a synthetic destructive operation.
        if destructive:
            self.used_grants.add(grant["approval_id"])
        if kind in {"create", "overwrite"}:
            self.files[target] = operation["content"]
        elif kind == "remove":
            del self.files[target]
        elif kind == "drop_table":
            del self.tables[target]
        elif kind == "truncate_table":
            self.tables[target] = []
        return {"ok": True, "reason": "synthetic_only", "runtime_authorized": False}

    def _grant_valid(self, actor, operation, run_id, grant):
        return (type(grant) is dict and set(grant) == {"approval_id", "approved_by", "actor", "run_id", "fingerprint", "expires_at", "decision"}
                and isinstance(grant.get("approval_id"), str)
                and re.fullmatch(r"[a-zA-Z0-9_-]{12,80}", grant["approval_id"]) is not None
                and grant["approval_id"] not in self.used_grants
                and grant["approved_by"] == "owner" and grant["actor"] == actor == "owner"
                and grant["run_id"] == run_id and grant["fingerprint"] == fingerprint(operation, run_id)
                and type(grant["expires_at"]) is int and self.now() < grant["expires_at"] <= self.now() + 300
                and grant["decision"] == "GRANTED")
# END HOSTINGER_ADDITIVE_ONLY_CONTRACT
```

## Relación con el gate de respaldo #1359

`tests/test_hostinger_additive_only.py` ejercita el `BackupGate` PHP real con el fixture B2 sintético de `tests/test_deploy_backup_gate.py`. Su veredicto de backup se inyecta en el simulador en memoria; el test valida que un recibo alterado, una restauración corrupta o una copia no verificada bloquean también las creaciones aditivas.

**La marca de test `SATISFIED` es solo una precondición sintética.** `runtime_authorized=false`, `deploy_enabled=false`, `production_verified=false` siempre. Hostinger real y Backblaze B2 siguen UNKNOWN y no están autorizados.

## Aceptación y reversión

`docs/hostinger-additive-only.md::Hostinger-additive-onlyTests::test_criterio_principal` es el nombre canónico exigido por #1369; la clase dinámica y método reales se definen en `tests/test_hostinger_additive_only.py`.

Ejecutar `python3 -m unittest tests.test_hostinger_additive_only -v` y luego `CI Factory v1 / Validar` sobre HEAD exacto. Reversión: revertir los dos archivos de documentación/prueba; no modifica código runtime ni credenciales. El resultado offline **no** concede permisos del dueño para un deploy real.
