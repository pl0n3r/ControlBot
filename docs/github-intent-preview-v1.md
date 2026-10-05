# GitHub Intent Preview V1

`GitHubIntentPreview` es un view-model owner-first, local y read-only para el tramo de intenciones GitHub. Revalida el envelope y la salida de policy antes de exponerlos y siempre devuelve `execution=false`.

## Campos visibles

La vista expone únicamente:

- intención: `intent_id`, `project_ref`, `repository_ref`, `type` y `params`;
- policy: `decision` y `reasons`;
- requisito de aprobación derivado de `owner_decision_required`;
- `evidence_refs` e `idempotency_key`;
- `mutation_controls=[]`.

No se renderizan botones, comandos, adapters ni capacidades de ejecución, incluso cuando la decisión es `allow`.

## Fail-closed

Envelope/policy inválidos, campos sensibles, PII directa, decisiones fuera del conjunto cerrado o reasons no válidos producen una vista mínima `unknown` sin intención, evidencia ni idempotencia. Las decisiones `deny` y `unknown` conservan controles de mutación vacíos.

La sanitización sigue el patrón owner-first ya usado por ControlBot: password/secret/token/cookie/authorization/bearer/credential/OTP/DSN, claves y PII directa no pueden llegar a la vista.

## Fuera de alcance

GitHub App/PAT/tokens, HTTP/API GitHub, HTML accionable, botones o adapters de mutación real, merge/cierre/edición, workflow dispatch, releases, deploy, producción, DOMAIN/DEPLOY_ENABLED, DNS, gasto, datos reales y go-live.
