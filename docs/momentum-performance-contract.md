# MOMENTUM Performance Contract v1

## Propósito

`MomentumPerformance` es una proyección analítica pura. Conecta un snapshot gobernado de Paid Media, evidencia observada de spend/funnel/costo y `MomentumRevenue` para producir métricas sin crear causalidad, authority ni ejecución.

```text
Paid Media planned/current/execution=false
+ spend observado independiente
+ Revenue Pipeline/Attribution
→ Performance
```

Un plan de Paid Media **no es gasto observado**. Solo aporta provenance. `spend.amount_minor` se materializa únicamente desde una observación `observed` con evidencia propia y `governed_spend_ref` igual al `spend_ref` del snapshot gobernado.

## API y scope

```php
MomentumPerformance::project($raw, $paidMediaSnapshot, $pipeline, $attribution)
```

La proyección queda ligada a Venture, Campaign, período y currency. Paid Media debe estar `planned`, `current`, `execution=false`, en el mismo Venture/Campaign/currency y con evidencia dentro de la ventana. Pipeline y Attribution se revalidan con `MomentumRevenue`.

Performance no recibe `VerifiedAccessContext`, no llama `DecisionRights`/`CapitalPolicy` y no vuelve a decidir permisos o presupuesto.

## Evidencia

- `spend`: `observed|unknown`, amount, currency, `governed_spend_ref`, source/evidence/freshness.
- `funnel`: `observed|inferred|unknown`, leads/conversions y source/evidence/freshness.
- `cost`: `observed|unknown`, amount y source/evidence/freshness.
- `revenue`: proviene exclusivamente de `MomentumRevenue::attribution()`.

Toda evidencia temporal debe caer dentro del período. Duplicados, campos extra, scope/currency mismatch, refs inválidas y valores conocidos sin evidencia fallan cerrado. `stale|unknown` nunca se convierten en `current`.

## Derivaciones

Solo señales `current` participan en derivados:

- `margin = observed_revenue - observed_spend - observed_cost` cuando existen los tres.
- `CAC = observed_spend / conversions` si conversions > 0; si el funnel es inferido, CAC queda `inferred`.
- `ROAS` usa exclusivamente revenue `observed`.
- Revenue `inferred` se conserva separado y nunca alimenta margin/ROAS observado.
- Payback y LTV permanecen `unknown` en v1 por falta de cohortes/cash-flow/lifetime evidence suficientes.

## Límites

No hay PII, cookies, tracking, provider sync, DB, persistence, spend execution, scheduler, queue ni WorkItems. `execution=false` siempre.

MOMENTUM Paid Media gobierna la intención; Revenue conserva attribution; Performance solo proyecta evidencia agregada; CAPITAL mantiene la verdad financiera/authority donde aplique.

Reversión: retirar las cuatro rutas aditivas de #428; no existe estado externo ni migración.
