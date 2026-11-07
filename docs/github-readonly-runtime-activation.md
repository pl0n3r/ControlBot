# ControlBot · activación GitHub GET-only (preparación offline)

**Alcance:** hoja #1484 del épico #55; autorizada exclusivamente para preparar código y ensayos sintéticos por decisión #1338/A, voto aislado del propietario y journal `factory-human-decision` v2/A. **Sin instalación, permisos de cuenta, tokens, red real, despliegue, gasto ni go-live.**

## Contrato de seguridad

`GitHubReadOnlyRuntimeActivation` no se conecta desde `public/` ni desde el router, y **nace apagado** (`enabled=false`). Su primera callback es una *fuente confiable inyectada por código de servidor*, no un parámetro del navegador. Los únicos parámetros solicitados son `repository`, `resource` y `page_size`, nunca IDs de instalación, grants, tokens o fechas de autoridad. La segunda callback es un **fake sender** sin acceso a red que solo puede observar envelopes del transporte desconectado ya existente.

La fuente produce un witness de prueba con `installation_id`, `owner`, `repository`, permisos mínimos `issues:read` o `pulls:read`, verificación, frescura y revocación; el grant de prueba requiere propietario y repo exactos, misma instalación, recurso aprobado, vigencia y ausencia de revocación. Es un **fixture**, no un consentimiento operativo ni un grant genuino. Owner mismatch, permisos adicionales, snapshot vencido, respuesta ambigua, 403/429, redirect, excepción, TTL incoherente y cualquier campo extraño bloquean fail-closed **antes** del sender cuando es fallo de autoridad.

El único sender permitido en esta hoja es un callback local. `GitHubReadOnlyHttpsTransport` genera exclusivamente `GET` a `https://api.github.com` con TLS, sin redirecciones, máximo tres páginas, 20 entradas por página y límites de bytes. No existe una ruta que lo cablee al runtime de Hostinger; no se presenta una página como viva por una ejecución en fixtures. Un resultado positivo solo devuelve `OFFLINE_VALIDATED` y `kind=offline_metadata_only`.

Siempre son verdaderas las invariantes `runtime_authorized=false`, `production_verified=false` y `execute_actions=false`. Auditoría contiene motivos de control sin identidad de instalación, datos de repositorios, PII ni secretos; `authority=unchanged`. Está prohibido usar esta hoja como permiso para mutaciones GitHub, incluido `issue.*`, `pr.merge`, workflow dispatch o releases.

## Verificación y puertas posteriores

Las cuatro pruebas `tests/test_github_readonly_runtime_activation.py` ejecutan PHP de producción con fixtures sintéticos: owner/scope/grant positivo, denegaciones por revocación o caducidad sin transporte, restricciones HTTPS/GET/paginación y fallos de HTTP, y flag apagado por defecto. CI `Validar`, SonarCloud, CodeQL, Policy y CodeRabbit FINAL sobre HEAD exacto siguen siendo obligatorios. La revisión jurídica D-059 de tratamiento y retención debe aprobarse **antes** de introducir credenciales o datos reales.

El propietario debe autorizar **separadamente** conexión de una instalación GitHub App real, permisos y scopes mínimos/revocables, identidad owner, gestión de credenciales privadas, sesiones y consentimientos; para producción se requieren además #625, #760 y D-043 con HTTPS owner-only, backup/restauración y evidencia exact-SHA. Esta aprobación **no** activa `live`, no cambia `datos.yml` y no completa los seis AC operativos del épico #55.

**Reversión:** revertir el eventual squash elimina los tres archivos nuevos. Como nunca se cablean red, instalación o datos, no hay cambios productivos que revertir.
