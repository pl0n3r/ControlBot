# Vendor Exception Signals v1

## Propósito

Esta proyección convierte condiciones materiales ya normalizadas por `VendorRegistry` en hechos ejecutivos consumibles por Cockpit/Owner Inbox. No clasifica la atención del Owner y no ejecuta procurement.

## Señales cerradas

- `renewal_due`
- `expiry_due`
- `health_degraded`
- `critical_unknown`
- `security_review_rejected`
- `legal_review_rejected`
- `missing_exit_plan`
- `missing_export_capability`

Las fechas `renewal_at` y `expiry_at` solo generan señal cuando existen y caen estrictamente antes del final de la ventana explícita `now + horizon_seconds`; el límite final es exclusivo. Fechas vencidas también permanecen visibles porque siguen siendo materialmente pendientes hasta que la fuente autoritativa cambie el estado.

## Materialidad

`missing_exit_plan` y `missing_export_capability` se emiten únicamente para proveedores `high|critical` en lifecycle `active|changing|offboarding`. El registro base ya exige owner_ref, por lo que no existe una señal separada de “owner missing”.

`critical_unknown` se emite cuando un proveedor critical tiene health/SLA unknown o freshness distinta de fresh. La salida conserva exactamente esos estados. No los traduce a incidente, outage ni healthy.

## Provenance

Cada señal conserva Venture/vendor scope, criticality, lifecycle, health/SLA, freshness, observed_at, source_ref y fechas. `evidence_refs` contiene únicamente referencias opacas relevantes. No se proyecta `credentials_ref` ni payload de contrato, PII o secretos.

## Límites de autoridad

Las señales no contienen priority_class, authority_level, budget/approval, decisión, severidad ejecutiva ni acción. #127/policy puede clasificarlas para Owner Inbox; #275 puede originar trabajo técnico cuando exista intención/policy explícita.

No hay compra, pago, renovación, offboarding, provider call, persistencia, scheduler ni Factory WorkItem en este contrato.
