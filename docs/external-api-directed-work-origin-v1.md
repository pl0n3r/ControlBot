# External API Directed Work Origin v1

## Objetivo

`ExternalApiDirectedWorkOrigin` une una mutación Owner ya autenticada con un WorkItem dirigido compatible con Factory Queue v1. No despacha ni ejecuta trabajo.

## Fronteras

- El cliente móvil solo aporta el body público `version|outcome|idempotency_key`.
- Identity, authority, scope, capability y policy nacen de `VerifiedAccessContext`.
- Device/session/step-up nacen de `VerifiedExternalSessionContext`.
- La operación se reautoriza con `ExternalApiRequestGate` para `owner_decision.decide`.
- Un `reject` solo produce auditoría `mutation_rejected`.
- Un `approve` usa un intent server-side allowlisted para producir `origin_mode=directed` y `origin_system=human`.
- `authority_level` se deriva del nivel verificado y se normaliza a la forma canónica de Factory.
- `policy_ref` debe pertenecer a las policies verificadas.
- `approval_ref`, `work_item_ref` e idempotencia son deterministas.
- `factory_handoff` significa “payload listo para Factory”, no queue write, dispatch ni ejecución.

## Compatibilidad Factory #269

El WorkItem resultante contiene los campos requeridos por Factory Queue v1: work_id, origin, scope, tipo, capabilities, roles, autoridad, productor, prioridad, dependencias, claims, policy, evidence e idempotency; los campos venture/project/repository/severity/budget son opcionales.

## Fuera de alcance

No hay DB, red, GitHub, provider, FactoryRunner, scheduler, cola paralela, controller HTTP ni cliente iOS. La persistencia real de la Owner Decision y el envío real hacia Factory pertenecen a slices posteriores.
