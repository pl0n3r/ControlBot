# SchedulerRequeuePlan v1

`SchedulerRequeuePlan` convierte una pérdida real de heartbeat en un plan de requeue fail-closed. No persiste ni selecciona otro agente: reutiliza los contratos canónicos de Presence y Agent Runtime para invalidar al owner anterior y devolver el WorkItem a la cola con un nuevo fence.

## Entrada autoritativa

El plan exige dos `PresenceSnapshot` consecutivos. Solo continúa cuando `PresenceAdapter::transitionEvent(before, after, session_id)` produce `type=stale`, es decir, la misma Session pasó de `healthy` a un freshness no saludable.

Después llama `PresenceAdapter::replanGuard(after, session_id, generation, 'reassign')`. Por tanto:

- una generation distinta falla cerrado;
- una Session `non_preemptible` fuera de `safe_point` no puede ser reasignada;
- la Assignment observada debe seguir siendo la misma.

No se interpreta ausencia de señal como capacidad libre.

## Ownership que debe coincidir

Antes de crear el plan se normalizan y ligan:

- WorkItem activo (`assigned|running|review`);
- Reservation activa con el mismo `reservation_id`, Session y generation;
- Assignment con la misma Session, project y source;
- Handoff con la misma Assignment, `from_session_id` y `issue_ref`, y todavía sin `to_session_id`.

Cualquier mismatch aborta el plan.

## Resultado

La salida contiene:

- `release_reservation`: misma Reservation, marcada `active=false`;
- `work_item`: vuelve a `queued`, limpia Reservation/Session y aumenta exactamente `generation+1` y `attempt+1`;
- `handoff`: registro saneado preservado para que la futura Session continúe con contexto atribuible;
- `fence`: old/new generation y attempt;
- fingerprint SHA-256 determinista ligado al evento stale.

El módulo no decide quién toma el siguiente trabajo. La nueva generation debe volver al flujo SchedulerSelection → AssignmentPlan → AssignmentCommit.

## Eventos tardíos

`ownerEventGuard()` solo permite eventos cuando Session y generation coinciden con el owner vigente de un WorkItem activo. Tras requeue el WorkItem queda sin owner y con generation nueva, por lo que un evento de la Session/generation anterior falla cerrado.

## Límite operativo

No hay DB, SQL, locks, red, shell, RunnerGateway, FactoryRunner, cancelación remota, ranking, Weekly Focus, preemption por prioridad ni mutación externa. Un adapter durable posterior deberá aplicar release + nuevo WorkItem de forma atómica.

Reversión: eliminar las cuatro rutas del slice; no existe estado persistente nuevo que restaurar.
