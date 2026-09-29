# AgentReplayLifecycle v1

Capa pura sobre `AgentReplayCore v1` para proyectar un lifecycle verificable sin inferir ejecución desde texto, commits o planes.

## Entrada tipada

Cada entrada es `{stage, event}`. `event` conserva exactamente el contrato `ReplayEvent` y se valida mediante `AgentReplayCore::event()`. `stage` es explícito y allowlisted: `issue`, `reservation`, `plan`, `commit`, `review`, `check`, `merge`, `deploy`, `manual` o `documental`.

La fuente también debe coincidir con la etapa: Issue usa `github_issue`; commit usa `github_commit`; review/merge usan `github_pr`; check usa `github_actions`; deploy usa `production`; reservation/plan usan `controlbot|factory`. Evidencia manual/documental nunca acepta `github_actions`.

## Semántica

El lifecycle siempre expone el orden canónico `issue → reservation → plan → commit → review → check → merge → deploy`. Una etapa sin evidencia explícita queda `state=missing`; no se rellena por inferencia.

Un comentario o plan no prueba ejecución. Un commit no prueba deploy. Un workflow `skipped` permanece `skipped`, y `startup_failure` permanece distinto de `failure`. Acciones `manual|documental` quedan en `auxiliary_evidence` y no cuentan como CI.

Todas las entradas deben pertenecer al mismo `work_item_id`. La misma variante de evento no puede etiquetarse con dos etapas distintas.

## Fixtures operativos

El fixture #78 conserva una secuencia de check `success → startup_failure` sin inventar estado de aplicación ni fallo de steps. El fixture #86 conserva el check draft-aware como `skipped`, nunca como test exitoso.

## Determinismo y conflictos

`AgentReplayCore::build()` sigue siendo la autoridad para orden, dedupe, conflictos y fingerprint base. Los conflictos se propagan sin resolverlos. El lifecycle añade su propio fingerprint sobre la proyección canónica; permutar la entrada no altera la salida.

## Fuera de alcance

Adapters de GitHub/ControlBot/FactoryRunner, persistencia, UI/filtros, transcripts, ejecución de acciones, red, DB, filesystem write, shell e inferencia de hechos ausentes.
