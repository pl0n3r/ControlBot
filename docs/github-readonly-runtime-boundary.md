# Frontera GET-only desconectada · ControlBot #927

## Autoridad
[ControlBot #926/A](https://github.com/pl0n3r/ControlBot/issues/926) y su journal de decisión v2/A autorizan exclusivamente una **preparación offline** de la hoja #927 del roadmap #55. Requiere un fake `Closure` inyectado por el harness de pruebas, nunca un SDK, cuenta o conexión GitHub real. El permiso #926/A **no sustituye** D-043/D-059, #708/B, #45, #625 ni decisión go-live.

## Diseño
- `GitHubReadOnlyRuntimeBoundary` se mantiene fuera del runtime y no crea clientes HTTP. Reutiliza `GitHubReadOnlyInstallationSource` para verificar HMAC sintético del fixture, owner, instalación, repositorio y SHA, scopes solo lectura, GET `issues|pulls`, páginas y payloads acotados.
- Sin `ownerGrant` sintético explícito, fresco y alineado exactamente con la identidad del fixture, el fake **no se invoca**. `origin=server_test_grant` y `owner_verified=true` son datos **solo del harness**, no prueba de sesión humana productiva ni grant real.
- Solo se devuelve `CURRENT/fixture_only` después de observar todas las páginas completas; significa **fixture de prueba**, no instalación válida en producción. Errores HTTP 403/429, timeout, origen, grant, scope, identidad, paginación o expiración ⇒ `UNKNOWN` y `fixture_projection=null`. Nunca datos parciales.
- En todas las rutas: `runtime_authorized=false`, `production_verified=false`, `execute_actions=false`, `authority=unchanged`. Auditoría en memoria de máximo 32 estados/motivos genéricos, sin identificadores, excepciones, respuestas ni PII.
- No agregar rutas de panel, PHP web endpoints, tokens, credenciales, permisos, sockets ni deploy; la Closure proviene exclusivamente del test. No afirmar `VALIDATED_IN_PRODUCTION`.

## Validación
```sh
php -l src/GitHubReadOnlyRuntimeBoundary.php
python3 -m unittest tests.test_github_readonly_runtime_boundary -v
```
La suite ejecuta PHP real con transporte simulado y cubre cuatro criterios AC-01..04 del Issue #927. Se exige CI `Validar` y calidad de seguridad sobre el SHA final del PR, revisión CodeRabbit FINAL una sola vez y squash con V3 sin cambiar las puertas de producto ni habilitar runtime. Revertir el squash elimina exclusivamente los tres archivos de esta hoja.
