# Infrastructure Center · observability contract v1

#188 deriva estado observable e impacto **sin collectors, polling, red ni acciones**. La fuente de identidad continúa siendo el inventario de #187; este módulo solo normaliza evidencia y relaciones explícitas.

## Observaciones

Cada `InfrastructureObservation` conserva `resource_id`, `state`, `source_ref`, `observed_at` y `freshness`.

Estados: `online | degraded | offline | maintenance | unknown`.
Freshness: `fresh | stale | unknown`.

La proyección efectiva falla cerrado:

- observación ausente → `unknown`;
- freshness `stale` → `unknown`;
- freshness `unknown` → `unknown`;
- solo evidencia `fresh` conserva el state observado.

No se infiere healthy por ausencia de incidentes, por nombre del proveedor ni por una release existente.

## Señales separadas

Backup freshness, restore verification, cost attribution y release drift son contratos separados. Un backup fresh **no prueba** restore verificado. Cost attribution requiere `cost_ref + source_ref + observed_at`; release current/drift requiere SHA esperado, SHA observado, source y timestamp coherentes.

Sin esa evidencia, el estado permanece `unknown`; no se completa por inferencia.

Incident refs solo enlazan evidencia. No crean incidentes ni deciden severidad.

## Impact graph

`InfrastructureImpact` recompone el grafo desde `InfrastructureResource::normalizeInventory()` y bindings explícitos de capability:

`resource → service → environment → project → venture → capability`.

No acepta un graph autocertificado por caller. Los enlaces `service_ref`, `environment_ref` y `parent_ref` deben conservar el mismo `project_ref` y `venture_ref`; `service_ref` además debe conservar `environment_ref`. Un capability solo se asocia cuando `project_ref` y `venture_ref` coinciden exactamente con el recurso. La vista inversa `venture → resources` usa únicamente `venture_ref` explícito; nunca cruza ventures por similitud de nombre, provider o account.

El grafo describe **impacto potencial conocido por relaciones declaradas**. No fabrica customer impact ni blast radius cuando faltan relaciones.

## Reutilización y límites

- `InfrastructureResource`/#187: identidad de recursos.
- `ProjectModel`: refs de project/environment.
- `VentureIdentity`: refs de venture.
- `FinanceCostCenter`: precedente de freshness y provenance.
- `HostingerConnectionHealth`: adapter específico existente; no se convierte en dominio genérico.

Fuera de alcance: collectors reales, cron, scheduler, remediation, provider writes, secrets, scoring global, alertas, budgets o authority. Esas capacidades se integran en nodos posteriores del DAG #125.
