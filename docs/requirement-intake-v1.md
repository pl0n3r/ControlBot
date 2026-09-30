# Requirement Intake v1

`RequirementIntake` convierte una entrada textual ya disponible en un contrato puro `RequirementDraft v1` y, posteriormente, en una propuesta `EpicProposal v1`. No crea Projects, Epics, Issues, repositorios ni despliegues.

## RequirementDraft v1

La entrada acepta únicamente:

- `source_kind`: `text` o `transcript`;
- `source_ref`: referencia opaca `controlbot:...`;
- `captured_at`: timestamp explícito;
- `content`: texto disponible para normalización.

El core reconoce hechos únicamente cuando están expresados con etiquetas explícitas como `Problem:`, `User:`, `Client:`, `Budget:`, `Deadline:`, `Objective:`, `Constraint:`, `Dependency:` u `Out of scope:`. Un campo no expresado permanece `unknown`; no se infieren cliente, presupuesto, fechas ni restricciones.

Texto y transcript producen la misma estructura. El audio bruto no forma parte del contrato. `source_kind` conserva provenance, mientras el fingerprint se deriva del contenido saneado y la evidencia estable.

Antes de producir el draft, patrones de credenciales y secretos se sustituyen por `[REDACTED]`. El contrato nunca restaura material redactado.

## EpicProposal v1

`analyze()` conserva los hechos del draft y proyecta:

- problema, usuario y objetivos;
- fuera de alcance y dependencias;
- riesgos derivados únicamente de estados observables del draft, como campos desconocidos o redacción aplicada;
- preguntas explícitas para los campos `unknown`;
- slices propuestos con criterios de aceptación;
- match opcional con un Project existente.

El match de Project es solo una propuesta de vínculo. `project_match.auto_create=false` siempre; un empate queda `ambiguous` y exige revisión. El resultado además declara `requires_approval=true` y `execution=false`.

## Frontera

Este módulo es determinista y sin side effects. No usa DB, filesystem writes, red, providers, GitHub mutations, audio, UI ni materialización de trabajo. La persistencia, aprobación y creación real pertenecen a slices posteriores de #8.
