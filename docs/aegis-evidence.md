# AEGIS Evidence v1

## Objetivo

`AegisEvidence` transforma evidencia normalizada en un contrato read-only de resiliencia, compliance e impacto. No consulta providers ni afirma cumplimiento, recuperación o causalidad cuando falta evidencia suficiente.

## Límite de autoridad

Este slice no ejecuta restores, remediaciones, cambios de infraestructura ni asesoría jurídica. Solo valida, normaliza y ordena referencias de evidencia. Los estados `unknown` y `stale` se conservan de forma fail-closed.

## Scope principal

Cada evaluación pertenece a un scope canónico `venture:<slug>`, `project:<slug>` o `institution:<slug>`. Las obligaciones de compliance y las señales de continuidad deben coincidir con ese scope.

Los incidentes pueden enlazar varios scopes afectados porque un impacto técnico puede cruzar venture, proyecto e institución. Ese enlace describe impacto observado; no declara causalidad.

## Compliance

Cada obligación conserva `obligation_id`, `state`, `reported_state`, `scope`, `evidence_refs`, `source_ref`, `observed_at`, `freshness`, `version`, `owner_ref`, `review_at` y `expires_at`.

Los estados permitidos son `compliant|gap|unknown|not_applicable`. Todas las obligaciones requieren evidencia explícita. Un estado reportado `compliant` solo permanece `compliant` con evidencia `fresh`; con evidencia `stale` o `unknown`, el estado normalizado pasa a `unknown`. La ausencia de evidencia se rechaza.

`expires_at` no puede preceder `review_at`.

## Backup y restore verification

Son evidencias independientes:

- backup: `healthy|degraded|failed|unknown`;
- restore: `verified|failed|unknown`.

Ambas conservan `reported_state`, referencias, timestamp y freshness. Evidencia no fresca normaliza el estado a `unknown`. Un backup `healthy` nunca implica restore `verified`.

## Impacto de incidentes

Un incidente conserva `incident_id`, `severity`, `capabilities`, `affected_scopes`, `evidence_refs`, `source_ref`, `observed_at` y `freshness`.

`capabilities` y `affected_scopes` son conjuntos explícitos, deduplicados y ordenados. El contrato no contiene un campo de causa ni intenta inferir causalidad.

## Secret-free

El esquema es cerrado. Campos extra como payloads crudos se rechazan. IDs y referencias rechazan patrones sensibles como passwords, secrets, tokens, cookies, bearer credentials, private/API keys, OTP, recovery codes, sesiones y credenciales. Las referencias son opacas y usan el prefijo `controlbot:`.

## Determinismo

Obligaciones e incidentes se ordenan por ID; capabilities, scopes y evidence refs se deduplican y ordenan. El mismo input lógico produce la misma salida aunque cambie el orden de las listas.

## Contrato ejecutable

`tests/test_aegis_evidence.py` cubre AC-01..AC-07 del Issue #157 mediante `tests/aegis_evidence_scenarios.php`.

## Fuera de alcance

No incluye asesoría jurídica automática, providers, restore real, postmortem completo, UI, remediación ni nuevas decisiones de autoridad.
