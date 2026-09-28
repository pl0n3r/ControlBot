# AEGIS Posture v1

## Objetivo

`AegisPosture` define el dominio read-only de postura de seguridad para ControlBot. Normaliza evidencia ya recolectada y produce una vista determinista por scope sin consultar providers, ejecutar remediaciones ni almacenar secretos.

## Límite de autoridad

Este slice no realiza acciones sobre producción. No crea credenciales, no llama APIs externas, no modifica infraestructura y no convierte señales incompletas en estados saludables. La autoridad de remediación pertenece a slices posteriores de AEGIS.

## Scope

Cada evaluación pertenece exactamente a un scope canónico:

- `venture:<slug>`
- `project:<slug>`
- `institution:<slug>`

Todos los findings y señales de una evaluación deben declarar el mismo scope. Una mezcla de scopes se rechaza en vez de agregarse silenciosamente.

## Findings

Cada finding conserva:

- `finding_id`
- `category`
- `severity`
- `scope`
- `state`
- `evidence_refs`
- `source_ref`
- `observed_at`
- `freshness`

Los campos están cerrados por esquema. Referencias con patrones de secretos, tokens, credenciales, cookies, claves privadas, OTP o recovery codes son rechazadas. La evidencia se conserva como referencias opacas `controlbot:...`, nunca como valor sensible.

Findings repetidos con el mismo `finding_id` solo se fusionan cuando el resto de sus campos estructurales coincide. Sus `evidence_refs` se deduplican y ordenan determinísticamente. Un conflicto para el mismo ID falla cerrado.

## Identity y acceso privilegiado

Las métricas soportadas en v1 son:

- `mfa_coverage`
- `privileged_identities`
- `orphan_accounts`

Un valor solo se expone como `known` cuando la evidencia es simultáneamente `authoritative` y `fresh`. Evidencia derivada, desconocida o stale produce `state=unknown` y oculta el valor numérico.

## Vulnerabilidades

Las señales de vulnerabilidad conservan `severity`, conteo, fuente, timestamp y freshness. Un conteo cero solo produce `clear` con evidencia fresca. Si freshness es `stale` o `unknown`, el estado derivado es `unknown`, nunca healthy/clear.

## Continuidad

Backup y restore verification son señales distintas:

- `backup_signal`: `available|failed|unknown`
- `restore_signal`: `verified|failed|unknown`

Que exista un backup no implica que una restauración haya sido verificada.

## Estado derivado

`posture_state` usa tres estados:

- `attention_required`: findings/vulnerabilidades high o critical activas, backup fallido o restore fallido.
- `unknown`: existe evidencia stale/unknown o una señal no autoritativa que impide afirmar el estado.
- `observed`: no hay atención requerida ni incertidumbre en las señales entregadas.

La ausencia de certeza nunca se transforma en un estado saludable.

## Determinismo

La salida se ordena por identificadores/metric/severity. Reordenar los mismos inputs normalizados produce exactamente la misma estructura de salida.

## Pruebas

El contrato ejecutable vive en `tests/test_aegis_posture.py` y utiliza `tests/aegis_posture_scenarios.php`. Los siete tests corresponden 1:1 con AC-01..AC-07 del Issue #156.

## Fuera de alcance

No incluye DB, UI, collectors/providers reales, incident response, remediation, Factory WorkItems, Living feedback ni decisiones automáticas de compliance.
