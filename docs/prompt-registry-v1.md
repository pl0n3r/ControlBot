# Prompt Registry v1

`PromptRegistry` es el núcleo puro del primer slice de #14: conserva un historial versionado e inmutable y solo selecciona versiones `approved`. No persiste datos, ejecuta prompts ni invoca Scheduler, Factory, modelos o proveedores.

## Registro
Cada `PromptTemplateVersion` contiene exactamente `template_id, version, task_class, provider_scope, body_ref, variables_schema, status, created_at, created_by, supersedes`.

Estados: `draft|candidate|approved|deprecated|revoked`. La versión 1 usa `supersedes=null`; las siguientes apuntan a la última versión del mismo template y avanzan `created_at`. `task_class`, `provider_scope` y `variables_schema` no cambian silenciosamente: un contrato incompatible requiere otro template.

`register(history, candidate)` valida el historial completo, rechaza duplicados y devuelve una copia ordenada por `template_id + version`; nunca reescribe filas previas.

## Selección y rollback
`active(history, template_id)` considera únicamente `approved` y devuelve la aprobada más reciente. Sin una aprobada falla cerrado; no existe fallback a draft, candidate, deprecated o revoked.

`rollback(history, template_id, target_version)` solo selecciona una versión `approved` anterior a la activa. No muta historial ni estados.

## Seguridad y límites
Los schemas son cerrados. IDs, refs, timestamps, tipos y `required` se validan; metadata con señales de password, token, cookie, credencial, claves privadas/API, OTP o recovery code se rechaza. El registro conserva `body_ref`, no el cuerpo, valores de variables ni transcripts.

El módulo es determinista y no usa reloj, DB, red, filesystem write, shell, cache persistente, FactoryRunner ni APIs de modelos. Evaluación comparativa, promotion/canary, UI, persistencia e integración real quedan fuera de este slice.
