# GitHub PR Review Visibility V1

## Límite

Esta extensión de `GitHubProjectSnapshot` completa únicamente la visibilidad read-only de pull requests requerida por ControlBot #55. No concede autoridad para revisar, fusionar, despachar workflows ni modificar Issues o releases.

## Contrato

- El listado de PRs abiertos sigue siendo la fuente base.
- Solo los primeros 20 PRs de cada repositorio reciben detalle adicional.
- El detalle usa exclusivamente GET a `/pulls/{number}` y `/pulls/{number}/reviews`.
- `mergeability` se normaliza a `mergeable|conflicting|unknown`.
- `review_state` se normaliza a `approved|changes_requested|commented|dismissed|pending|unknown`.
- Reviews repetidas del mismo reviewer se reducen a su última señal observada; la salida no contiene identidad, body, email ni comentario.
- Si el detalle excede el budget, falta, es ambiguo o no se puede validar, la evidencia queda `unknown` y el bucket de PRs se marca parcial/truncado.
- Una review o mergeability `unknown` degrada el estado del repositorio a `unknown`; nunca se infiere que un PR está listo.

## Seguridad y UX

El gate de regresión asociado a esta hoja es el check `Contrato ControlBot` sobre el HEAD exacto del PR.

No se añaden credenciales, scopes, endpoints mutantes ni nuevas fuentes de verdad. La vista del proyecto conserva estados UNKNOWN/STALE, escape HTML, layout mobile-first y ausencia de formularios o botones mutantes.
