# SchedulerAssignmentPlan v1

`SchedulerAssignmentPlan` es la frontera pura entre una selección ya validada por `SchedulerSelection` y la futura escritura atómica de reserva/asignación del Scheduler #58.

## Entrada y trust boundary

El plan recibe una selección `factory-dispatcher-v2`, el `WorkItem` vigente, una `Session` y su `Agent`, además de IDs de reserva/asignación suministrados por el caller. Reutiliza `SchedulerCore::workItem()` y `AgentRuntime` para normalizar los contratos existentes; no define un segundo modelo de WorkItem, Session o Assignment.

Antes de producir salida vuelve a ligar la selección con el estado vigente: `key`, `source_ref`, `priority`, `generation`, `required_capabilities` y `account_id` deben corresponder al WorkItem/Session observados. Solo se acepta un WorkItem `queued|eligible` sin ownership previo y una Session `idle`, healthy, sin assignment y perteneciente a un Agent que cubra todas las capabilities requeridas.

## Salida

El `AssignmentPlan` contiene:

- `preconditions`: fingerprints SHA-256 del WorkItem, Session y Agent observados, más un `cas_sha256` combinado;
- `reservation`: ownership de la Session sobre la misma `generation`;
- `work_item`: proyección a `assigned`, preservando `generation`, `attempt`, source y capabilities;
- `session`: proyección `idle → assigned`, ligada al `assignment_id` y al repo/Issue del WorkItem;
- `assignment`: contrato `AgentRuntime` en estado `assigned`;
- `selection_ref`: fingerprint de la solicitud canónica y key seleccionada;
- `fingerprint`: SHA-256 determinista del plan completo.

Los IDs se reciben como entrada para mantener el core determinista; este módulo no genera UUIDs ni consulta reloj, DB o red por su cuenta.

## Semántica CAS

Los fingerprints son **precondiciones**, no una transacción. El escritor del slice posterior deberá comparar el estado autoritativo inmediatamente antes del commit y aplicar Reservation + WorkItem + Session + Assignment como una única transición atómica. Si cualquier precondición cambió, debe fallar cerrado y reconstruir elegibilidad/selección; nunca reutilizar este plan para hacer takeover.

El fingerprint del Agent forma parte de CAS porque un cambio de capabilities entre plan y commit también invalida la asignación.

## Fallos cerrados

Se rechazan, entre otros:

- drift entre selección y WorkItem actual;
- WorkItem ya reservado/asignado o en estado no asignable;
- Session ocupada, stale/offline o de otra cuenta;
- Agent distinto al de la Session o sin capabilities suficientes;
- generation distinta;
- shapes/IDs inválidos detectados por los contratos canónicos existentes.

## Fuera de alcance

Este módulo no persiste, no bloquea filas, no ejecuta `RunnerGateway`, no llama FactoryRunner, no incrementa `generation`/`attempt`, no procesa heartbeat/requeue/handoff, no implementa ranking ni Weekly Focus y no contiene red, shell o cron.

La reversión consiste en eliminar este archivo, `src/SchedulerAssignmentPlan.php` y sus dos fixtures de prueba; no hay migraciones ni estado persistente.
