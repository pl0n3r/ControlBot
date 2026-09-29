# ObservabilityEvent v1

Primer slice ejecutable de Observability Fabric #56. Normaliza señales ya obtenidas por adapters
externos; no consulta proveedores, no persiste y no abre incidentes.

## Contrato

`ObservabilityEvent::normalize(raw, now, ttlBySource)` acepta únicamente `health`, `ci`, `deploy`
y `agent`. El input usa schema cerrado y la salida añade `fingerprint` y
`freshness = fresh|stale|unknown`.

El fingerprint representa la causa observable: incluye identidad, severidad, tipo, payload
allowlisted, `occurred_at` y correlation keys; excluye `received_at` y freshness para que un retry
del mismo evento se deduplique aunque llegue después.

Freshness usa exclusivamente `now`, `occurred_at` y TTL inyectado por source. TTL ausente produce
`unknown`; timestamps futuros o invertidos fallan cerrado.

## Seguridad

Payloads son mapas planos, bounded y con claves allowlisted por source. Se rechazan nested data,
campos extra, secretos/credenciales, PII directa y SQL. Correlation keys se validan, deduplican y
ordenan antes del fingerprint.

El fixture de #78 modela pérdida de capacidad del runner con `application_status=unknown`:
`startup_failure=true` y `steps=null` no se reinterpretan como un fallo de steps.

## Fuera de alcance

Incident lifecycle/correlación multi-evento, notificaciones, freeze/circuit breaker, Scheduler,
adapters reales, DB, red, shell, UI y postmortems.
