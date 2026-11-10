# Skills de terceros (copias fijadas)

Skills de revisión copiadas aquí **sin modificar** y revisadas a mano (solo Markdown, sin hooks, scripts ni binarios).

## Ponytail: revisión y auditoría de código

| Carpeta | Origen | Commit | Licencia |
| --- | --- | --- | --- |
| `ponytail-review`, `ponytail-audit` | https://github.com/DietrichGebert/ponytail (`skills/`) | `96608e078b2d5ea607c5c3b8b5a54d1c1fba6846` | MIT (ver `_licenses/ponytail.MIT.txt`) |

Solo se copiaron los dos `SKILL.md` de revisión, **sin modificar** y revisados a mano. Se excluyeron a propósito el modo «perezoso» siempre activo y todos sus hooks (`SessionStart`, `SubagentStart`, `UserPromptSubmit`), el importador de transcripciones y los scripts. Cada skill se invoca solo cuando se pide (`/ponytail-review`, `/ponytail-audit`) y entrega un informe sin editar.
