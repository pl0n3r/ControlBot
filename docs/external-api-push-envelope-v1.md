# External API Push Envelope v1

## Objetivo
Este contrato define el payload mínimo que una futura integración móvil puede entregar mediante push. El push **no es fuente de verdad** y no contiene el detalle de una decisión, incidente o excepción. Solo avisa que existe una entidad que debe resolverse después mediante la API autenticada.

## Tipos
Los tipos v1 son cerrados:

- `critical_incident`
- `security_risk`
- `spend_approval`
- `venture_degradation`
- `strategic_decision`
- `decision_result`

No existe un tipo genérico de marketing o ruido operacional.

## Payload mínimo

El envelope conserva referencias opacas, type, venture opcional, provenance/freshness, una copy key genérica, correlation ID y una collapse key determinista.

No admite title/body libres ni copia evidencia, importes, nombres, conversaciones, secretos o credenciales. Tampoco transporta identificadores de dispositivo del proveedor de push.

## Deep link autenticado

`deepLink()` solo produce:

- una route cerrada;
- el target_ref;
- `requires_authenticated_api_fetch=true`.

El cliente debe abrir la superficie correspondiente y recuperar el detalle mediante el flujo normal autenticado/autorizado. Abrir una notificación no concede capability ni authority.

## Freshness

- `current` y `stale` exigen `occurred_at` y `source_ref`.
- `unknown` exige que ambos sean nulos.
- stale permanece visible como stale; el push no lo convierte en estado actual.

## Dedupe y preferencias

La collapse key es determinista para `type + target_ref`, pero no promete entrega exactly-once.

`deliveryPolicy()` recibe explícitamente si las preferencias habilitan el aviso, si policy permite el tipo y si la severidad declarada por el caller/policy permite notificar. El contrato no calcula severidad ni infiere escalación desde el evento y no envía nada.

## Fuera de alcance

APNs/FCM, registro de device tokens, SDK de proveedor, persistencia, retry, rate limiting persistente, scheduler, código iOS, UI y ejecución de decisiones o Factory WorkItems.
