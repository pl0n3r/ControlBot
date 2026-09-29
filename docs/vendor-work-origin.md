# Vendor Governance → Factory Work Origin v1

Este bridge convierte una intención explícita derivada de un `VendorRecord` normalizado en un WorkItem compatible con Factory Queue #269. No ejecuta procurement, compra, pago, renovación, provisión ni llamadas a proveedores.

## Handoff

`VendorWorkOrigin` ofrece dos entradas:

- `fromRisk`: materializa una intención derivada de riesgo/health/SLA/freshness/reviews;
- `fromLifecycle`: materializa trabajo de `changing | offboarding | exited`.

Ambas fuerzan `origin_mode=automatic`, `origin_system=controlbot` y `producer_ref=controlbot:vendor-governance`.

El caller/policy aporta explícitamente work ID, group, work type, capabilities, roles, authority level, priority class, dependencies, claims y policy ref. `criticality`, health, review state y fechas del vendor nunca otorgan prioridad, budget ni authority.

## Provenance y minimización

El bridge primero consume `VendorRegistry::normalize()`. `observed_at` se deriva solo de `freshness.observed_at`; freshness `unknown` falla cerrado porque no existe observación atribuible.

`evidence_refs` usa referencias opacas ya normalizadas: vendor, service, freshness source, CAPITAL y AEGIS/LEX; para lifecycle añade exit/export/offboarding. `credentials_ref` se excluye deliberadamente y no se copia PII, contrato, secreto o payload de terceros.

La idempotencia se deriva de `vendor_id + origin_kind + work_type`, por lo que el orden de capabilities/roles/claims no altera la identidad.

## Factory sigue siendo autoridad

Stale, degraded, pending o rejected pueden originar intención, pero ControlBot nunca emite `ready`, `approved` ni `authorized`. Factory #269 conserva validación, deduplicación, readiness, gates, ranking, dispatch y feedback.

`budget_ref` y `approval_ref` son referencias explícitas opcionales. Su presencia no autoriza gasto ni procurement.

Este slice no persiste, no agenda, no rankea, no crea cola paralela y no llama Factory/FactoryRunner.
