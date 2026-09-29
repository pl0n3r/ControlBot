# Product Experiment Outcomes v1

## Propósito

Este contrato conecta Product Intelligence con resultados de experimentos sin apropiarse del lifecycle de Discovery. Consume un `experiment_ref` opaco y dos métricas agregadas ya gobernadas por `ProductIntelligence`: baseline y variant.

No diseña ni ejecuta el experimento, no asigna usuarios y no decide `VALIDATED`, `INVALIDATED`, `BUILD` o `STOP`.

## Comparabilidad

Baseline y variant deben compartir:

- Venture y Product;
- surface;
- category;
- unit.

Ambas ventanas de métrica deben caer dentro de `evaluation_window`. El outcome conserva los IDs de las dos métricas, provenance agregada y la ventana evaluada.

## Resultado numérico

Cuando ambas métricas están en `measured`:

- `delta = variant - baseline`;
- `comparison = higher | lower | equal`.

Estos valores son descriptivos. `higher` no significa mejor y `lower` no significa peor. El contrato no establece causalidad ni significancia estadística.

Si cualquiera de las dos métricas está en `unknown` o `insufficient_data`, el outcome queda `inconclusive`, con `delta=null` y `comparison=unknown`.

## Fail-closed

- Si cualquiera de las métricas es inferred, el outcome es inferred.
- Si cualquier evidencia es unknown, la freshness efectiva es unknown.
- En ausencia de unknown, cualquier stale degrada el outcome a stale.
- Confidence efectiva es el mínimo de baseline y variant.
- `experiment_ref` usa un identificador opaco `experiment:<32hex>`.

## Privacidad y límites

El outcome contiene agregados y referencias, no miembros individuales ni payloads de eventos. No añade tracking, cookies, providers, persistencia, Factory Queue ni ejecución. Cualquier instrumentación futura debe cumplir el contrato de privacidad de Factory y documentar los tratamientos que corresponda.
