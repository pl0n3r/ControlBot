# Product Discovery Work Origin v1

`ProductDiscoveryWorkOrigin` es el límite entre una decisión de Product Discovery y la cola única de Factory. Su única responsabilidad es materializar un `WorkItem v1` compatible con Factory #269 cuando Discovery produce una decisión `BUILD` sustentada por evidencia válida. No decide readiness, prioridad efectiva, dispatch, ejecución ni autoridad.

## Fuente de verdad

El builder no acepta un snapshot de decisión autocertificado. Recompone la salida canónica mediante `ProductDiscoveryDecision::decide()` y, por esa cadena, vuelve a validar Assessment, Experiment, Initiative/Hypothesis y Outcome contra los inputs fuente. Cualquier mismatch de Venture/Product, schema o evidencia falla cerrado.

Para materializar trabajo se exige simultáneamente:

- `decision=BUILD`;
- `classification=VALIDATED`;
- `freshness=fresh`;
- inputs explícitos de política para `work_id`, `group_id`, `project_id?`, `repository_ref?`, `work_type`, capabilities, roles, authority, priority, policy, dependencies y claims;
- `execution=false`.

`ITERATE`, `PARK`, `STOP`, `RESEARCH_MORE`, y evidencia `stale|unknown` producen un resultado `blocked` con `work_item=null`.

## Compatibilidad con Factory WorkItem v1

El WorkItem emitido usa únicamente campos admitidos por Factory #269:

- `origin_mode=automatic`;
- `origin_system=controlbot`;
- `producer_ref=controlbot:product-discovery`;
- `venture_id` proviene de la decisión canónica;
- authority, priority, roles, capabilities, policy y claims provienen únicamente del caller/policy explícito;
- `evidence_refs` es la unión determinista de provenance y evidence refs de Discovery;
- `observed_at` se deriva del `end_at` de la ventana de evaluación y se normaliza a ISO-8601 UTC;
- `idempotency_key` se deriva determinísticamente del scope y de las refs canónicas de Discovery.

Factory v1 no admite `freshness` dentro del WorkItem, por lo que se conserva en el envelope y en `provenance`, nunca se inventa como campo del WorkItem.

## Autoridad y gates

Un `BUILD` de Discovery expresa intención de producto, no permiso de ejecución. Este boundary no verifica ni autocertifica budget, AEGIS, LEX, approval o autoridad real. `authority_level` es un requisito explícito del WorkItem y Factory debe evaluarlo después con su readiness canónico.

No existe scheduler, ranking, queue, provider, persistencia, red, shell ni ejecución local en este módulo. `execution` siempre es `false`.

## Seguridad y privacidad

El schema es cerrado. Refs vacías, material con forma de secreto/credencial, PII directa, duplicados, campos extra, repositorios inválidos y cross-scope fallan cerrado. Las listas se ordenan determinísticamente y nunca se persiste transcript, prompt, chain-of-thought ni dato personal nuevo.

## Reversión

El cambio es aditivo y puro. Revertir consiste en retirar `ProductDiscoveryWorkOrigin` y sus pruebas/documentación; no hay migración ni estado persistente que deshacer.
