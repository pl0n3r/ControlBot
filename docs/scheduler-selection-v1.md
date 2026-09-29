# SchedulerSelection v1

`SchedulerSelection` conecta el `SchedulerCore` de ControlBot con una decisión producida por la política canónica `factory-dispatcher-v2` sin copiar ranking, autoridad ni heurísticas de Factory al producto.

## Límite

Entrada 1: candidatos creados por `SchedulerCore::candidate()`. El adaptador valida shape, `policy_ref`, readiness, generation y capabilities, ordena por `key` y calcula un fingerprint SHA-256 estable. Permutar la misma colección no cambia la solicitud.

Entrada 2: una `SelectionDecision` externa con `policy_ref`, fingerprint, `selected_key`, razón y telemetría mínima. Antes de aceptarla, ControlBot recompone la solicitud con la readiness vigente. Cualquier drift invalida la decisión.

Salida: la selección validada y el snapshot exacto del candidato elegido. `generation` y `required_capabilities` salen del candidato vigente; la decisión externa no puede suministrarlos ni ampliar authority.

## Contratos fail-closed

- Solo `factory-dispatcher-v2` es aceptado.
- Keys duplicadas, shapes extra, candidatos desconocidos y no-ready fallan cerrado.
- Readiness actual distinta de la fingerprint original invalida la selección.
- La telemetría se limita a `ready_not_selected` y `excluded`, y debe coincidir exactamente con la solicitud actual.
- `selection_reason` es texto corto y saneado; secretos y PII obvia se rechazan.

## Lo que deliberadamente no hace

No clasifica HEALTH/INCIDENT, no desempata prioridades, no implementa anti-starvation y no invoca Factory. Tampoco contiene DB, red, shell, persistencia, reservas, assignment, requeue o mutaciones de Session. La jerarquía y selección siguen perteneciendo a Dispatcher V2 en Factory.

## Reversión

El módulo es puro y aditivo. Revertir estas cuatro rutas elimina el boundary de selección sin migraciones, datos persistentes ni efectos de producción.
