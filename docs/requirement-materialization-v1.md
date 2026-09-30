# Requirement Materialization v1

`RequirementMaterializer` cubre el paso posterior a la revisión de `EpicProposal v1`: convierte una aprobación explícita del dueño en Project/Epic/Issues sin ampliar autoridad.

## Boundary

- Entrada canónica: `EpicProposal v1` + `RequirementDecisionView v1` + aprobación vinculada por refs y fingerprints.
- La vista se recalcula desde el proposal y debe coincidir exactamente antes de tocar el gateway.
- Se reutiliza `OwnerContext::assertFresh()`; una sesión stale/no-owner falla cerrado.
- Solo `option=approve` puede ejecutar. `revise` y `reject` nunca mutan.
- El dominio no hace red, DB, filesystem, repos, branches, deploys, secretos ni provisioning.

## Project

- `link_existing`: enlaza exclusivamente el `project_ref` aprobado; no crea copia.
- `review_matches`: no es materializable y requiere nueva decisión.
- `owner_choice_required`: exige `project_resolution` explícita con `project_id`, `slug`, `title`, `phase` y `priority`; se valida con `ProjectModel v1`.
- Los recursos del Project nuevo nacen vacíos. Repositorios y entornos quedan fuera de este slice.

## Idempotencia

Cada artefacto recibe una clave SHA-256 derivada de la identidad exacta de proposal + decisión + tipo de artefacto/slice. El gateway implementa operaciones `ensure*`: repetir la misma aprobación devuelve las mismas refs y no crea duplicados.

El receipt conserva únicamente refs, fingerprints, claves de idempotencia y slices materializados. No incluye intent/transcript/audio, credenciales ni payloads sensibles.

## Seguridad y reversión

Un diff alterado, una aprobación vinculada a otra proposal/decisión, una elección incompleta o un receipt de gateway con identidad inesperada falla antes de continuar. El gateway real queda para un slice/adaptador posterior; este contrato es testeable con fake determinista y no concede autoridad para Factory provisioning ni producción.
