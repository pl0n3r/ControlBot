# Dynamic Capacity E2E

Este documento fija el escenario de cierre de #199. No introduce otro runtime ni otro scheduler: compone los contratos ya integrados de `AgentRuntime`, `PresenceAdapter` y `SchedulerCore`.

## Cadena autoritativa

```text
capacidad observada del provider/account
  -> PresenceAdapter (freshness + estado + assignment + generation)
  -> idle_capacity autoritativa
  -> SchedulerCore (dependencias + claims + concurrencia)
  -> dispatchable_capacity
  -> replan / handoff
  -> generation fencing
  -> recovery + recompute
```

`Account.capacity` permanece como dato declarado compatible. El E2E usa deliberadamente una capacidad declarada de 1 y una observación real de 4 para demostrar que el techo operativo no proviene del plan.

## Escenario reproducible

1. **Join healthy.** `session-a` entra idle, con heartbeat fresco. La observación anuncia capacidad total 4. Presence calcula capacidad confiable y Scheduler puede despachar un WorkItem.
2. **Working.** La misma sesión toma `ControlBot#217`. Una lane queda ocupada y la capacidad idle disminuye.
3. **Degradación.** Se prueban dos señales: provider `rate_limited` y heartbeat stale. En ambos casos la capacidad nueva cae a 0, pero assignment y WorkItem permanecen atribuibles para replan.
4. **Handoff.** `AgentRuntime::handoff()` documenta el paso de `session-a` a `session-b`. La nueva presencia usa generation 2 y mantiene un único owner de la asignación.
5. **Fencing.** Un recovery con generation vieja es rechazado. Un owner vigente con generation 2 puede recuperar cuando vuelve a estar healthy.
6. **Recovery.** El snapshot healthy recalcula la capacidad desde la observación actual; Scheduler vuelve a consumir esa misma `idle_capacity`.

## Invariantes

- Presence es la única fuente numérica de `idle_capacity`; Scheduler no la reconstruye desde plan/provider/accounts.
- `degraded` y `unknown` son estados globales explicables y no implican por sí solos que toda capacidad sana de otras cuentas desaparezca.
- Un WorkItem tiene un solo owner vigente; generation fencing impide recuperar ownership stale.
- No hay ranking, backlog o scheduler paralelo a Factory Dispatcher V2.
- El escenario es puro: sin red, DB, reloj implícito, browser automation ni producción.
- Fixtures y salida no contienen credenciales, cookies, tokens, transcripts ni chain-of-thought.
- Los timestamps son explícitos, por lo que dos ejecuciones producen exactamente la misma evidencia.

## Evidencia

La suite `tests/test_dynamic_capacity_e2e.py` mapea AC-01..AC-06 directamente. El escenario PHP devuelve snapshots y guards completos para que una regresión de capacidad, ownership o sanitización sea observable y reproducible.
