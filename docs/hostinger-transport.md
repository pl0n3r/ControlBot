# ControlBot #1368 — contrato único de transporte Hostinger

**Estado:** contrato offline ejecutable y regresiones, sin red ni acceso a producción. Los dos adaptadores productivos ya existentes son `src/HostingerSshRuntime.php` / `src/OpenSshClient.php` y `src/HostingerPublicApiRuntime.php` / `src/HostingerPublicApiAdapter.php`. Su autoridad de credenciales es `src/SecretsBroker.php`. Este PR no crea un runtime productivo nuevo: solo el contrato común probado y su procedimiento seguro de integración, dentro de los dos claims del issue.

## Política de permisos mínimos

`dispatch(actor, operation)` usa `transport=ssh|api` **solo si** `enabled=true`; valor inicial `none` y desactivado. No hay fallback de una modalidad a otra. Allowlist estático: SSH únicamente `ssh.readonly`, API únicamente `hostinger.read` o `cron.snapshot`; nunca `cron.write`, `dns.write`, deploy, shell o operaciones desconocidas. Rechazar antes de leer credenciales; no aceptar rutas, comandos, URLs, huellas ni hosts provenientes del caller.

Usar **solo** `HOSTINGER_SSH_KEY_FILE` y `HOSTINGER_API_TOKEN_FILE`; el broker debe comprobar archivo regular, propietario, permisos `0600`, ausencia de enlaces simbólicos y límite de tamaño antes de resolverlo dentro del callback efímero. Nunca incluir credenciales en logs, UI, respuesta o issue. Para producción se conserva `SecretReference`, `CapabilityPolicy`, `CapabilityGrant`, `ProductionAuthority` y el `SecretsBroker` PHP del repositorio. El fragmento Python es una especificación ejecutable de seguridad, **no** un adaptador de producción.

SSH exige huella del servidor previamente autorizada; el adaptador debe validar `StrictHostKeyChecking` y host-key pinning antes del primer comando, con el `OpenSshClient` existente. La API solo permite HTTPS + GET hacia endpoints tipados de `HostingerPublicApiAdapter`, nunca métodos o paths arbitrarios. Toda solicitud crea un recibo mínimo **actor, operación, transporte, fecha, resultado y motivo** incluso al denegar; falla de auditoría = error cerrado.

## Contrato offline ejecutable (sin sockets)

El bloque siguiente se importa solo por el test con fakes, no por PHP ni por un servicio de producción.

