# External API Owner E2E v1

## Objetivo

Este slice demuestra, sin red ni ejecución real, que los contratos móviles del Owner pueden componerse de extremo a extremo sin crear una segunda cola, otra autoridad ni un runtime paralelo.

Cadena demostrada:

`push approval_required → Owner Inbox → approve → WorkItem directed → factory_handoff → verification → push decision_result`.

## Contratos reutilizados

- `ExternalApiPushNotification`: aviso mínimo y detalle autenticado.
- `ExternalApiReadProjection`: Owner Inbox público con freshness explícita.
- `ExternalApiDirectedWorkOrigin`: única materialización del WorkItem dirigido.
- `ExternalApiMobileAudit`: evidencia de handoff y verificación.
- `ExternalApiMobileState`: stale/unknown fail-closed antes de una mutación.

## Garantías

- Los request/correlation IDs se mantienen coherentes en la cadena de prueba.
- `reject` nunca crea WorkItem.
- Un estado stale/unknown no habilita la mutación.
- Sesión revocada o ausencia de step-up falla cerrada.
- El resultado vuelve al cliente mediante un push mínimo que exige detalle autenticado.
- No hay DB, red, APNs/FCM, iOS, scheduler, FactoryRunner ni dispatch real.

## Límite de la evidencia

Este E2E valida composición contractual, no transporte. El envío real del WorkItem a Factory, la ejecución y la app iOS siguen fuera de alcance y requieren adapters/runtime propios gobernados.
