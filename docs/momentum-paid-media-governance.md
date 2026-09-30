# MOMENTUM Paid Media Governance v1

`MomentumPaidMedia` es una frontera de **planificación pura** para campañas con canal `paid_social` o `search_ads`. No ejecuta anuncios, pagos, colas ni adapters de proveedor.

## Contrato

- La campaña se normaliza con `MomentumCampaign` y debe mantener el mismo Venture, `budget_ref` y canal paid.
- La autoridad se decide con `DecisionRights::evaluate`; un `budget_ref`, `secret_scope_ref` o cualquier referencia opaca nunca concede permiso.
- El gasto se valida con `CapitalPolicy::evaluate` en el mismo Venture, moneda y monto de propuesta.
- `freshness=stale|unknown`, authority deny o CAPITAL deny produce `denied`.
- Cualquier owner gate se conserva como `owner_decision_required`.
- Una reallocation puede redistribuir dentro del mismo total; aumentar el gasto total o exceder el límite explícito requiere Owner Decision.
- `secret_scope_ref` es solo `scope:<32 hex>`; credenciales, tokens y campos extra fallan cerrado.
- Toda salida mantiene `execution=false`.

## Límites

ControlBot proyecta intención gobernada. Decision Rights conserva autoridad y CAPITAL conserva presupuesto. Factory/FactoryRunner siguen siendo las superficies de cola/ejecución fuera de este contrato. No hay API de Meta, Google, TikTok, payment, bidding, scheduler, persistencia ni side effects.
