# AgentReplayCore v1

Núcleo puro para reconstruir un replay verificable de un WorkItem sin ejecutar acciones ni persistir conversaciones. Recibe eventos ya observados por adapters externos; no consulta GitHub, ControlBot, FactoryRunner ni producción.

## Contrato

Cada `ReplayEvent` usa un schema cerrado: `event_id`, `occurred_at`, `observed_at`, `sequence`, `source`, `kind`, `actor_type`, `actor_ref`, `work_item_id`, `project_id`, `repo`, `issue_number`, `pr_number`, `commit_sha`, `execution_order_id`, `execution_event_id`, `summary`, `evidence_ref` y `payload_digest`.

`build()` valida y normaliza cada evento, elimina reingestas exactamente idénticas y ordena por `occurred_at → sequence → source → event_id → payload_digest`. Permutar la entrada no modifica la salida ni el fingerprint.

## Estados y evidencia

`success`, `failure`, `skipped`, `startup_failure`, `cancelled`, `blocked`, `waiting_human` y `unknown` se conservan literalmente. El núcleo no promociona `skipped` a éxito, no convierte falta de runner en fallo de steps y no infiere deploy desde un commit.

Un handoff conserva `work_item_id`, mientras cada evento mantiene su propio `actor_type` y `actor_ref`. El replay no transfiere retrospectivamente acciones de una Session/agente a otra.

## Conflictos

`event_id` representa identidad del hecho observado. Una reingesta idéntica se deduplica. Si el mismo `event_id` aparece con variantes distintas, se preservan todas las variantes y se añade una entrada en `conflicts` con `state=unknown`, `reason=conflicting_evidence`, fingerprints y referencias de evidencia. El núcleo nunca elige silenciosamente una variante.

## Privacidad y seguridad

`summary` y `evidence_ref` rechazan secretos obvios, email/teléfono evidentes, transcripts completos y referencias a chain-of-thought/internal reasoning. `evidence_ref` admite enlaces GitHub sin query sensible o referencias internas allowlisted. El módulo no almacena chats ni intenta reconstruir razonamiento privado.

## Fuera de alcance

Persistencia, ingestión real, UI/filtros, transcript opt-in, creación de incidentes, reejecución de acciones, DB, red, filesystem write, shell y ejecución de providers. Esos adapters deben conservar `payload_digest`, actor attribution y evidencia sin ampliar autoridad.
