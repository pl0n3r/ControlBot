# Owner Briefing Delivery v1

`OwnerBriefingDelivery` completa #108 sin crear scheduler ni transporte paralelo. Recibe tick explícito, el snapshot de `OwnerBriefing::build()`, policy de canal y, opcionalmente, evidencia de una entrega anterior.

## Contrato
La identidad depende únicamente de `day + briefing_fingerprint`. El mismo briefing del mismo día conserva `delivery_ref` y `dedupe_key` aunque cambie el canal. Un receipt válido `delivered` para esa identidad hace que el retry devuelva `decision=suppress` con razón `duplicate`.

El planner nunca declara una entrega ocurrida: si es elegible devuelve `delivery_status=pending` y una `delivery_intent`; el transporte autorizado deberá producir después la evidencia `delivered`.

## Fail-closed
No se produce intención con tick `stale|unknown`, policy deshabilitada, canal no disponible, evidencia previa de la misma identidad o inputs/snapshot inválidos. El canal es abstracto (`email|push`), sin SMTP, push providers ni credenciales.

## Fronteras
No hay DB, red, filesystem write, provider calls, cron, polling, scheduler mutation ni clock global. Día y freshness llegan inyectados por el caller.

Observability y Scheduler siguen siendo autoridades externas. Este componente solo materializa una intención idempotente y verificable para una capa de ejecución autorizada.
