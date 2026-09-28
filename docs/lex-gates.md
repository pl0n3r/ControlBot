# LEX Gates y handoff a Factory

Este slice convierte incertidumbre jurídica material y gaps ejecutables de LEX en contratos ya gobernados por la fábrica. No crea un scheduler, no ejecuta acciones y no concede autoridad.

## Human legal gate

Una incertidumbre material produce una puerta de categoría `legal` compatible con `factory-human-gate`.

- la pregunta es concreta y limitada al scope afectado;
- `safe_default = B` mantiene el scope bloqueado;
- `recommendation = B` evita tratar una interpretación incierta como aprobación;
- `authority_effect = none`;
- `auto_execute = false`;
- evidence/policy permanecen trazables.

La puerta usa los campos simples completos requeridos por Factory: `title_simple`, `summary_simple`, `why_recommended`, `blocks` y metadatos completos por opción.

## GAP ejecutable → Factory WorkItem v1

Un gap ejecutable se materializa como WorkItem compatible con Factory #269:

- `origin_mode = automatic`;
- `origin_system = controlbot`;
- `work_type` pertenece al catálogo cerrado `compliance_review|security|product|knowledge_documentation|marketing_growth`;
- conserva group/venture/project/repository cuando aplican;
- conserva `policy_ref`, `evidence_refs`, severity, observed_at y claims;
- requested capabilities/roles describen el trabajo, no un proveedor/modelo/ejecutor;
- `idempotency_key` se deriva de scope + gap + tipo + policy.

No existen campos `provider`, `model`, `executor`, scheduler privado ni ranking alternativo.

## Freshness y readiness

`fresh` puede producir `ready_hint=true` para un gap ejecutable, pero es solo una pista local: Factory vuelve a validar authority, policy, evidence, dependencias, claims y gates.

`stale` y `unknown` siempre producen `ready_hint=false`.

Una puerta humana siempre produce `ready_hint=false`, aunque su evidencia sea fresh. Pending nunca equivale a aprobación.

## Idempotencia

`evaluateBatch()` deduplica WorkItems equivalentes por `idempotency_key`. Dos outputs con la misma key y distinto contenido fallan cerrado.

## Boundary

LEX clasifica y materializa intención. Factory sigue siendo la cola única y el dispatcher canónico. ControlBot no gana autoridad legal por crear una puerta o un WorkItem.
