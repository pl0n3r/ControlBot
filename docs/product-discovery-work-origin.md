# Product Discovery Work Origin v1

`ProductDiscoveryWorkOrigin` conecta una decisión de Product Discovery con la cola única de Factory. Solo materializa trabajo cuando la decisión recompuesta es `BUILD`, la clasificación es `VALIDATED` y la evidencia está `fresh`; cualquier otro estado falla cerrado con `work_item=null`.

## Límite
- Recompone `ProductDiscoveryDecision::decide()` desde los inputs fuente; no confía en un snapshot normalizado del caller.
- Emite únicamente campos admitidos por Factory WorkItem v1: `origin_mode=automatic`, `origin_system=controlbot` y `producer_ref=controlbot:product-discovery`.
- Authority, priority, roles, capabilities, policy, dependencies y claims son requisitos explícitos del caller; no prueban authority/budget/AEGIS/LEX ni autorizan ejecución.
- `freshness` permanece en el envelope/provenance porque no es campo de WorkItem v1; `observed_at` se deriva de `evaluation_window.end_at` en UTC.
- Provenance/evidence e `idempotency_key` son deterministas y ligados a Initiative → Hypothesis → Experiment → Assessment → Decision.

## Seguridad y operación
El schema es cerrado. Cross-scope, campos extra, duplicados, refs inválidas, secretos y PII directa fallan cerrado. El módulo no implementa readiness, ranking, dedupe global, scheduler, dispatch, provider, red, shell, persistencia ni ejecución; `execution=false` siempre.

## Reversión
El cambio es aditivo y sin estado persistente: revertir esta clase, sus pruebas y este documento restaura el comportamiento anterior sin migraciones ni rollback de datos.
