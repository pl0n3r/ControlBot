# AEGIS Integration v1

`AegisRuntime` conecta AEGIS Remediation con Factory Queue v1 y Feedback Mesh sin ejecutar acciones ni crear scheduler/ranking.

Flujo: `finding → policy/authority → remediation plan → Factory WorkItem → verification/evidence → Living lesson candidate`.

## Factory handoff

Cada finding produce un WorkItem compatible con Factory #269: `origin_mode=automatic`, `origin_system=aegis`, work type `security|compliance_review|infrastructure`, scope, severity, policy, evidence, observed_at e idempotency. Authority se normaliza al slug lowercase requerido por WorkItem v1. `source_freshness` queda en el envelope porque WorkItem v1 no admite un campo freshness adicional.

No se emiten provider, model, executor, scheduler ni ranking.

## Fail-closed y owner gate

`ready_hint=true` solo con evidencia `fresh` y plan #158 `auto_eligible`. Stale/unknown, policy/authority insuficiente u owner gate quedan no-ready. El hint no reemplaza readiness/Dispatcher V2.

Si #158 exige owner, el WorkItem conserva `approval_ref` y el gate declara `auto_execute=false`.

## Living feedback

El candidato contiene source/scope/evidence/freshness e identidades deterministas, con `authority_effect=none` y `policy_effect=none`. No contiene texto libre, PII, secretos ni provider data. Factory/Lab decide cualquier adopción posterior.

Findings equivalentes se deduplican por idempotency key. Verification/evidence se conserva en el WorkItem y la salida. Sin red, DB, producción, UI, runner ni mutaciones de Factory authority/policy.
