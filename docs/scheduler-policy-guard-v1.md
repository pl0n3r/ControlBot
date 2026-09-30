# SchedulerPolicyGuard v1

Parent: ControlBot #58 · Slice #395.

## Contrato
`SchedulerPolicyGuard` valida una selección ya autorizada por Factory Dispatcher V2. No elige trabajo, no calcula score/ranking y no modifica readiness. Revalida provenance mediante `ValidatedSchedulerSelection`, WorkItems canónicos y `PresenceAdapter`.

## Preemption
Solo una selección protegida (`incident`, `health` o prioridad `critical`) puede solicitar desplazar trabajo no protegido cuya prioridad declarada sea estrictamente inferior. Ownership, generation, assignment y presencia deben coincidir. `PresenceAdapter::replanGuard(..., "preempt")` conserva el gate de safe-point/preemptibility. El guard solo produce una intención pura; no cancela, reencola ni ejecuta.

## Weekly Focus
`focus_version`, `focus_position` y `focus_influenced` son provenance opcional. Si Focus influyó, un WorkItem ready protegido no puede quedar sin seleccionar. Un candidato excluido permanece excluido: Focus nunca cambia readiness.

## Autoridad y fallo cerrado
Factory selecciona; `SchedulerSelection` valida; este guard comprueba invariantes; `PresenceAdapter` preserva fencing. Drift de fingerprint, selected key, WorkItem, generation, Focus o presencia falla cerrado. Mismos inputs producen misma salida/fingerprint. Sin DB, red, reloj implícito, shell ni filesystem write.