```python
# BEGIN HOSTINGER_TRANSPORT_CONTRACT
import os
from pathlib import Path
import re
import stat

OPERATIONS = {"ssh": frozenset({"ssh.readonly"}), "api": frozenset({"hostinger.read", "cron.snapshot"})}
FILES = {"ssh": "HOSTINGER_SSH_KEY_FILE", "api": "HOSTINGER_API_TOKEN_FILE"}


class FileSecretsBroker:
    """Límite de SecretsBroker en el contrato offline: callback sin exponer credenciales."""

    def __init__(self, private_dir, server_env):
        self.private_dir = Path(private_dir)
        self.server_env = dict(server_env)

    def use(self, mode, callback):
        name = self.server_env.get(FILES[mode])
        if not isinstance(name, str) or not name.startswith("/") or ".." in Path(name).parts:
            raise ValueError("secret_file_invalid")
        path = Path(name)
        if path.parent != self.private_dir or path.name in {"", ".", ".."}:
            raise ValueError("secret_outside_private_root")
        directory_fd = os.open(self.private_dir, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try:
            root_info = os.fstat(directory_fd)
            if (root_info.st_uid != os.geteuid() or stat.S_IMODE(root_info.st_mode) & 0o077):
                raise ValueError("private_root_permissions_invalid")
            fd = os.open(path.name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=directory_fd)
            try:
                details = os.fstat(fd)
                if (not stat.S_ISREG(details.st_mode) or stat.S_IMODE(details.st_mode) != 0o600
                        or details.st_uid != os.geteuid() or details.st_nlink != 1):
                    raise ValueError("secret_permissions_invalid")
                with os.fdopen(fd, "rb", closefd=False) as stream:
                    payload = stream.read(4097)
                if len(payload) < 12 or len(payload) > 4096:
                    raise ValueError("secret_size_invalid")
                secret = payload.decode("utf-8")
                response = callback(secret)
                return self._redact(response, secret)
            finally:
                os.close(fd)
        finally:
            os.close(directory_fd)

    @classmethod
    def _redact(cls, value, secret):
        if isinstance(value, str):
            return value.replace(secret, "[REDACTED]")
        if isinstance(value, dict):
            return {key: "[REDACTED]" if re.search(r"(?i)token|secret|password|private_key|authorization", str(key))
                    else cls._redact(item, secret) for key, item in value.items()}
        if isinstance(value, list):
            return [cls._redact(item, secret) for item in value]
        return value if isinstance(value, (int, bool, float, type(None))) else "[REDACTED]"


class HostingerTransport:
    """Selector estricto de adaptadores inyectados; sin fallback de red."""

    def __init__(self, config, broker, ssh_adapter, api_adapter, audit, clock):
        self.enabled = config.get("enabled", False) is True
        self.mode = config.get("transport", "none")
        self.pinned_fingerprint = config.get("expected_fingerprint")
        self.broker = broker
        self.adapters = {"ssh": ssh_adapter, "api": api_adapter}
        self.audit = audit
        self.clock = clock

    def dispatch(self, actor, operation):
        safe_actor = actor if isinstance(actor, str) and re.fullmatch(r"[A-Za-z][A-Za-z0-9_-]{1,47}", actor) else "[invalid]"
        mode = self.mode if self.mode in OPERATIONS else "none"
        response = {"ok": False, "reason": "denied", "result": None}
        if safe_actor == "[invalid]" or not isinstance(operation, str):
            response["reason"] = "invalid_request"
        elif not self.enabled or mode == "none":
            response["reason"] = "transport_disabled"
        elif operation not in OPERATIONS[mode]:
            response["reason"] = "operation_denied"
        elif mode == "ssh" and (not isinstance(self.pinned_fingerprint, str)
                                or not re.fullmatch(r"SHA256:[A-Za-z0-9+/]{20,88}={0,2}", self.pinned_fingerprint)):
            response["reason"] = "host_fingerprint_missing"
        else:
            try:
                def perform(secret):
                    adapter = self.adapters[mode]
                    if mode == "ssh":
                        if adapter.verify_host(self.pinned_fingerprint) is not True:
                            raise ValueError("host_fingerprint_mismatch")
                        return adapter.readonly_probe(secret)
                    return adapter.read(operation, secret)
                response = {"ok": True, "reason": "executed", "result": self.broker.use(mode, perform)}
            except Exception:
                response = {"ok": False, "reason": "transport_or_secret_rejected", "result": None}
        receipt = {"actor": safe_actor, "operation": operation if isinstance(operation, str)
                   and operation in (OPERATIONS["ssh"] | OPERATIONS["api"]) else "[denied]",
                   "transport": mode, "at": self.clock(), "ok": response["ok"], "reason": response["reason"]}
        try:
            self.audit(receipt)
        except Exception:
            return {"ok": False, "reason": "audit_unavailable", "result": None}
        return response
# END HOSTINGER_TRANSPORT_CONTRACT
```

## Pruebas y autorización

Ejecutar `python3 -m unittest discover -s tests -p 'test_hostinger_transport.py' -v`. Caso principal exacto solicitado por el issue: **`docs/hostinger-transport.md::Hostinger-transportTests::test_criterio_principal`**. La suite define ese nombre de clase de forma dinámica para que `unittest` lo encuentre y verifica adapters SSH/API simulados, deny-by-default, allowlist, fichero secreto privado, huella SSH, redacción y auditoría. No abre sockets ni utiliza Hostinger real.

**No habilita go-live.** Un trabajo posterior que reclame expresamente `src/**` deberá conectar esta interfaz con los adapters PHP ya presentes, con autoridad y grants exactos, `SecretReference` y SecretsBroker auténticos, un registro de auditoría persistente, aprobación humana y gates de live. Tampoco despliega, modifica DNS, compra servicios ni mueve credenciales. Reversión: revertir este squash de documentación/pruebas; el runtime queda inalterado.
