# ControlBot — Factory backup preflight offline (#902)

## Alcance autorizado

Este comando es **una evaluación de fixtures sintéticos**, no un respaldo, restore,
recibo de seguridad productivo, sonda de Hostinger o autorización de go-live.
Se materializa exclusivamente por la [decisión humana #894/A](https://github.com/pl0n3r/ControlBot/issues/894#issuecomment-6419031160)
y el [journal bot v2/A](https://github.com/pl0n3r/ControlBot/issues/894#issuecomment-6419031160),
para preparar de forma reversible la línea bloqueada [#625](https://github.com/pl0n3r/ControlBot/issues/625).
No satisface el criterio **AC-04 de #760**, que exige señales productivas reales.

## Invocación controlada

Desde la raíz del checkout se proporciona un único objeto JSON **inventado** por stdin:

```sh
printf '%s\n' '{"kind":"synthetic","source_sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","artifact_sha256":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","baseline_verified":true,"artifact_verified":true,"rollback_verified":true}' |
  python3 ops/factory/backup --offline-dry-run
```

La salida `SYNTHETIC_ONLY` indica exclusivamente que la forma del fixture y
sus banderas de prueba pasaron validaciones locales. Las tres propiedades
`baseline_verified`, `artifact_verified` y `rollback_verified` son
afirmaciones **no autenticadas** del test; jamás pruebas de que existe copia real.

**Contrato de salida**: una sola línea JSON con `mode=offline_preflight`,
`status=SYNTHETIC_ONLY|REJECTED`, `reason` (valor fijo de allowlist),
`backup_verified=false`, `live_authorized=false` y `readiness=UNKNOWN`.
El comando no genera ni escribe receipts, artefactos ni estado durable.

**Comportamiento fail-closed**: argumentos distintos a `--offline-dry-run`
salen 2 con `mode_not_authorized`; JSON ausente/mayor a 8192 bytes, claves
duplicadas, formato erróneo, rutas/URLs/secretos extra, huellas mal formadas o
campos no booleanos salen 2 con `invalid_synthetic_fixture`. Baseline, artefacto
o rollback no afirmados producen respectivamente `baseline_unverified`,
`artifact_unverified` o `rollback_unverified`. Las tres comprobaciones
sintéticas afirmativas salen 0, sin conceder permiso a producción. No imprime
campos enviados por el llamador, errores ni valores del entorno.

## Seguridad y prohibiciones

El ejecutable usa solo `json`, `re` y `sys` de Python: **no** importación de
red, procesos hijos, SSH, HTTP, sistema de ficheros ni credenciales.
Nunca inicia jobs, comandos `gh`, consultas de Hostinger, conexiones, backups,
migraciones, deployments o restores. `GITHUB_SHA` real no es recibido ni
certificado. Una petición `--live`, `--real`, `--host` o cualquier otro modo
está prohibida.

No habilitar `DOMAIN`, `DEPLOY_ENABLED` ni derivar `BACKUP_VERIFIED` de la
salida de este comando. Antes de un backup productivo o despliegue se necesitan
contratos independientes de identidad de artefacto, autoridad D-059,
backup/rollback verificables, sonda owner-only autenticada, consentimiento
y controles de fase. No usar PII, claves, cookies o DSN en fixtures o logs.

## Pruebas de aceptación y reversión

```sh
python3 -m unittest tests/test_factory_backup_preflight.py
python3 -m unittest discover -s tests -p 'test_*.py'
```

Los métodos AC-01..04 del Issue #902 prueban los rechazos, la ausencia de
recibo productivo y ausencia de invocaciones a comandos externos usando
ejecutables señuelo dentro de un directorio temporal. CI `Validar`,
SonarCloud, CodeQL y CodeRabbit FINAL se evalúan sobre el HEAD exacto del PR.

**Rollback**: revertir el squash de #902 por la vía de PR protegida. No hay
migraciones, datos, servicios ni efectos exteriores que restaurar. El incidente
#760 y el entregable #625 permanecen abiertos hasta validar producción
verdadera con evidencia independiente, nunca por un fixture verde.
