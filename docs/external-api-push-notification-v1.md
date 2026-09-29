# External API Push Notification v1

## Objetivo
Este contrato representa una notificación móvil mínima. El push llama la atención, pero el detalle sensible siempre se recupera desde una operación GET pública ya declarada por `ExternalApiContract` y vuelve a pasar por autenticación y autorización del servidor.

## Payload
El payload acepta solo versión, referencia opaca de notificación, categoría, severidad, `entity_ref` interna, `detail_operation_id`, localization key cerrada, tiempos de emisión/expiración y una `dedupe_key` opaca. La salida añade `requires_authenticated_detail=true`.

No admite title/body libres, decisiones, opciones, montos, nombres, email, URL externa, device token, identificadores de proveedor ni credenciales. `critical` expresa severidad del aviso y nunca authority.

## Detalle autenticado
`detail_operation_id` debe resolver dinámicamente a una operación GET existente del catálogo público. Una operación POST, desconocida o removida falla cerrado. El payload no concede scopes, capabilities ni authority.

## Delivery policy
`deliveryPolicy()` es pura y recibe evidencia explícita:
- preferencia de categoría y severidad, cada una opcional;
- default del caller para ausencia de preferencia;
- última dedupe key y timestamp, o ambos nulos;
- ventana de dedupe;
- conteo, inicio, ventana y límite nominal de rate;
- tiempo actual.

Ausencia de preferencia usa el default explícito. Con default `false`, el comportamiento es fail-quiet. Expiry, duplicado dentro de la ventana o límite alcanzado producen `suppress` con reasons deterministas.

## Límites
No hay APNs/FCM, provider SDK, device registration, red, persistencia, cola, scheduler, background delivery, Factory WorkItem, DecisionRights execution ni código iOS. El caller conserva preferencias, históricos y contadores fuera de este contrato.
