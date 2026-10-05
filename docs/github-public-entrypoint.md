# GitHub read-only public entrypoint

`GitHubPublicEntrypoint` expone únicamente `GET /github` detrás del owner gate existente y lee `var/github-readonly.json`; nunca llama GitHub directamente.

Contrato local: `{"version":1,"projects":[...]}`. Cada snapshot se normaliza con `GitHubProjectView` y se renderiza con `GitHubGlobalUi`; evidencia ausente, inválida, stale u oversized permanece `UNKNOWN`.

Frontera: owner incorrecto → 403; configuración inválida → 503; método distinto de GET → 405; path distinto de `/github` → 404. Todas las respuestas usan `no-store`, `nosniff`, frame-deny y no-referrer.

No contiene credenciales GitHub, networking, workflow dispatch, merge, mutaciones de Issues, deploy ni live. El wiring de `public/index.php` queda para el leaf dependiente.
