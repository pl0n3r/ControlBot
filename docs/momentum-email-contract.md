# MOMENTUM Email Core v1

Slice de #126 para representar gobernanza de email sin enviar mensajes ni copiar contactos a ControlBot.

## Scope

`EmailProgram` pertenece a una Campaign canónica del mismo Venture y exige que la Campaign declare el canal `email`. Conserva únicamente `campaign_ref`, `audience_ref`, clase de mensaje y un ID opaco. Las clases son `marketing` y `transactional`; no se reinterpretan entre sí.

`EmailAudienceState` conserva tres señales independientes:
- consentimiento: `granted | denied | unknown`;
- suppression: `active | clear | unknown`;
- unsubscribe: `active | clear | unknown`.

Cada señal mantiene su propia referencia, `source_ref`, `observed_at` y `freshness=current|stale|unknown`.

## Fail closed

`marketing_eligible=true` solo existe cuando el programa es `marketing`, las tres señales son current, el consentimiento es granted y suppression/unsubscribe están clear. `denied`, `active`, `unknown` o cualquier señal stale nunca se promocionan a permitido. Un programa transactional permanece separado y este core no le concede permiso de delivery.

## Privacidad y límites

Las referencias usan `namespace:<32 lowercase hex>`; direcciones de email, nombres, teléfonos, contenido, URLs, secretos y credenciales quedan fuera del contrato. La fuente autoritativa de contactos/consentimiento permanece fuera de ControlBot.

Este contrato no decide base legal, no sustituye revisión jurídica y no implementa provider APIs, deliverability, envío, persistence, scheduling, Factory WorkItems ni FactoryRunner. `execution=false` permanece obligatorio.
