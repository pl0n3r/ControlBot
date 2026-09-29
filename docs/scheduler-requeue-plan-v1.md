# SchedulerRequeuePlan v1

`SchedulerRequeuePlan` convierte una pérdida real de heartbeat en un plan de requeue fail-closed. Es puro: no persiste, no selecciona otra Session y no ejecuta Runner.

## Trigger y guards

Solo continúa si `PresenceAdapter::transitionEvent(before, after, session_id)` produce `type=stale` para la misma Session. Después reutiliza `PresenceAdapter::replanGuard(after, session_id, generation, 'reassign')`.

Falla cerrado ante generation distinta, recovery/no-change/leave y `non_preemptible` fuera de `safe_point`. No interpreta ausencia de señal como capacidad libre.

## Ownership

Antes del plan deben coincidir:
- WorkItem activo `assigned|running|review`;
- Reservation activa con mismo `reservation_id`, Session y generation;
- Assignment con misma Session, project y source;
- Handoff con misma Assignment, `from_session_id`, `issue_ref` y sin `to_session_id`.

Cualquier mismatch aborta.

## Resultado y fence

La salida marca la Reservation previa `active=false`, devuelve el WorkItem a `queued`, limpia Reservation/Session e incrementa exactamente una vez `generation` y `attempt`. Preserva el Handoff saneado, expone old→new generation/attempt y un fingerprint determinista ligado al evento stale.

`ownerEventGuard()` solo acepta eventos cuya Session y generation coinciden con el owner vigente de un WorkItem activo. Tras requeue, el owner anterior queda fenced.

La nueva generation vuelve al flujo SchedulerSelection → AssignmentPlan → AssignmentCommit. Fuera de alcance: DB/SQL, selección, cancelación remota, ranking, Weekly Focus, UI, red, shell y mutación externa. Reversión: eliminar las cuatro rutas del slice.
