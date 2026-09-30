# MOMENTUM Performance Contract v1

## Propósito

`MomentumPerformance` es una proyección analítica pura que conecta evidencia de campaña con el funnel de revenue sin crear causalidad, autoridad ni ejecución.

Su frontera es:

```text
Paid Media / evidencia de spend
        +
MomentumRevenue Pipeline + Attribution
        +
observaciones agregadas de funnel y costo
        ↓
MomentumPerformance::project()
        ↓
spend → leads → conversions → revenue / margin
        +
unit economics solo cuando hay inputs suficientes
```

No es un warehouse, tracker, CRM, policy engine, scheduler ni execution plane.

## API

```php
MomentumPerformance::project(
    array $raw,
    array $pipelineRaw,
    array $attributionRaw,
): array
```

El método revalida `pipelineRaw` y `attributionRaw` mediante `MomentumRevenue`. No redefine sus estados ni convierte forecast o inferencia en revenue observado.

## Scope

Toda proyección queda ligada a:

- `performance_id` opaco;
- `venture_id`;
- `campaign_ref`;
- `paid_media_ref` opaco;
- período cerrado `start_at..end_at`;
- moneda ISO de tres letras.

`venture_id` y `campaign_ref` deben coincidir con Pipeline y Attribution. La moneda debe coincidir con Attribution. Toda evidencia temporal debe caer dentro del período.

Un mismatch falla cerrado.

## Observaciones

### Spend

`spend` contiene:

- `classification: observed | unknown`;
- `amount_minor`;
- `currency`;
- `source_ref`;
- `evidence_refs[]`;
- `observed_at`;
- `freshness`.

`paid_media_ref` enlaza la observación con la frontera de paid media, pero Performance no vuelve a decidir authority o budget.

Un plan/propuesta de paid media no se transforma automáticamente en gasto observado. Para que `derived.spend_minor` exista, la observación de spend debe ser `observed`, tener evidencia y permanecer `current`.

### Funnel

`funnel` contiene:

- `classification: observed | inferred | unknown`;
- `leads`;
- `conversions`;
- `source_ref`;
- `evidence_refs[]`;
- `observed_at`;
- `freshness`.

`conversions` nunca puede superar `leads`. Un funnel `unknown` no puede declarar conteos.

### Revenue

Revenue proviene exclusivamente de `MomentumRevenue::attribution()`.

- `observed`: puede alimentar `derived.observed_revenue_minor` si está current.
- `inferred`: se conserva separadamente en `derived.inferred_revenue_minor` y nunca alimenta margen o ROAS observado.
- `unknown`: no aporta monto.

No se usa forecast como revenue demostrado.

### Cost

`cost` contiene una observación agregada adicional al spend de medios:

- `classification: observed | unknown`;
- `amount_minor`;
- `currency`;
- `source_ref`;
- `evidence_refs[]`;
- `observed_at`;
- `freshness`.

El costo representa costos atribuibles adicionales al spend de medios. Performance no pretende sustituir contabilidad ni CAPITAL.

## Freshness y evidencia

Estados de freshness:

- `current`;
- `stale`;
- `unknown`.

Los valores conocidos conservan source/evidence aunque queden stale. Sin embargo, una señal stale/unknown no se usa para derivar métricas current.

Las referencias de evidencia se validan como IDs opacos y se ordenan de forma determinista. Duplicados fallan cerrado.

## Derivaciones

La proyección puede exponer:

- spend observado;
- leads/conversions conocidos;
- revenue observado;
- revenue inferido, separado;
- costo observado;
- margin observado.

`margin_minor` solo existe cuando spend, costo y revenue observado están disponibles y current:

```text
margin_minor = observed_revenue_minor - spend_minor - cost_minor
```

Si falta cualquiera de esos inputs, el margen es `null`.

## Unit economics

### CAC

Cuando hay spend observado current y conversions conocidas mayores que cero:

```text
cac_minor = floor(spend_minor / conversions)
```

Si las conversions son inferidas, la clasificación de CAC queda `inferred`. Si faltan inputs, queda `unknown`.

### ROAS

Solo usa revenue observado current:

```text
roas_milli = floor((observed_revenue_minor / spend_minor) * 1000)
```

`1000` representa `1.000x`. Revenue inferido o unknown nunca produce ROAS observado.

### Payback y LTV

v1 no recibe cohortes, duración de retención, cash-flow temporal ni lifetime revenue suficientes para derivar payback o LTV de forma defendible.

Por eso ambos permanecen explícitamente `unknown` en vez de fabricarse.

## Fail-closed

Se rechazan, entre otros:

- cross-Venture;
- campaign mismatch;
- currency mismatch;
- evidencia fuera del período;
- campos extra;
- IDs no opacos;
- evidencia duplicada;
- valores observed/inferred sin evidencia;
- cantidades declaradas bajo clasificación `unknown`;
- conversions mayores que leads.

Freshness stale/unknown no se convierte en current: la señal se preserva, pero sus derivados quedan `null`.

## Privacidad y seguridad

El contrato acepta únicamente valores agregados y referencias opacas. No incorpora nombres, emails, teléfonos, cookies, identificadores de plataforma ni payloads de usuario final.

No almacena credenciales ni ejecuta integraciones externas.

## Límites institucionales

- **MOMENTUM Campaign / Paid Media**: contexto de campaña y gobernanza del gasto.
- **MomentumRevenue**: pipeline y attribution canónicos.
- **MomentumPerformance**: proyección analítica agregada.
- **CAPITAL**: autoridad financiera, presupuesto y verdad financiera cuando aplique.
- **Factory / FactoryRunner**: trabajo y ejecución, fuera de este contrato.

Performance no crea una cola, no decide authority, no aprueba budget y no ejecuta spend.

## Sin side effects

v1 no contiene:

- persistencia;
- DB;
- red;
- trackers/cookies;
- provider sync;
- gasto;
- scheduler;
- queue;
- WorkItems;
- decisiones de authority/budget.

La salida mantiene `execution=false`.

## Reversión

El slice es aditivo y puro. Revertir las cuatro rutas de #428 elimina el contrato sin migraciones ni estado externo que restaurar.
