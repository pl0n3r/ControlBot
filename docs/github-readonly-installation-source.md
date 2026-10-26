# Fuente GET-only de instalación GitHub · exclusivamente offline (#924)
## Autoridad y límites
Esta hoja corresponde a [ControlBot #55](https://github.com/pl0n3r/ControlBot/issues/55) y al voto auténtico **A** del propietario en [#919](https://github.com/pl0n3r/ControlBot/issues/919), con journal v2/A. Es una frontera **solo para pruebas de integración sintéticas**, desconectada del panel desplegado y de cualquier cliente HTTP. No habilita GitHub App, OAuth, instalación productiva, scopes reales, red, tokens, SSH, Hostinger, release ni cambio de permisos. D-043/D-059 y las puertas #45/#625 siguen vigentes.
**Invariantes de cualquier salida:** `runtime_authorized=false`, `production_verified=false`, `execute_actions=false`, `authority=unchanged`. Un `CURRENT` significa únicamente *fixture sintético fresco* (`reason=fixture_only`): jamás significa producción GREEN o instalación real verificada. `fixture_projection` solo contiene repo, SHA, recurso, cantidad de objetos y páginas, nunca payloads.
## Fronteras de confianza
- El `serverFixture` proviene exclusivamente del harness offline del servidor, nunca de HTTP o formulario. Declara `origin=server_fixture`, `test_only=true`, `owner_verified=true`, instalación sintética, propietario, repositorio, SHA de 40 hex, scopes exactos `contents:read`/`metadata:read`, ventana de validez máxima de 300 s y un HMAC-SHA256 de prueba. El HMAC **no** certifica una instalación GitHub auténtica ni representa credenciales reales.
- La solicitud solo puede usar el mismo repositorio/SHA, recursos `issues` o `pulls` y 1–25 objetos por página. No hay URL arbitraria ni métodos distintos de `GET`.
- La Closure `fakeGet` inyectada recibe solo método `GET`, ruta relativa validada, página, tamaño y `offline_test_only`. No crea peticiones HTTP.
- Máximo tres páginas con `status=200`, repo/SHA exactos, `truncated=false`, continuidad coherente y elementos estrictos `{id,state}`. 403/429, timeout, paginación truncada, identidad ajena, payload inesperado o caducidad producen `UNKNOWN` y `fixture_projection=null`. Nunca publica resultados parciales.
- La auditoría en memoria solo contiene estado, motivo genérico y páginas (máximo 32 eventos), sin PII, secretos, llaves, URLs completas, respuestas del transporte ni textos de excepciones.
## Contrato de aceptación ejecutable
```sh
python3 -m unittest tests/test_github_readonly_installation_source.py -v
php -l src/GitHubReadOnlyInstallationSource.php
```
| Criterio | Test |
| --- | --- |
| AC-01 | `GitHubReadOnlyInstallationSourceTests.test_exact_repository_and_sha_read_only_projection` |
| AC-02 | `GitHubReadOnlyInstallationSourceTests.test_missing_owner_installation_or_scope_denies_network` |
| AC-03 | `GitHubReadOnlyInstallationSourceTests.test_403_429_timeout_partial_and_stale_fail_closed` |
| AC-04 | `GitHubReadOnlyInstallationSourceTests.test_no_writes_secrets_or_pii_in_audit` |
Las pruebas ejecutan PHP real desde Python mediante `php -r` y fake inyectado, sin credenciales. Exigir `Validar`, SonarCloud, CodeQL exact-HEAD y CodeRabbit **solo FINAL** una vez que CI esté verde.
## Despliegue y reversión
No existe integración al runtime para esta fuente. Conectarla a GitHub real, dotarla de scopes, secretos o desplegarla requiere una **nueva autorización explícita** y evidencia de identidad, operador, seguridad y observabilidad. Revertir el squash retira estos tres archivos sin migraciones ni efectos remotos.
