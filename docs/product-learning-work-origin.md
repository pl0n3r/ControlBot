# Product Learning → Factory Work Origin v1

Este bridge convierte ProductLearningSignal en un WorkItem compatible con Factory Queue #269. No llama Factory ni decide readiness, ranking, aprobación, presupuesto o ejecutor.

## Handoff

El bridge fuerza `origin_mode=automatic`, `origin_system=controlbot` y `producer_ref=controlbot:product-intelligence`. Venture y evidence refs provienen de la señal normalizada.

El caller/policy debe aportar explícitamente work ID, group, work type, capabilities, roles, authority level, priority class, dependencies, claims, policy ref y `observed_at`. La señal no puede inferir esos campos.

`idempotency_key` se deriva de signal ID + work type. Evidence refs incluyen signal, aggregate ref, source ref y evidence ref, ordenados de forma determinista.

## Factory sigue siendo autoridad

Unknown, inconclusive o stale pueden originar intención, pero ControlBot nunca añade `ready`, `approved` o `authorized`. Factory valida WorkItem, freshness/evidence, gates, deduplicación, readiness, dispatch y feedback.

Opcionalmente se preservan project, repository, budget y approval refs. El contrato no admite provider/model/executor ni campos de ejecución.

Este slice no persiste ni crea cola, scheduler o ranking local.
