# PauseProductionGate v1

`PauseProductionGate` es el boundary puro entre `PauseControl` y las operaciones tipadas de Production Authority. No concede autoridad y no ejecuta la operación.

## Clasificación canónica

El gate recibe contexto Runtime, PauseStates y un `operation_id`. La clasificación read/write proviene exclusivamente de `ProductionOperation::fromId(...)->effect()`; no existe un segundo catálogo dentro del gate.

Para `write`, el gate llama `PauseControl::effective(..., mutation=true)`. Una pause efectiva `active|releasing|unknown` produce `pause_blocked=true` y `pause_allows=false`.

Para `read`, el gate llama el mismo contrato con `mutation=false`. Una lectura tipada permanece disponible durante una pause activa, pero `effective_scope`, `effective_pause_id` y `effective_state` se conservan como evidencia.

## Autoridad y release

Toda salida declara `requires_existing_authority=true` y `authorization=not_granted`. Por tanto, `pause_allows=true` significa únicamente “PauseControl no bloquea esta operación”. No significa capability, approval, grant, budget, backup receipt ni permiso de Production Authority.

Cuando una pause está `released`, deja de ser efectiva y `pause_blocked` pasa a false. El release no amplía autoridad.

## Precedencia y fallos cerrados

La precedencia es heredada de `PauseControl`: global > project > account > session. Operation IDs desconocidos, PauseStates inválidos y scopes incompatibles se rechazan mediante los contratos existentes. Un estado `unknown` bloquea writes.

La decisión incluye operation/capability/effect, `pause_blocked`, `pause_allows`, evidencia efectiva, reason y fingerprint SHA-256 determinista.

## Límites

No hay Scheduler/global-pause SLA, auditoría append-only, resume/ExecutionOrder, provider/Runner execution, DB, red, filesystem write, endpoints ni UI. Reversión: retirar las cuatro rutas del slice; no hay migraciones ni estado externo.
