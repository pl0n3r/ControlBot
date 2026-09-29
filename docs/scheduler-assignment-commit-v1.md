# SchedulerAssignmentCommit v1

`SchedulerAssignmentCommit` convierte el plan puro de #350 en un **CommitSet CAS lógico**. No persiste nada: define exactamente qué estado debe seguir vigente y qué cuatro records deben escribirse juntos cuando exista un adapter durable.

## Provenance y CAS

El módulo no acepta un array de plan arbitrario. `PreparedSchedulerAssignmentPlan::fromSelection()` ejecuta `SchedulerAssignmentPlan::plan()`, que a su vez exige una `ValidatedSchedulerSelection` emitida mediante `SchedulerSelection::validateDecision()`.

En commit se vuelven a normalizar WorkItem, Session y Agent autoritativos. Sus fingerprints deben coincidir byte-for-byte con las precondiciones del plan. Además se recalcula health con `now` y TTL inyectados: un heartbeat que envejeció después de preparar el plan invalida el commit aunque el record no haya cambiado.

## CommitSet

La salida contiene únicamente:

- `expected`: fingerprints de WorkItem, Session, Agent y CAS combinado;
- `writes`: Reservation, WorkItem assigned, Session assigned y Assignment;
- `plan_fingerprint`: liga la transición al plan preparado;
- `commit_fingerprint`: fingerprint determinista de todo el CommitSet.

Los cuatro writes son un único conjunto contractual. No existen operaciones parciales, `applied=true`, estado intermedio exitoso ni takeover implícito. El adapter durable futuro debe comparar `expected` dentro de su transacción y aplicar los cuatro records juntos o ninguno.

## Fallos cerrados

Se rechazan drift de WorkItem/Session/Agent, ownership aparecido después del plan, Session no-idle o stale, pérdida de capabilities, plan estructural fabricado, fingerprint inválido y cualquier proyección que cambie source, generation, attempt o Session.

## Límite

Este slice no introduce MariaDB, SQL, locks persistentes, endpoints, red, shell, RunnerGateway ni FactoryRunner. Tampoco incrementa generation/attempt ni implementa heartbeat recovery, requeue, handoff, ranking, Weekly Focus o circuit breaker.

Reversión: eliminar las cuatro rutas del slice; no existe estado persistente que restaurar.
