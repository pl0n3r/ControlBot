# GitHub Intent Policy V1

`GitHubIntentPolicy` evalúa offline envelopes normalizados por `GitHubIntentEnvelope`. Nunca ejecuta GitHub y toda salida conserva `execution=false`.

## Autoridad reutilizada

La policy no crea capabilities `github.*` ni un segundo modelo de autoridad. El caller aporta una capability ya definida por el servidor y:

- `CapabilityPolicy::classify()` decide si esa capability es conocida, automática, exige backup/owner o está prohibida.
- `CapabilityGrant` representa la autoridad existente. Su emisión ya aplica `CapabilityPolicy` y exige `owner_approval_id` cuando corresponde.
- `CapabilityGrant::authorize()` verifica vigencia, revocación y scope exacto.

El scope se deriva del envelope: `project_ref` → project, `repository_ref` → resource y `type` → operation. El caller no puede sustituir esos tres campos.

## Decisiones

Solo existen `allow | owner_decision_required | deny | unknown`.

- envelope/tipo no reconocido → `unknown`;
- capability desconocida → `unknown`;
- capability prohibida, autoridad inválida/ausente, scope mismatch o evidencia stale → `deny`;
- capability que exige owner sin grant aprobado → `owner_decision_required`;
- grant vigente y exacto → `allow`.

La evidencia debe venir ya validada por el envelope y su observación no puede superar el TTL indicado; V1 limita ese TTL a 900 s.

## Fuera de alcance

GitHub App/PAT/tokens, HTTP/API GitHub, adapters, shell/git libre, mutaciones reales, deploy, producción, DOMAIN/DEPLOY_ENABLED, gasto y datos reales.
