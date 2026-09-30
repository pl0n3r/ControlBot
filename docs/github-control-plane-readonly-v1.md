# GitHub Control Plane — Read Model v1

#557 separa la visibilidad read-only de las acciones privilegiadas todavía bloqueadas en #55 por Production Authority #45.

`ProjectModel` autoriza los repositorios; `GitHubProjectSnapshot` reutiliza `ApiClient` y solo hace GET bajo `api.github.com/repos/*`. Por repo expone SHA de main, checks, PRs, Issues sin duplicar PRs, último release, último workflow de main y `observed_at`.

Checks/PRs/Issues se limitan a 100 y marcan `truncated=true` cuando la vista puede ser parcial. Ausencia de release es `null`. SHA, shapes, scope o status/conclusion ambiguos fallan cerrado: nunca se fabrica GREEN.

Fuera de alcance: UI, DB/cache, Dependabot, merge/review, mutación de Issues, workflow dispatch, tags/releases mutantes, freeze, secrets, AutoFactory y FactoryRunner.

Reversión: retirar las cuatro rutas de #557; no existe migración ni estado externo.
