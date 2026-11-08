# ControlBot · piloto de consulta GitHub GET-only con autorización del propietario

**Alcance y autoridad:** hoja #1501 del épico #55, materializada exclusivamente por decisión OWNER #1493/A (comentario `/decidir A` #6713306650 y journal BOT v2/A #6713314061). La hoja anterior #1484/PR #1485 ya entregó la frontera sintética OFF y no se reabre.

## Implementación no activada

`GitHubOwnerScopedGetPilot` construye únicamente consultas **GET** a `https://api.github.com/repos/{owner}/{repo}/issues|pulls` con tamaño de página de 1–20, máximo 3 páginas, 32 KiB por respuesta y 64 KiB agregados. Cuenta con un adaptador nativo cURL limitado a TLS verificado, sin redirecciones y tiempos máximos de conexión/total 2/5 s. **No hay ningún wiring en rutas web, cron, bootstrap o Hostinger; el constructor nace con `enabled=false` (OFF).** No se instalaron ni conectaron cuentas, ni se usaron credenciales ni datos reales para desarrollar esta hoja.

El request del caller solo admite `repository`, `resource` y `page_size`; rechaza `token`, `installation_id`, `grant`, `url`, métodos o campos extra. El owner, ID de instalación, permisos y grant son exclusivamente de una **fuente server-side inyectada**, no verificables con un booleano suministrado por HTTP. Antes de consultar un proveedor secreto, valida identidad repo/owner exacta, instalación positiva, fuente verificada, únicamente `metadata:read` y `issues:read` o `pull_requests:read`, `approved=true`, revocación, frescura y expiración ≤300 s. El token lo proporciona un callback privado, nunca el navegador; no se devuelve ni registra.

La respuesta real de GitHub se reduce a número/estado de cada Issue/PR y marcas de tiempo; no conserva ni registra cuerpo, título, emails, credenciales ni cabeceras. Un fallo, 403/429, TLS no verificado, redirección, JSON extraño, paginación no terminada, falta de permiso, fuente ausente o caducidad produce `UNKNOWN` y proyección `null`: nunca GREEN. El resultado de **pruebas con sender fake** se llama `OFFLINE_VALIDATED`, no lectura real. Una futura lectura mediante el adaptador nativo reportará `SOURCE_READ`, que por sí sola tampoco certifica seguridad ni operación productiva.

Las invariantes de toda respuesta incluyen `runtime_authorized=false`, `production_verified=false` y `execute_actions=false`, `authority=unchanged`. No existe capacidad para escritura, `issue.*` mutante, `pr.merge`, `workflow.dispatch`, releases ni cambio de permissions. No hay modo de activar esta clase por petición pública.

## Verificación, privacidad y límites

`tests/test_github_owner_scoped_get_pilot.py` ejecuta el PHP **real** con una fuente/falso token/sender **locales**, cubriendo AC-01..04 de #1501, incluyendo rechazo de autoridad client-controlled, revocación, expiración, bloqueo del token, TLS/GET/host/paginación y errores, y ausencia de activación web. CI `Validar`, SonarCloud, CodeQL y CodeRabbit FINAL una sola vez tras CI verde deben verificarse sobre HEAD exacto.

**Las credenciales reales y la instalación GitHub App siguen reservadas al propietario.** Un permiso operativo específico por cuenta/repositorio, consentimientos de privacidad y aprobación jurídica de tratamientos/retención son requisitos independientes. El voto #1493/A autoriza código de piloto, **no** suministra token, habilita producción ni revoca #708/B sobre mutaciones. Hostinger, DNS, deploy, HTTPS owner-only, backup/restore, observación #625/#760 y D-043/D-059 permanecen fuera de alcance. El éxito de estos tests no acredita ninguno de los seis criterios operativos originales de #55; `datos.yml.phase=construccion` permanece.

**Riesgo y reversión:** cualquier futura conexión defectuosa puede exponer metadatos, por lo que la activación se conserva OFF hasta permiso/auditoría externos y smoke del OWNER. Revertir el squash de #1501 elimina esta clase, test y documento; como no hay wiring ni datos externos no queda efecto productivo que revertir.
