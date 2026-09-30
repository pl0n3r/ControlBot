# SecurityAlertChannel v1

`SecurityAlertChannel` es un planner puro para convertir un `SecurityEvent v1` ya normalizado en una intención de alerta. No entrega mensajes ni consulta proveedores.

## Entrada

- `SecurityEvent v1`: salida autoritativa de `SecurityEvent::normalize()`.
- `ChannelState`: `primary_state` e `independent_state`, cada uno en `healthy|degraded|down|stale|unavailable|unknown`.
- `AlertPolicy`: `version` entero positivo y `escalate_noncritical` booleano.

## Decisión

1. Un evento no crítico produce `no_alert` salvo que `escalate_noncritical=true`.
2. Un evento elegible usa `primary_channel` solo cuando el primario está `healthy`.
3. Si el primario no está sano y el canal independiente está `healthy`, produce `independent_channel`.
4. Si no existe un canal de entrega confirmado sano, produce `owner_review`. En particular, `independent_state=unknown` nunca se interpreta como entrega posible.

La selección del canal no modifica `confidence`. Severidad y certeza siguen siendo dimensiones independientes.

## Idempotencia

`intent_id` deriva únicamente de `event_identity + channel + policy_version`. Reobservar el mismo evento no genera una intención nueva mientras esos tres componentes no cambien.

## Payload mínimo

Para una intención que requiere atención, el payload contiene solo:

- `event_identity`;
- `event_type`;
- `severity`;
- `confidence`;
- timestamp (`occurred_at` cuando existe, de lo contrario `observed_at`);
- `context.account_scope` como referencia opaca.

No se proyectan `event_id`, provider, fingerprint, actor, device, región, ciudad, coordenadas, payload bruto ni credenciales. `no_alert` no proyecta payload.

## Pureza y límites

El módulo no realiza red, email/SMS/push, DB, filesystem, cron, queue mutation, retry ni delivery receipts. Tampoco confirma que una alerta haya sido entregada. Esas responsabilidades pertenecen a slices posteriores de #17.
