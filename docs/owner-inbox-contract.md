# Owner Inbox Contract

Primer slice propio de #127. `OwnerInbox` representa **dirección por excepción** sin crear otra cola, ejecutar decisiones ni inferir autoridad.

## Entry v1

Cada entrada contiene:

- `entry_ref` opaca;
- clase `fyi | watch | decision | critical`;
- scope explícito `group | venture | project | institution`;
- `title`, `summary` e `impact` breves;
- actor/ref opcional;
- provenance mediante `source_ref`, `evidence_refs`, `observed_at` y `freshness`;
- datos de decisión opcionales: `required_authority_level`, `decision_ref`, `options_ref`, `deadline_at`.

`decision` exige authority + decision_ref. `critical` exige authority explícita. `fyi/watch` no pueden transportar authority, decision_ref, options_ref ni deadline de decisión.

La clase no concede permisos. Decision Rights #122 sigue siendo la única autoridad para decidir quién puede actuar.

## Freshness

- `current` y `stale` requieren source + observed_at.
- `unknown` no puede inventar source/evidence/observed_at.
- stale/unknown se conservan como tales; nunca se promueven a current.

## Colección

`collection()` rechaza refs duplicadas y ordena de forma determinista:

1. critical;
2. decision;
3. watch;
4. fyi;
5. deadline ascendente cuando exista;
6. entry_ref.

Ese orden es presentación determinista, no un score ni ranking de negocio.

## Seguridad y privacidad

Texto y refs rechazan HTML/control characters y marcadores obvios de secretos/identificadores directos. La opacidad real de una ref sigue siendo responsabilidad del productor autoritativo; el contrato valida forma y límites, no inspecciona sistemas externos.

## Límite

No hay persistencia, notifications, scheduler, provider calls, DecisionRights evaluation, Factory WorkItems ni UI. El contrato solo transporta evidencia segura para que Cockpit/API puedan renderizar y un caller autorizado pueda resolver la acción posterior.
