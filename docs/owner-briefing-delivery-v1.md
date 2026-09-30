# Owner Briefing Delivery v1

`OwnerBriefingDelivery` completa el slice #108 sin crear un scheduler ni un transporte paralelo. Recibe un tick explícito del Scheduler, el snapshot que ya consume `OwnerBriefing::build()`, una policy de canal y, opcionalmente, evidencia de una entrega anterior.

## Contrato

La identidad de una entrega depende únicamente de `day + briefing_fingerprint`. El mismo briefing del mismo día conserva `delivery_ref` y `dedupe_key` aunque cambie el canal o se reintente la planificación. Si existe un receipt válido con estado `delivered` para esa identidad, el retry devuelve `decision=suppress` y razón `duplicate`.

El planner nunca declara que una entrega ocurrió. Cuando es elegible devuelve `delivery_status=pending` y una única `delivery_intent`. El transporte real deberá producir después la evidencia `delivered` que un retry puede consumir.

## Fail-closed

No se produce intención cuando:

- el tick del Scheduler está `stale` o `unknown`;
- la policy está deshabilitada;
- el canal configurado no está disponible;
- ya existe evidencia válida de entrega para el mismo día y fingerprint;
- el snapshot no pasa el contrato de `OwnerBriefing`;
- tick, policy o receipt tienen shape, refs o provenance inválidos.

El canal es abstracto (`email|push`). Este slice no configura SMTP, push providers ni credenciales.

## Fronteras

No hay DB, red, filesystem write, provider calls, cron, polling, scheduler mutation ni clock global. El día y freshness llegan inyectados por el caller.

La policy de Observability y Scheduler siguen siendo autoridades externas. Este componente únicamente materializa una intención idempotente y verificable para que una capa de ejecución autorizada la consuma posteriormente.
