# Infrastructure Center · governance contract v1

#189 convierte una intención explícita en una decisión gobernada antes de cualquier ejecución. Esta capa **no ejecuta** provider APIs, SSH, SQL, IaC, deploys ni mutaciones productivas. `execution=false` permanece explícito.

## Flujo canónico

`InfrastructureIntent → authority/policy → CapitalPolicy cuando aplica → Factory WorkItem v1 → RunnerRequest → asignación → RunnerGateway::order()`

No se crea un RBAC, scheduler, ranking, budget engine, secret store ni cola paralela.

## Authority y capability policy

La authority es una proyección confiable producida por la capa de acceso existente, compatible con `VentureAccessRuntime`: `allow | deny | owner_decision_required | unknown`. Su `scope` debe coincidir exactamente con el scope de la intención. `unknown` y scope mismatch fallan cerrado.

`CapabilityPolicy` clasifica la **misma capability que llegará al Runner**. Para evitar subdeclaración de autoridad:

- `restart | capacity | rollback | reconcile` no aceptan una capability base `automatic` de lectura;
- `read | plan` no aceptan una capability base de escritura;
- restrictions pueden elevar una capability válida, nunca rebajarla;
- unknown/forbidden falla cerrado.

Si la policy final exige backup, `safe_point_ref` es obligatorio.

## Budget y costo

Cuando `cost_applicable=true`, son obligatorios `budget_ref`, `cost_ref` y un input de `CapitalPolicy`, que compone `BudgetGuard` y `DecisionRights`.

- `allow`: puede continuar si los demás gates permiten;
- `owner_decision_required`: se materializa el WorkItem, pero no se emite RunnerRequest;
- `deny`, UNKNOWN, evidencia ausente o scope financiero inconsistente: fail-closed.

Una recomendación técnica nunca autoriza dinero.

## MutationContract

`restart | capacity | rollback | reconcile` exigen `plan_ref`, `impact_ref`, `rollback_ref` o `safe_point_ref`, `verify_ref` y declaración explícita de `irreversible`.

`blast_radius=high` o `irreversible=true` exigen Owner Decision. `blast_radius=unknown` falla cerrado. `read | plan` no pueden transportar evidencia de mutación.

## Factory Queue #269

`work_item` usa el contrato WorkItem v1 documentado por Factory #269: `work_id`, origen, group, type, requested capabilities, required roles, authority, producer, priority, dependencies, claims, policy/evidence/idempotency y refs opcionales de venture/project/repository/budget/approval.

No usa `SchedulerCore::workItem()`, cuyo contrato local es distinto. El WorkItem #269 tampoco contiene state, provider, model, executor ni runner. Factory readiness/Dispatcher V2 siguen siendo la única cola y selección canónica.

## FactoryRunner boundary

Una intención `planned` produce un **pre-orden** `RunnerRequest` con WorkItem ID, capability, scope, instruction/evidence refs y verify-after-write. No contiene `runner_id`.

Solo después de una asignación real, `InfrastructureIntent::toRunnerOrder()` agrega order/attempt/generation/runner/TTL y delega la validación final a `RunnerGateway::order()`. La intención no selecciona runners ni salta readiness.

Owner Decision produce `factory-human-gate` con safe default de no ejecutar, mantiene `runner_request=null` y materializa siempre un `approval_ref` canónico `controlbot:approval/infra-<intent_id>` derivado siempre del intent, aunque el caller aporte otro ref. Ese mismo ref queda en el WorkItem y en el marker propio de ControlBot para que Factory readiness preserve el gate; el payload cerrado `factory-human-gate` no se amplía. Un estado `planned` sin gate humano no inventa `approval_ref`.

`required_roles` usa exclusivamente slugs del catálogo profesional Factory: `arquitectura`, `infraestructura`, `ingenieria-software`, `qa`, `seguridad`, `sre`. Un hard-deny domina cualquier escalamiento y no emite WorkItem, RunnerRequest ni marker que parezca autorizarlo.

## Seguridad y trust boundaries

Los envelopes son cerrados y rechazan campos extra. Refs/textos rechazan control characters y material con forma de secreto. Las credenciales nunca entran en WorkItem ni RunnerRequest.

Trust boundaries: observabilidad → authority/scope → CapabilityPolicy → CapitalPolicy → InfrastructureIntent → Factory Queue → RunnerGateway.

## Trade-off, reversión y SRE

ControlBot mantiene una proyección PHP del WorkItem v1 porque Factory #269 vive en otro repositorio; no duplica readiness ni ranking. La fuente normativa sigue siendo `pl0n3r/Factory#269`.

El módulo es puro: no toca DB, red, cron, hosting ni producción. Reversión: revertir este commit; no hay estado externo que restaurar.

No existe deploy productivo en este slice. Health/smoke exact-SHA y rollback de artefacto productivo son N/A; la evidencia SRE aplicable es fail-closed, safe point, rollback y verify-after-write.

## Fuera de alcance

Provider writes reales, adapters, selección/transporte de runner, ejecución de órdenes, pagos, secretos y UI del Infrastructure Center.
