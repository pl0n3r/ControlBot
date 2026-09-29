# Infrastructure Center · UI projection v1

#190 agrega una proyección pura: `Resource → Observation → Impact → InfrastructureCenterUi::project()`. No crea inventario, collector, scheduler, authority ni ejecución paralela.

## Contrato

- Estado conserva `observed_state`, `effective_state`, `freshness`, `source_ref` y `observed_at`; stale/unknown o ausencia nunca se vuelven GREEN.
- Observaciones duplicadas, huérfanas y relaciones cross-scope fallan cerrado.
- Drill-down usa scopes normalizados y el graph canónico: venture → project → environment/resource y resource → impact.
- Backup, restore, costo, release drift e incidentes permanecen señales independientes.
- #190 no acepta acciones del caller y siempre proyecta `actions=[]` / `execution=false`; authority se integra en #191 desde `InfrastructureIntent::plan()`.
- IDs/refs pasan por `InfrastructureProvider`; secretos no llegan al view-model.

## Límite

Trust boundary: `provider evidence → normalized domain → governed projection → UI consumer`. La UI no toca providers, DB, red, FactoryRunner, credenciales ni producción.

#191 reutiliza esta proyección con `InfrastructureIntent` y FactoryRunner. Reversión: revertir las cuatro rutas de #190; no existe estado externo que restaurar.
