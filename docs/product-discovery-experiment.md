# Product Discovery Experiment Plan v1

Este contrato implementa el paso previo a experimentar de #183. Consume Initiative + Hypothesis de `ProductDiscovery` y exige declarar cómo se intentará validar la hipótesis antes de cualquier ejecución.

## Boundary

`ProductDiscoveryExperiment::plan()` acepta únicamente refs opacas y datos estructurales. El plan queda enlazado a la misma `initiative_id`, `hypothesis_ref` y `primary_metric_ref` del Discovery Core. La Initiative debe estar en estado `HYPOTHESIS`.

El plan declara un `kind` cerrado, una `evaluation_window` con `start_at < end_at`, `validation_cost_ref` opaca y estado descriptivo `EXPERIMENT_READY | RUNNING`. `RUNNING` no significa que este contrato ejecute nada: la salida mantiene `execution=false` y solo representa estado aportado por la capa operativa futura.

## Evidencia y scope

La salida conserva `group|venture`, `venture_id`, `market_ref`, `responsible_ref`, `expected_outcome_ref`, freshness y confidence de la Hypothesis. No promueve evidencia stale/unknown ni exige repo o Project.

## Límites

No asigna usuarios o tráfico, no activa feature flags, no crea tracking, no llama providers, no persiste, no calcula significancia ni produce `VALIDATED`, `INVALIDATED`, `BUILD`, `STOP` o WorkItems. `ProductExperimentOutcome` sigue siendo el contrato agregado posterior al experimento; el decision gate de Discovery será otro slice.

## Reversión

Cuatro archivos nuevos, sin migraciones ni estado persistente. Revertirlos elimina el contrato completo.
