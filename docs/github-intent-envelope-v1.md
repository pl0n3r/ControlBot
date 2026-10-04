# GitHub Intent Envelope V1

`GitHubIntentEnvelope` normaliza una intención GitHub antes de policy, aprobación o ejecución. Es puro, versionado, no usa red/token/API/shell/git libre y siempre entrega `execution=false`.

## Contrato
Campos exactos: `version=1`, `intent_id`, `project_ref`, `repository_ref`, `type`, `params`, `idempotency_key` y `evidence_refs`. Campos extra fallan cerrado; la evidencia debe ser interna, no vacía, única y no sensible.

| Tipo | Params exactos |
| --- | --- |
| `issue.create` | `payload_ref` |
| `issue.update` | `issue_number`, `payload_ref` |
| `issue.close` | `issue_number` |
| `issue.reserve/release` | `issue_number`, `reservation_ref` |
| `pr.review` | `pr_number`, `review_ref` |
| `pr.merge` | `pr_number`, `expected_head_sha`, `merge_method` |
| `workflow.dispatch` | `workflow_ref`, `git_ref`, `inputs_ref` |
| `release.approve` | `release_ref`, `candidate_sha`, `approval_ref` |
| `project.freeze/unfreeze` | `reason_ref` |

Los contenidos editables viajan como referencias internas. Se rechazan tipos/params desconocidos, comandos libres, refs sensibles, SHA/ref inválidos y componentes como password, secret, credential, bearer, key, token, OTP o cookie.

La siguiente hoja `GITHUB_INTENT_POLICY_V1` reutiliza capability/approval existentes; este envelope no crea autoridad. Fuera de alcance: GitHub App/PAT, secretos, HTTP/API, mutaciones reales, deploy, producción y go-live.
