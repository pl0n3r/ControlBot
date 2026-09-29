# PauseProductionGate v1

Boundary puro entre `PauseControl` y operaciones de producción tipadas.

- La clasificación read/write proviene exclusivamente de `ProductionOperation::effect()`.
- Writes consultan `PauseControl::effective(..., mutation=true)`: pause `active|releasing|unknown` bloquea.
- Reads tipadas permanecen disponibles durante una pause activa; la pause efectiva se conserva como evidencia.
- Scope y precedencia siguen siendo los de `PauseControl`: global > project > account > session.
- Un release solo elimina el bloqueo de pausa. La salida siempre conserva `requires_existing_authority=true` y `authorization=not_granted`.
- Operation ID desconocido, PauseState inválido o scope incompatible fallan cerrado.

La salida contiene operation/capability/effect, `pause_allows`, evidencia de pause efectiva, reason y fingerprint determinista. No ejecuta operaciones ni crea grants, approvals o capabilities.

Fuera de alcance: Scheduler/global-pause SLA, auditoría append-only, resume idempotente, DB/red/shell/Runner, endpoints y UI.
