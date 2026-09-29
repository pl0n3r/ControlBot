# Product Learning Signals v1

## Propósito

Product Learning Signal es una frontera de salida para Product & Customer Intelligence. Convierte métricas agregadas y outcomes de experimento ya normalizados en un envelope trazable que otros dominios pueden consumir.

La señal transporta evidencia. No recomienda, no prioriza y no concede autoridad.

## Orígenes

El contrato admite dos orígenes:

- `metric`: normalizado por `ProductIntelligence`;
- `experiment_outcome`: normalizado por `ProductExperimentOutcome`.

Cada señal conserva Venture, Product, surface, category, status, source/evidence refs, freshness, confidence y nature. `aggregate_ref` identifica el agregado canónico del que nació.

## Tipos

- `observed_change`: métrica measured + observed;
- `inferred_change`: métrica measured + inferred;
- `revenue_outcome`: métrica agregada de categoría revenue_outcome;
- `evidence_gap`: métrica unknown o insufficient_data;
- `experiment_result`: outcome de experimento, incluido un outcome inconclusive.

El tipo describe evidencia. No expresa desirability, causalidad, urgencia ni decisión.

## Targets

Targets permitidos:

- `discovery`;
- `momentum`;
- `customer_success`;
- `capital`.

Los targets se normalizan en orden determinista y no pueden repetirse. Un target solo declara compatibilidad de consumo. No ordena acciones al dominio destinatario.

## Fail-closed

La señal conserva exactamente las limitaciones del origen:

- unknown sigue unknown;
- inconclusive sigue inconclusive;
- stale sigue stale;
- inferred sigue inferred;
- confidence no se eleva.

No existe campo libre de mensaje, acción, prioridad, miembros ni payload de evento. El schema cerrado impide usar este envelope como canal lateral de instrucciones o datos individuales.

## Privacidad y límites

El contrato no incorpora identidad de usuario ni eventos crudos. Tampoco añade persistencia, instrumentación, llamadas externas o ejecución. Las integraciones runtime futuras deben vivir en slices separados y respetar autoridad, privacidad y gates de sus dominios consumidores.
