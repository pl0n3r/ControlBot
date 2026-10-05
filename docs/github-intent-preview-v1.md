# GitHub Intent Preview V1

`GitHubIntentPreview` es un view-model owner-first, local y read-only para el tramo de intenciones GitHub. Revalida el envelope y una salida de policy con contrato cerrado antes de exponerlos y siempre devuelve `execution=false`.

## Campos visibles

La vista expone únicamente:

- intención: `intent_id`, `project_ref`, `repository_ref`, `type` y `params`;
- policy: `decision` y `reasons`;
- requisito de aprobación derivado de `owner_decision_required`;
- `evidence_refs` e `idempotency_key`;
- `mutation_controls=[]`.

No se renderizan botones, comandos, adapters ni capacidades de ejecución, incluso si una policy upstream legítima entrega `allow` u `owner_decision_required`.

## Relación con GitHubIntentPolicy

La policy reparada por #687 falla cerrado cuando solo recibe freshness cruda: un intent válido con `evidence_ref` enlazado pero `observed_at`/TTL caller-controlled produce `deny / untrusted_evidence_freshness`. La preview proyecta esa decisión tal cual; no la eleva ni fabrica grants.

Los tests de integración usan esa policy real para el caso live fail-closed. `owner_decision_required` se cubre únicamente como fixture presentacional del contrato cerrado de salida para verificar el view-model; esa fixture no representa authority, grant ni aprobación real. Ninguna fixture usa `hostinger.read`, `database.restore` u otra capability ajena como authority GitHub.

## Fail-closed

Envelope/policy inválidos, campos sensibles, PII directa, decisiones fuera del conjunto cerrado o reasons no válidos producen una vista mínima `unknown` sin intención, evidencia ni idempotencia. Las decisiones `deny` y `unknown` conservan controles de mutación vacíos.

La sanitización sigue el patrón owner-first ya usado por ControlBot: password/secret/token/cookie/authorization/bearer/credential/OTP/DSN, claves y PII directa no pueden llegar a la vista.

## Fuera de alcance

GitHub App/PAT/tokens, HTTP/API GitHub, HTML accionable, botones o adapters de mutación real, merge/cierre/edición, workflow dispatch, releases, authority GitHub, grants/aprobaciones reales, deploy, producción, DOMAIN/DEPLOY_ENABLED, DNS, gasto, datos reales y go-live.
