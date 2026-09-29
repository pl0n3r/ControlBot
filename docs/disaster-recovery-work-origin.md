# Disaster Recovery Work Origin — ControlBot RECOVERY_WORKITEM

Este slice de #182 convierte **Recovery Health ya derivado por Factory #331** en un WorkItem v1 de **Factory #269 / Factory Queue v1**. ControlBot/AEGIS origina trabajo; Factory conserva readiness, deduplicación, prioridad operativa y despacho.

## Frontera

- Factory #331 es la autoridad de `HEALTHY|DEGRADED|UNKNOWN|BLOCKED` y de las clases canónicas de recovery.
- Solo una clase ya presente en `work_item_classes` puede originar remediación.
- `HEALTHY` no origina trabajo.
- El WorkItem fuerza `origin_mode=automatic`, `origin_system=aegis` y `producer_ref=controlbot:aegis/recovery`.
- Authority, priority, work type, capabilities, roles, policy, dependencies y claims son inputs explícitos del caller/policy. No se infieren del estado Recovery.
- `project_id` y `observed_at` provienen del Recovery Health; `health_ref` queda como evidence ref.
- Freshness permanece en el Recovery Health referenciado y no se copia a un campo que WorkItem v1 no admite.
- La idempotencia deriva de proyecto + clase + observed_at y no depende del orden de capabilities/roles/claims.

## Fail-closed

HEALTHY, clase ausente/no canónica, authority expandida, execute=true, health sensible o schema incoherente se rechazan. UNKNOWN/BLOCKED/DEGRADED pueden originar remediación solo cuando Factory #331 ya emitió una clase; eso no significa ready, approved ni authorized.

## Fuera de alcance

No recalcula Recovery Health, no llama Factory runtime/readiness/dispatcher, no crea Issues, no persiste cola, no agenda, no rankea, no notifica y no ejecuta remediación. El output es únicamente un WorkItem v1 para la cola única Factory.
