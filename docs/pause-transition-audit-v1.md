# PauseTransitionAudit v1

Registro lógico append-only para cambios de `PauseState`. Reutiliza `PauseControl::state()`; no mantiene un segundo schema de pausa ni persiste datos.

## Transiciones

Permitidas:
- creación → `active|unknown`;
- `active → releasing|released`;
- `releasing → released`.

Entre `before` y `after` no pueden cambiar `pause_id`, scope, source, reason, created_at, preemptibility ni refs de policy/incident/evidence. Cada evento conserva actor, source, reason, `occurred_at`, before/after state y fingerprint SHA-256.

## Append

`append(history,event)` valida cada evento y solo agrega al final. Un replay exacto de un `event_id` ya presente devuelve la misma historia. Reutilizar ese ID con otro payload falla cerrado; también se rechaza que la historia existente ya contenga el mismo ID más de una vez. Timestamps regresivos, transiciones inválidas, discontinuidad `before_state → after_state` por `pause_id` o historia temporalmente reordenada también fallan cerrado.

El `event_id` es determinista por pause + estado destino + timestamp; el fingerprint cubre el payload completo, incluido actor.

## Seguridad y límite

Actor y contenido auditado rechazan patrones de secretos. Sin DB, filesystem write, red, shell, Runner, endpoints o UI. La persistencia durable futura debe conservar esta secuencia sin update/delete/reorder.

Fuera de alcance: SLA de global pause, Scheduler, resume de ExecutionOrder y cierre completo de #10.
