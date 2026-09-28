# LEX Core v1

## Propósito

LEX Core es el dominio read-only de Legal & Compliance dentro de AEGIS. Normaliza un Legal Registry atribuible a evidencia; no sustituye asesoría jurídica, no inventa obligaciones y no interpreta la ausencia de hallazgos como aprobación.

## Estados canónicos

Cada obligación termina exactamente en uno de cuatro estados: `compliant`, `gap`, `unknown` o `not_applicable`.

`unknown` es fail-closed. Si la evidencia se declara `stale` o `unknown`, o si una obligación ya expiró al tiempo de evaluación, el estado efectivo pasa a `unknown` aunque el productor haya reportado `compliant`.

Un `compliant` reportado exige al menos una referencia de evidencia. `not_applicable` exige una justificación explícita. Estas reglas impiden convertir ausencia de evidencia en cumplimiento.

## Legal Registry

Cada obligación conserva:

- `obligation_id` y `scope`;
- `market_id` opcional;
- `jurisdiction_pack_id` opcional y opaco para el core;
- `domain` y `requirement_ref`;
- `status` efectivo y `reported_status`;
- `source_refs[]` y `evidence_refs[]`;
- `observed_at`, `freshness` y `reported_freshness`;
- `responsible_ref`, `reviewed_at`, `expires_at` y `next_review_at`;
- `severity` y `human_review_required`;
- `justification_ref` y `assumption_refs[]`.

El core no conoce países. Un `jurisdiction_pack_id` es metadata versionada que será interpretada por el slice de Jurisdiction Packs; añadir Colombia u otro país no cambia la semántica central.

## Determinismo y deduplicación

Las obligaciones se ordenan por `obligation_id`; colecciones de referencias se deduplican y ordenan. Dos entradas idénticas con el mismo ID se colapsan; dos entradas incompatibles con el mismo ID se rechazan.

## Seguridad y minimización

El esquema es cerrado. IDs, scopes y refs tienen límites de longitud y rechazan patrones asociados a secretos, credenciales, tokens, cookies, OTP y claves privadas/API. LEX Core almacena referencias, no documentos jurídicos completos ni datos personales innecesarios.

## Resumen

`summary.counts` agrega los cuatro estados efectivos y `summary.human_review_required` cuenta obligaciones que exigen criterio humano. El core no produce un score, aprobación o conclusión legal agregada.

## Fuera de alcance

No incluye Jurisdiction Packs, lógica específica de Colombia, Legal Watch, navegación web, Factory WorkItems, UI, providers, decisiones humanas ni go-live.
