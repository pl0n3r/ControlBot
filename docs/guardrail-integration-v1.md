# Guardrail Integration v1

`GuardrailIntegration` conecta #109 con Runtime, Scheduler y Observability sin duplicar autoridad. `ExecutionGuardrail::analyze()` sigue decidiendo salud, fingerprint, pausa y escalamiento.

Un stuck preemptible fuera de safe point solo emite una intención tipada `runtime.session.pause` en espera. Con safe point, la intención pide activación pero **no declara la pausa aplicada**: el `PauseState` validado por `PauseControl::state()` permanece `unknown` y sin `activated_at`/evidencia fabricada. La ejecución externa debe devolver un receipt de pausa antes de aceptar checkpoint o requeue.

El checkpoint/handoff se valida con `AgentRuntime`. El ownership falla cerrado si la Session no está en un estado ejecutable, si el Assignment terminó o si `Session.repository + issue_number` no coincide con `WorkItem.source_ref`. El WorkItem candidato se valida con `SchedulerCore` y proyecta generation/attempt +1, sin owner ni reserva. El requeue queda tipado como `scheduler.work_requeue`, cercado por generation/attempt y condicionado explícitamente a receipts `pause → checkpoint`. `non_preemptible` solo escala y nunca solicita pausa automática.

Los eventos del owner anterior pasan por `SchedulerRequeuePlan::ownerEventGuard()`. La alerta usa una intención `observability.guardrail_alert` ligada al fingerprint del guardrail: misma causa mantiene una sola alerta, causa distinta reemplaza la anterior y estado healthy resuelve la alerta activa.

`reconcile()` exige evidencia ordenada `pause → checkpoint → requeue`. Cualquier ejecución parcial permanece `recoverable=true` y `success=false`; no se inventa éxito.

No hay DB, red, filesystem write, timers, provider/browser calls ni side effects. El componente solo proyecta intenciones y estado reconciliable.
