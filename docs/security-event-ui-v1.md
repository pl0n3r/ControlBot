# Security Event Inbox v1

`SecurityEventInbox` proyecta eventos de seguridad canónicos para el inbox privado del dueño sin ejecutar acciones externas.

## Semántica

- severidad y confidence se muestran como dimensiones independientes;
- el contexto visible limita ubicación a país y omite actor, ciudad, región, payloads y material sensible;
- estados: `unread | acknowledged | superseded`;
- orden: `critical → warning → info`, luego timestamp ascendente e identidad estable;
- acknowledge genera un `SecurityEvent v1` de tipo `security_alert_acknowledged` con identidad determinista;
- acknowledge no resuelve, elimina ni reclasifica el evento original.

## Accesibilidad y frontera

La acción acknowledge expone label, foco por teclado y flags explícitos `requires_hover=false` / `requires_drag=false`, válidos para móvil y escritorio.

El projector es puro: sin DB, red, providers, filesystem writes, persistencia ni side effects. Retención/purge pertenece a #492.
