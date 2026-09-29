# External API Mobile State v1

## Objetivo

Este contrato hace explícito cuándo un snapshot consumido por un cliente móvil está fresh, stale, offline o unknown. No implementa cache real ni autorización. Su función es impedir que una UI móvil convierta evidencia vieja o desconectada en estado actual o en permiso implícito para mutar.

## Estado derivado

Cada snapshot declara una referencia de recurso, observed_at, received_at, max_age_seconds, source_ref y sensitivity.

- `fresh`: hay conectividad y la edad desde observed_at no supera max_age_seconds.
- `stale`: hay conectividad, pero la edad supera el máximo declarado.
- `offline`: existe evidencia temporal válida, pero no hay conectividad.
- `unknown`: no existe evidencia temporal/provenance suficiente.

Los tres campos de provenance temporal (`observed_at`, `received_at`, `source_ref`) son atómicos: existen juntos o están ausentes juntos. Evidencia parcial falla cerrado.

## Lectura

Un snapshot stale u offline puede mostrarse de forma read-only si la experiencia lo necesita, pero siempre conserva `state`, `age_seconds`, `observed_at` y `source_ref`. Solo `fresh` produce `current=true`.

## Mutaciones

`mutationPolicy()` evalúa únicamente el gate de freshness y la elegibilidad nominal para una futura cola offline. No autoriza la operación.

- Estado distinto de fresh produce `freshness_gate=fail`.
- Step-up requerido o autoridad alta nunca son queueable offline.
- Queueability offline requiere idempotencia y que el operation_id esté en una allowlist explícita suministrada por policy server-side.
- Fresh no se encola: la operación sigue su request gate normal.

El contrato no persiste ni ejecuta ninguna operación.

## Cache y sensibilidad

- `public`: puede permitirse cache persistente.
- `confidential`: cache persistente solo cuando el caller declara storage cifrado.
- `restricted`: cache persistente prohibida.

El contrato decide metadata/policy; no recibe ni guarda payloads del recurso.

## Límites

No implementa SQLite/CoreData/SwiftData, Keychain/Secure Enclave, background sync, push/APNs, provider SDKs, request adapters, Factory WorkItems ni execution plane.
