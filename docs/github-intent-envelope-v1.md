# GitHub Intent Envelope V1

## Objetivo

`GitHubIntentEnvelope` define un contrato GitHub-specific, puro y versionado para describir una intención antes de policy, aprobación o ejecución. El envelope no usa red, token, GitHub API, shell ni git libre y siempre devuelve `execution=false`.

## Envelope

Campos exactos de v1:

- `version=1`
- `intent_id`: identificador opaco de 32 hex.
- `project_ref`: referencia interna `controlbot:project/...`.
- `repository_ref`: `owner/repo`.
- `type`: uno de los tipos cerrados.
- `params`: mapa exacto según el tipo.
- `idempotency_key`: clave estable y no sensible.
- `evidence_refs`: lista no vacía de referencias internas `controlbot:` o `github:`.

Campos adicionales fallan cerrado.

## Tipos y parámetros cerrados

| Tipo | Params exactos |
| --- | --- |
| `issue.create` | `payload_ref` |
| `issue.update` | `issue_number`, `payload_ref` |
| `issue.close` | `issue_number` |
| `issue.reserve` | `issue_number`, `reservation_ref` |
| `issue.release` | `issue_number`, `reservation_ref` |
| `pr.review` | `pr_number`, `review_ref` |
| `pr.merge` | `pr_number`, `expected_head_sha`, `merge_method` |
| `workflow.dispatch` | `workflow_ref`, `git_ref`, `inputs_ref` |
| `release.approve` | `release_ref`, `candidate_sha`, `approval_ref` |
| `project.freeze` | `reason_ref` |
| `project.unfreeze` | `reason_ref` |

Los contenidos editables se transportan como referencias internas (`*_ref`); este contrato no incorpora body/comandos libres. `merge_method` solo admite `merge`, `squash` o `rebase`. SHA exige 40 hex. `git_ref` acepta únicamente una referencia sintácticamente acotada, nunca una línea de comandos.

## Fail-closed y sensibilidad

Se rechazan:

- tipos desconocidos;
- params faltantes o adicionales;
- campos top-level adicionales;
- shell/git libre o comandos embebidos;
- referencias con componentes de contraseña, secreto, credencial, bearer, keys, token, OTP o cookie;
- evidencia vacía, duplicada o mal formada.

La validación solo normaliza el contrato. No clasifica authority, no decide policy, no crea approval y no materializa ninguna acción.

## Reutilización

La siguiente hoja (`GITHUB_INTENT_POLICY_V1`) consume este envelope y debe reutilizar los contratos existentes de capability/approval. Esta capa no crea un segundo modelo de autoridad y no convierte una referencia de evidencia en permiso de ejecución.

## Fuera de alcance

GitHub App/PAT, secrets, llamadas HTTP/GitHub API, merge/cierre/edición real, workflow dispatch real, releases reales, shell/git libre, deploy, producción y go-live.
