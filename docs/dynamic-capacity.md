# Dynamic Capacity E2E

Este documento fija el escenario de cierre de #199. No introduce otro runtime, bridge productivo ni scheduler: compone los contratos ya integrados de `RuntimeCapacitySignal`, `AgentRuntime`, `PresenceAdapter` y `SchedulerCore`.

## Cadena autoritativa

```text
señal AutoFactory/FactoryRunner
  -> RuntimeCapacitySignal::normalize()
  -> seam exclusiva de test (campos permitidos)
  -> PresenceAdapter (freshness + estado + assignment + generation)
  -> idle_capacity autoritativa
  -> SchedulerCore (dependencias + claims + concurrencia)
  -> dispatchable_capacity
  -> replan / handoff
  -> generation fencing
  -> recovery + recompute
```

`Account.capacity` permanece como dato declarado compatible. El E2E usa deliberadamente una capacidad declarada de 1 y una señal normalizada con capacidad observada 4 para demostrar que el techo operativo no proviene del plan. La seam del test no es código productivo: traduce únicamente los campos saneados del contrato #215 a fixtures que ya acepta Presence.

## Escenario reproducible

1. **Join healthy.** AutoFactory emite una señal `idle` que pasa por `RuntimeCapacitySignal::normalize()`. La señal anuncia capacidad total 4 y heartbeat fresco; la seam de test construye el fixture Presence y Scheduler puede despachar un WorkItem.
2. **Working.** La misma sesión emite una señal normalizada `working` con `assignment_ref`. Una lane queda ocupada y la capacidad idle disminuye.
3. **Degradación.** Se normalizan señales `rate_limited` y `stale`. En ambos casos la capacidad nueva cae a 0, pero assignment y WorkItem permanecen atribuibles para replan.
4. **Handoff.** `AgentRuntime::handoff()` documenta el paso de `session-a` a `session-b`. `SchedulerCore::readiness()` demuestra owner A/reserva generation 1 antes del replan y owner B/reserva generation 2 después; una reserva stale produce `stale_reservation_generation`/`stale_owner` y dos reservas activas fallan cerrado.
5. **Fencing.** `PresenceAdapter::replanGuard()` rechaza recovery con generation vieja. El owner vigente generation 2, alimentado por señal FactoryRunner normalizada, puede recuperar cuando vuelve a estar healthy.
6. **Recovery.** El snapshot healthy recalcula la capacidad desde la observación actual; Scheduler vuelve a consumir esa misma `idle_capacity`.

## Invariantes

- `RuntimeCapacitySignal` sanea el input externo; la seam de test no toma decisiones operativas. Presence sigue siendo la única fuente numérica de `idle_capacity`; Scheduler no la reconstruye desde plan/provider/accounts.
- `degraded` y `unknown` son estados globales explicables y no implican por sí solos que toda capacidad sana de otras cuentas desaparezca.
- Un WorkItem tiene un solo owner vigente; Scheduler rechaza reservation/owner stale y múltiples owners activos, mientras generation fencing impide recuperar ownership stale.
- No hay ranking, backlog o scheduler paralelo a Factory Dispatcher V2.
- El escenario es puro: sin red, DB, reloj implícito, browser automation ni producción.
- Fixtures y salida no contienen credenciales, cookies, tokens, transcripts ni chain-of-thought.
- Los timestamps son explícitos, por lo que dos ejecuciones producen exactamente la misma evidencia.

## Evidencia

La suite `tests/test_dynamic_capacity_e2e.py` mapea AC-01..AC-06 directamente. El escenario PHP devuelve snapshots y guards completos para que una regresión de capacidad, ownership o sanitización sea observable y reproducible.
