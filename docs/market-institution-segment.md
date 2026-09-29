# Market Institution Segment v1

Issue: #251 · Parent: #162

## Propósito

`MarketInstitutionSegment` añade una **dimensión Market** a señales agregadas de MOMENTUM y CAPITAL sin reinterpretar su valor, ejecutar sus motores ni ampliar autoridad. Es una proyección pura y consumer-neutral sobre el `Market` canónico de `MarketScope` (#232).

## Entrada

La proyección recibe un `Market` v1 válido y una lista de señales. Cada señal contiene exactamente:

- `institution`: `momentum|capital`;
- `venture_id`: debe coincidir con el Venture del Market;
- `signal_ref`: `controlbot:<institution>/<32hex>`;
- `evidence_refs[]`: refs opacas `controlbot:evidence/<32hex>`, únicas;
- `observed_at`: entero no negativo para `fresh|stale`, y `null` para `unknown`;
- `freshness`: `fresh|stale|unknown`.

No se aceptan payloads de campaña, métricas financieras, decisiones, presupuesto, gasto, forecast, revenue, policy, authority ni approval. Los campos extra fallan cerrado.

## Salida

La salida conserva `venture_id`, `market_id` y la geografía canónica del Market. Cada señal recibe esa misma dimensión y mantiene su `evidence_refs`, `observed_at` y `freshness`. Las evidencias se ordenan y las señales se ordenan por `institution + signal_ref`; duplicados fallan cerrado.

Un Market `global` conserva `{kind: global, code: null}`: **no expande países implícitos**. Dos Markets del mismo Venture continúan siendo entidades distintas por `market_id` y geografía.

## Límites de autoridad y datos

Este contrato no:

- recalcula métricas, atribución, revenue, forecast o growth;
- concede presupuesto, aprobación, policy o capacidad de ejecución;
- llama MOMENTUM, CAPITAL, Factory o FactoryRunner;
- crea WorkItems, scheduler o cola paralela;
- persiste datos ni consume providers;
- contiene secretos, PII o texto libre: solo IDs y refs opacas.

La fuente de autoridad continúa en los contratos de Decision Rights/CAPITAL/MOMENTUM/Factory correspondientes.

## Reversión

El cambio es aditivo y stateless. Revertir `MarketInstitutionSegment.php` y sus pruebas elimina la proyección sin migraciones ni estado externo.
