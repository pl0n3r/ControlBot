# GitHub Intent Policy V1

`GitHubIntentPolicy` evalúa offline envelopes normalizados por `GitHubIntentEnvelope`. Nunca ejecuta GitHub y toda salida conserva `execution=false`.

## Binding de autoridad

La capability ya no viene del caller. La policy deriva un nombre canónico desde el tipo de intent:

- Issue write → `github.issue.write`
- PR review/merge → `github.pr.review|github.pr.merge`
- workflow dispatch → `github.workflow.dispatch`
- release approval → `github.release.approve`
- project freeze/unfreeze → `github.project.write`

Este binding **no concede authority**. `CapabilityPolicy::classify()` sigue siendo la fuente canónica y actualmente no contiene esas capabilities GitHub; por tanto la decisión es `unknown` hasta que una hoja separada y autorizada las gobierne. Grants `hostinger.*`, `database.*`, `config.*` u otras capabilities ajenas nunca pueden autorizar un intent GitHub.

`CapabilityGrant::authorize()` se reutiliza únicamente si la capability canónica llega a existir en `CapabilityPolicy`; el scope sigue derivándose del envelope, no del caller.

## Freshness vinculada a evidencia

El contexto debe seleccionar `evidence_ref` y esa referencia debe existir exactamente dentro de los `evidence_refs` ya validados por el envelope. Solo entonces se evalúan `evidence_observed_at` y el TTL (máximo 900 s). Ref ausente/mismatch o evidencia stale fallan cerrado antes de considerar authority.

## Decisiones

Solo existen `allow | owner_decision_required | deny | unknown`.

- envelope/tipo no reconocido → `unknown`;
- capability GitHub canónica aún no gobernada → `unknown`;
- evidencia no vinculada/stale, authority inválida o scope mismatch → `deny`;
- una futura capability gobernada podrá reutilizar policy/grant/approval existentes sin crear autoridad paralela.

## Fuera de alcance

Añadir capabilities GitHub a `CapabilityPolicy.php`, GitHub App/PAT/tokens, HTTP/API GitHub, adapters, shell/git libre, mutaciones reales, deploy, producción, DOMAIN/DEPLOY_ENABLED, gasto y datos reales.
