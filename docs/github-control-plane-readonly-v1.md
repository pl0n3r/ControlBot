# GitHub Control Plane — Read Model v1

## Límite

Este slice materializa únicamente la mitad de **visibilidad** de ControlBot #55.

`ProjectModel` sigue siendo la autoridad de qué repositorios pertenecen a un Proyecto. `GitHubProjectSnapshot` recibe ese Project, lo normaliza y consulta cada repositorio mediante el `ApiClient` existente.

No crea una segunda fuente de verdad y no introduce autoridad para mutar GitHub.

```text
ProjectModel
  ↓ repositorios autorizados
GitHubProjectSnapshot
  ↓ GET-only
GitHub API
  ↓ shape allowlisted
snapshot observado
```

## Señales v1

Por repositorio:

- SHA exacto de `main`;
- check-runs del SHA;
- PRs abiertos;
- Issues abiertos, excluyendo PRs que GitHub también entrega en `/issues`;
- último release si existe;
- último workflow run observado para `main`;
- `observed_at` explícito.

Las listas se consultan con límite 100. Alcanzar el límite o recibir un `total_count` mayor marca `truncated=true`; no se interpreta una página parcial como inventario completo.

## Fail-closed

El snapshot completo falla ante:

- Proyecto/repositorio fuera del contrato `ProjectModel`;
- SHA inválido;
- status/conclusion incoherentes;
- workflow devuelto fuera de `main`;
- shapes externos inválidos;
- conteos imposibles.

La ausencia de release es un estado válido y se representa como `null`.

No se transforma evidencia incompleta o ambigua en GREEN.

## Seguridad y autoridad

La proyección usa exclusivamente `GET` bajo el origen ya allowlisted por `ApiTransport`.

No contiene:

- merge/review;
- creación/edición/cierre de Issues;
- workflow dispatch;
- tags/releases mutantes;
- freeze/unfreeze;
- secrets nuevos;
- DB/cache;
- Dependabot cuando la API no esté disponible.

Las acciones privilegiadas de #55 siguen bloqueadas por Production Authority #45. Este slice no reduce ni sustituye ese gate.

## Reversión

Las cuatro rutas de #557 son aditivas. Retirarlas elimina el read model sin migración ni estado persistido.
