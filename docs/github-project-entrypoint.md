# GitHub project read-only entrypoint

`GitHubProjectEntrypoint` expone solo `GET /projects/{project_id}/github` para el owner. Lee el mismo `var/github-readonly.json` de la vista global, exige un único `project_id` exacto y renderiza `GitHubProjectView` + `GitHubProjectUi`.

Ruta malformada, Project ausente, duplicado o proyección inválida responden `404 Not found` sin mostrar evidencia de otros Projects. No contiene GitHub API, credenciales, mutaciones, deploy ni live.
