# Market Work Origin v1

`MarketWorkOrigin` convierte gaps conocidos de `MarketReadiness` en WorkItems compatibles con la cola unica de Factory.

Solo `blocked + fresh` con evidencia y `observed_at` valido puede materializar trabajo. `unknown` y `stale` permanecen no resueltos y nunca se convierten en permiso ni trabajo ejecutable. El WorkItem usa solo campos admitidos por Factory v1, no incluye `freshness` y normaliza `observed_at` a UTC.

Authority, policy, approval y budget solo se propagan desde contexto explicito. `work_id`, `idempotency_key`, claims y evidencia son deterministas por Venture, Market, country y domain. Un `launch_state=ready` produce `no_work`.

Este componente es puro y read-only. No persiste, agenda, prioriza, despacha ni ejecuta trabajo; Factory conserva esas responsabilidades. La reversión consiste en retirar las cuatro rutas de #444.
