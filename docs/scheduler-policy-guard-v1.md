# SchedulerPolicyGuard v1

Parent: ControlBot #58. Slice: #395.

## Propósito

SchedulerPolicyGuard valida invariantes de seguridad alrededor de una selección ya autorizada por Factory Dispatcher V2. No elige trabajo, no calcula score y no mantiene un orden alterno.

La provenance entra mediante ValidatedSchedulerSelection. El guard vuelve a vincular request_fingerprint, selected_key y los WorkItems canónicos antes de evaluar cualquier intención.

## Preemption

Una selección protegida es un WorkItem de tipo incident o health, o con prioridad critical.

El guard puede producir una intención de preemption únicamente cuando:

- el WorkItem seleccionado está protegido;
- el WorkItem running no está protegido;
- identidad, ownership y generation permanecen vigentes;
- PresenceAdapter autoriza operation=preempt;
- non_preemptible ya alcanzó safe_point.

El resultado es una decisión pura allowed/reasons. Este módulo no cancela procesos, no reencola y no ejecuta side effects.

## Weekly Focus

Weekly Focus pertenece a #7 y se implementará después de Scheduler #58. Este slice solo deja el invariant de seguridad que #7 deberá respetar.

focus_version y focus_position son provenance opcional. focus_influenced=true exige ambos valores y nunca puede justificar que un WorkItem high/medium sea seleccionado mientras exista un WorkItem ready protegido dentro de la misma SelectionDecision.

El guard usa exclusivamente el ready set ya emitido por SchedulerSelection. Un candidato excluido sigue excluido; Weekly Focus no puede volverlo elegible.

## Autoridad

- Factory Dispatcher V2 selecciona.
- SchedulerSelection valida la decisión externa.
- SchedulerPolicyGuard valida invariantes de preemption/focus.
- PresenceAdapter gobierna generation y safe-point/preemptibility.
- SchedulerAssignmentPlan conserva la asignación y el fencing posteriores.

No existe select_next local, score, ranking alterno ni autoridad adicional.

## Fail closed y determinismo

Drift de request_fingerprint, selected_key, WorkItem identity/generation, focus provenance o presencia falla cerrado. Mismos inputs producen la misma salida y fingerprint.

El core no realiza DB, red, reloj implícito, shell ni filesystem write.
