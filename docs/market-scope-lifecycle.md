# Market Scope lifecycle

Segundo slice de #162. Este contrato convierte un cambio de Market Scope en una señal mínima y determinista, sin persistirla ni ejecutar motores externos.

## TBD y transición

Un Venture puede permanecer con Market Scope TBD durante discovery. TBD se representa como ausencia de scope. `MarketScopeLifecycle::change()` permite `null → MarketScope` y exige que el siguiente scope sea válido según el contrato canónico de #232.

Una transición defined → defined se compara después de normalizar ambos scopes. Cambiar solo el orden de listas no produce un cambio material y falla como no-op.

## Evidencia y deltas

El evento no copia snapshots completos. Conserva fingerprints SHA-256 before/after y deltas explícitos para mode, primary country, target, excluded, launch, expansion, currency y locale.

`global` nunca expande países implícitos. Un delta contiene únicamente países presentes en las listas canónicas del caller.

## Reassessment

Todo cambio material emite dos señales booleanas:

- `lex_reassessment_required=true`;
- `readiness_reassessment_required=true`.

Estas señales invalidan conclusiones previas para consumidores posteriores; no ejecutan LEX ni readiness y no producen `COMPLIANT`, `GAP`, scores o aprobaciones.

## Límite

El contrato es read-only y `execution=false`. No hay base de datos, event store, provider calls, Factory WorkItems, scheduler ni queue paralela. Persistencia, materialización de trabajo y la respuesta “qué falta para lanzar” pertenecen a slices posteriores.
