# GitHub Intent Policy V1

`GitHubIntentPolicy` evalúa offline envelopes normalizados por `GitHubIntentEnvelope`. Nunca ejecuta GitHub y toda salida conserva `execution=false`.

## Binding de autoridad

La capability no viene del caller. La policy mantiene un binding cerrado intent → capability canónica GitHub (`github.issue.write`, `github.pr.review`, `github.pr.merge`, `github.workflow.dispatch`, `github.release.approve`, `github.project.write`). Ese binding **no concede authority**.

`CapabilityPolicy` todavía no gobierna esas capabilities GitHub. Esta reparación tampoco las añade: grants `hostinger.*`, `database.*`, `config.*` u otros dominios no pueden convertirse en authority GitHub por sustitución.

## Freshness y provenance

`evidence_ref` debe existir exactamente en los `evidence_refs` validados del envelope. Sin embargo, un `observed_at` y TTL recibidos como valores crudos siguen siendo caller-controlled y no prueban provenance.

Por eso V1 falla cerrado:

- `evidence_ref` no vinculado → `deny / evidence_mismatch`;
- timestamp fuera del TTL → `deny / stale_evidence`;
- ref válido + timestamp/TTL crudos aparentemente frescos → `deny / untrusted_evidence_freshness`.

No existe hoy una proyección de freshness confiable dentro del alcance de esta hoja. Una hoja futura deberá introducirla explícitamente antes de que la policy pueda consultar grants/approvals y producir `allow` o `owner_decision_required`.

## Decisiones

El contrato de salida sigue cerrado a `allow | owner_decision_required | deny | unknown`, pero en esta versión reparada ningún contexto de freshness crudo puede llegar a `allow`. Tipos inválidos permanecen `unknown`; evidencia inválida o no confiable permanece `deny`.

## Fuera de alcance

Añadir capabilities GitHub a `CapabilityPolicy.php`, crear una proyección de freshness confiable, GitHub App/PAT/tokens, HTTP/API GitHub, adapters, shell/git libre, mutaciones reales, deploy, producción, DOMAIN/DEPLOY_ENABLED, gasto y datos reales.
