# Security Event Retention v1

`SecurityEventRetention` proyecta un plan determinista de retención y purge para eventos de seguridad canónicos. No ejecuta borrados, escrituras ni I/O externo.

## Política

- la policy es versionada, define un fallback por severidad y overrides explícitos por `event_type + severity`;
- cada plan expone `policy_fingerprint`, una huella del contenido completo de la policy, para detectar cambios de ventanas incluso si alguien reutiliza por error el mismo número de versión;
- el plan conserva identidad, fingerprint, tipo, severidad, confidence histórica, timestamps mínimos y solo referencias opacas estrictamente necesarias;
- contexto de dispositivo/ubicación, `provider` y actor se redactan o purgan; no forman parte del snapshot mínimo;
- artefactos de entrada usan schema cerrado: refs opacas `controlbot:` pueden quedar `keep_reference`; payload bruto se `redact` y secretos/IP/ubicación precisa/credenciales se `purge`, sin copiar su contenido al plan;
- `legal_hold=true` es explícito y nunca autoriza material sensible ni amplía el snapshot mínimo;
- acknowledge solo se acepta si su `event_id` coincide con la derivación canónica de `SecurityEventInbox::acknowledge()` para el `event_identity` fuente + actor/context; luego se conserva únicamente `state`, referencia del acknowledge y timestamp;
- el mismo evento + policy completa + inputs produce el mismo `purge_plan_id`.

## Frontera

No hay DB, filesystem writes, providers, jobs, cron ni deletes reales. Cambiar la versión o el contenido de la policy produce evidencia explícita en `policy_version`, `policy_fingerprint` y `purge_plan_id`; no reescribe historia silenciosamente.
