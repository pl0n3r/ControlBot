# Observability Project Status v1

`ObservabilityProjectStatus` proyecta el estado observable por Proyecto sin consultar providers ni duplicar autoridad.

## Contrato

- Entrada: catálogo explícito de `project_id`, eventos raw, `now` y TTL por fuente.
- Cada evento se revalida con `ObservabilityEvent::normalize()`.
- Cada Proyecto siempre expone `health`, `ci`, `deploy` y `agent`.
- Ausencia se representa como `freshness=unknown`, nunca como estado sano.
- `stale` se conserva; la proyección no promueve evidencia vieja a `fresh`.
- Entre eventos de la misma fuente gana el `occurred_at` más reciente; un empate con fingerprints distintos falla cerrado.
- `evidence_ref` es opaca y deriva del fingerprint normalizado; no se copian payloads.
- Catálogo, proyectos y fuentes se ordenan determinísticamente, por lo que el orden de entrada no cambia el snapshot ni su fingerprint.

## Límites

El módulo no crea incidentes, policy, inbox, push, freeze, persistencia, red, DB, shell ni acciones del Scheduler. El routing y las acciones gobernadas permanecen en los contratos de #366/#466/#10.
