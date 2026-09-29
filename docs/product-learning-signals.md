# Product Learning Signals v1

Product Learning Signal es una salida consumer-neutral de Product & Customer Intelligence. Transporta evidencia agregada; no recomienda, prioriza ni concede autoridad.

## Orígenes y envelope

- `metric`: normalizado por `ProductIntelligence`.
- `experiment_outcome`: normalizado por `ProductExperimentOutcome`.
- Conserva Venture, Product, surface, category, status, provenance, freshness, confidence y nature.
- `aggregate_ref` identifica el agregado canónico de origen.

## Tipos y targets

Tipos: `observed_change`, `inferred_change`, `revenue_outcome`, `evidence_gap`, `experiment_result`.

Targets: `discovery`, `momentum`, `customer_success`, `capital`. Se ordenan de forma determinista, no se duplican y solo expresan compatibilidad de consumo.

## Fail-closed

Una métrica unknown/insufficient produce `evidence_gap`; inferred sigue inferred; stale sigue stale. Un outcome inconclusive sigue `experiment_result` + inconclusive. Confidence nunca se eleva.

El contrato cerrado no admite mensaje, acción, prioridad, miembros ni payload de evento, evitando usar la señal como canal lateral de instrucciones o datos individuales.

## Límites

No decide desirability, causalidad, BUILD/STOP, gasto, campaña, churn ni health. No integra runtime, persistencia, instrumentación, proveedores externos, UI ni Factory WorkItems.
