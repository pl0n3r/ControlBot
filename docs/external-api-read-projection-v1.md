# External API Read Projection v1

## Objetivo

`ExternalApiReadProjection` materializa únicamente las respuestas públicas read-only ya definidas por `openapi/controlbot-owner-v1.json` para `cockpit.read` y `owner_inbox.read`.

No es un controller, collector, cache ni fuente de verdad. Recibe snapshots ya normalizados y produce envelopes públicos estrictos.

## Reglas

- El shape público sigue el OpenAPI v1; no se mantiene un segundo modelo paralelo.
- `request_id` y `correlation_id` son IDs hexadecimales de 32 caracteres.
- `generated_at` y timestamps presentes son enteros positivos.
- Freshness conserva exactamente `current | stale | unknown`.
- `unknown` exige `observed_at=null` y `source_ref=null`.
- `stale` nunca se promueve a `current`.
- Las referencias públicas usan el namespace `controlbot:`.
- Cockpit expone solo venture ref, estados business/technical y contadores.
- Owner Inbox expone solo la categoría, refs y copy mínimo definido por el contrato.
- Los objetos rechazan campos extra para evitar fugas de modelos internos.
- Claves o valores con señales de secretos/credenciales fallan cerrado.

## Límites

Este componente no:
- consulta DB o red;
- ejecuta Factory/FactoryRunner;
- persiste ni cachea;
- implementa HTTP routing;
- implementa iOS;
- inventa health, freshness o métricas.

La ausencia de evidencia debe representarse como `unknown`, no como healthy/current.
