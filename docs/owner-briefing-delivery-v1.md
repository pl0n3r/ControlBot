# Owner Briefing Delivery v1

`OwnerBriefingDelivery` completa #108 sin crear scheduler ni transporte paralelo. Consume un tick explícito de Scheduler, el snapshot de `OwnerBriefing::build()`, una policy de canal y evidencia opcional de una entrega anterior.

## Contrato

La identidad depende solo de `day + briefing_fingerprint`. El mismo briefing del mismo día conserva `delivery_ref` y `dedupe_key` aunque cambie el canal. Un receipt válido `delivered` para esa identidad suprime el retry como `duplicate`.

El planner nunca afirma que el transporte ocurrió: un caso elegible queda `delivery_status=pending` con una única `delivery_intent`; los demás quedan `suppressed`.

## Fail-closed y fronteras

Tick `stale|unknown`, policy deshabilitada, canal no disponible, duplicado, snapshot inválido o provenance inválida no producen intención. El canal `email|push` es abstracto.

No hay DB, red, filesystem write, provider calls, cron, polling, scheduler mutation ni clock global. Scheduler/Observability siguen siendo autoridades externas y la evidencia `delivered` solo puede llegar después desde una capa de ejecución autorizada.
