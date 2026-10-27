# Contrato E2E offline owner-only de proyección GitHub — #993

**Autorización:** [ControlBot #945/A](https://github.com/pl0n3r/ControlBot/issues/945), voto aislado [OWNER /decidir A](https://github.com/pl0n3r/ControlBot/issues/945#issuecomment-6443238339) y [journal v2/A del bot](https://github.com/pl0n3r/ControlBot/issues/945#issuecomment-6443256097). Dependencias #735, #934 y #943 completadas. Épico #55 permanece OPEN.

## Qué se valida

El nuevo test `tests/test_github_projection_owner_e2e.py` conecta **solo durante la prueba** el pipeline PHP previamente integrado `GitHubReadOnlyProjectionPipeline` a su writer de JSON sintético, ambos con source/transporte `Closure` fake, y vuelve a leer el archivo desde `GitHubProjectEntrypoint` (`/projects/factory-control/github`) y `GitHubPublicEntrypoint` (`/github`) en PHP CLI.

El JSON solo se crea en un directorio `cb-projection-fixture-*` de primer nivel bajo el temporal del sistema, nunca en `var/github-readonly.json`. Los SHA, usuarios, checks y repositorios son ficticios. Los parámetros de `php -r` se transmiten como JSON/base64 local, sin red, API GitHub, usuarios reales, tokens, cookies o infraestructura Hostinger.

| AC | Test `GitHubProjectionOwnerE2ETests` | Aserción |
| --- | --- | --- |
| 01 | `test_pipeline_fixture_renders_owner_project_view` | Fixture fresco, archivo atómico, vistas owner GET=200 y estado/frescura CURRENT |
| 02 | `test_non_owner_and_write_requests_fail_closed` | Actor distinto=403, métodos POST/PUT/DELETE=405, cuerpos sin identidad de proyecto |
| 03 | `test_stale_or_invalid_projection_never_claims_current` | Caducado indica STALE/UNKNOWN; JSON inválido no expone proyecto ni afirma CURRENT |
| 04 | `test_fixture_permissions_and_runtime_remain_disabled` | Harness 0700, archivo 0600, sin staging residual, flags de ejecución/productivo falsos |

## Límites, privacidad y reversión

- El **harness**, no el writer ya integrado, impone y verifica permisos **0700** del directorio de fixture y ausencia de symlink; el writer conserva **0600** en el archivo de etapa y promueve atómicamente. No se modifica el writer ni se afirma que asegure los permisos de la carpeta por sí solo.
- El rechazo 403 ocurre antes de leer el archivo, todas las respuestas llevan `Cache-Control: no-store`, y rutas/métodos no GET no activan handlers de escritura.
- `runtime_authorized=false`, `production_verified=false`, `execute_actions=false`. CURRENT denota únicamente frescura de datos de **prueba**: no demuestra GitHub real ni `VALIDATED_IN_PRODUCTION`.
- No hay tratamiento personal, terceros, transferencias ni retención fuera de los fixtures temporales. `datos.yml`, `src/`, workflows y permisos productivos no cambian.
- No concede grants, tokens, GitHub App, Hostinger, pairing, cron, deploy, bases, dinero o go-live. No satisface los seis AC de operación real de #55 ni las puertas independientes #625/#760.
- `python3 -m unittest tests.test_github_projection_owner_e2e -v`; CI `Validar` exact HEAD, SonarCloud, CodeQL y una sola revisión FINAL CodeRabbit tras verde. Revert del squash restaura el estado sin migraciones.
 
**Roles:** ingeniería de software (compatibilidad Python→PHP), QA (regresión), seguridad (autorización y permisos), legal-privacidad (minimización/autoridad).
