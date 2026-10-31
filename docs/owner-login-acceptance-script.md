# Guion de aceptación owner-only: login, 2FA/TOTP, CSRF y rutas privadas

**Alcance:** preparación **offline** de ControlBot #961. **Estado global: NO ENSAYADO** en servicio instalado; `datos.yml.phase=construccion`. No es un visto bueno para live, ni una validación operativa. No registrar identidades, contraseñas, tokens, códigos TOTP, cookies, claves, direcciones IP ni capturas de contenido sensible.

## Fuente y frontera de autoridad

- `src/OwnerLoginGate.php` define el contrato de login local, CSRF, sesión, revocación/logout, 900 segundos de inactividad y throttle; `tests/test_owner_login_gate.py` ofrece **pruebas sintéticas**, no evidencia de hosting.
- `public/login.php` contiene la entrada HTTPS y cookies seguras. Desde #1346 `.htaccess` sirve `/login`, y `public/index.php` redirige con 302 a `/login?next=…` a quien no tenga sesión (las rutas que no son de navegador responden 403, sin `WWW-Authenticate`). La ventana de Basic Auth solo se retira cuando el dueño crea el archivo marcador `/home/u151692719/.htpasswds/owner-login-enabled` y el servidor tiene `CONTROLBOT_OWNER_PASSWORD_HASH`; sin ambos, todo sigue cerrado como antes. Tras entrar se vuelve a la página pedida (solo rutas internas) o a `/`; salir es por POST con CSRF.
- `src/OwnerSession.php` y `tests/test_owner_session.py` definen una ruta **distinta**, de sesión OAuth confiable con reautenticación TOTP. `src/Routing/OwnerAuthenticatedRouter.php` y `tests/test_authenticated_router.py` verifican su contrato offline. **UNKNOWN** si ambas rutas están cableadas al mismo login instalado: no asumir TOTP aplicado al formulario local por existir esa clase.
- `docs/security/owner-login-throttle.md` documenta límite por IP y riesgos residuales; `tests/test_owner_access_htaccess.py` inspecciona el Basic Auth del árbol, sin comprobar el borde productivo.
- `docs/lista-salida-produccion.md` conserva la fila «Prueba de autenticación, CSRF, TOTP y accesos restringidos» en `DEL_DUEÑO`, vinculada a [#625](https://github.com/pl0n3r/ControlBot/issues/625). **No cambiar** esa clasificación con pruebas offline.

## Precondiciones del ensayo real

**Estado: NO ENSAYADO.** El operador autorizado debe disponer de una instalación HTTPS identificada por versión/SHA, decisión específica de operación/go-live, respaldo y rollback según D-059, cuentas de ensayo autorizadas, segundo factor configurado y canal protegido para registrar evidencias opacas. Si falta cualquiera de estos elementos: **NO-GO**; no realizar peticiones, iniciar sesión ni modificar Hostinger. Mantener las pruebas en esta hoja como `NO ENSAYADO`.

## Casos numerados y resultados esperados

### TC-01 — Login owner-only y credenciales inválidas

**Estado: NO ENSAYADO.** **Fuente:** `src/OwnerLoginGate.php`, `tests/test_owner_login_gate.py`, `public/login.php`. **Resultado esperado (PROPUESTA):** únicamente la identidad propietaria autorizada accede a la sesión privada; una identidad ajena o credencial errónea obtiene denegación genérica sin enumerar cuentas. Verificar transporte HTTPS y ausencia de credenciales en URL, pantalla de error y recibo. La prueba del código no prueba un login real instalado.

### TC-02 — Segundo factor TOTP y reautenticación

**Estado: NO ENSAYADO.** **Fuente:** `src/OwnerSession.php`, `tests/test_owner_session.py`, `tests/test_authenticated_router.py`. **Resultado esperado (PROPUESTA):** la ruta privada que requiera OAuth+TOTP rechaza un código inválido o ausente, admite solo reautenticación vigente y deniega la caducada. **UNKNOWN:** si el login del servicio desplegado integra TOTP; la ruta local `OwnerLoginGate` no acredita por sí sola esta conexión.

### TC-03 — CSRF en login, logout y acciones sensibles

**Estado: NO ENSAYADO.** **Fuente:** `src/OwnerLoginGate.php`, `src/OwnerSession.php`, `tests/test_owner_login_gate.py`, `tests/test_owner_session.py`. **Resultado esperado (PROPUESTA):** POST sin token, token incorrecto o sesión cambiada no produce autenticación ni acción; respuesta local prevista de login: 403. Un token válido solo es aceptable dentro de la sesión correspondiente. No copiar el token al recibo.

### TC-04 — Caducidad de sesión, cookies y cierre de sesión

**Estado: NO ENSAYADO.** **Fuente:** `src/OwnerLoginGate.php`, `public/login.php`, `tests/test_owner_login_gate.py`. **Resultado esperado (PROPUESTA):** tras 900 segundos sin actividad se exige autenticación nueva; logout invalida acceso posterior; una cookie previa no restituye privilegios. HTTPS, `Secure`, `HttpOnly`, `SameSite=Strict`, regeneración de ID y no-cache se deben constatar en tráfico **autorizado**, sin guardar cookies.

### TC-05 — Revocación y denegación después del cierre

**Estado: NO ENSAYADO.** **Fuente:** `src/OwnerLoginGate.php`, `src/OwnerSession.php`, `docs/identity-center.md`. **Resultado esperado (PROPUESTA):** logout y revocación específica de la autoridad o grant aplicable impiden operaciones posteriores; un permiso de una identidad o alcance distinto no se hereda. **UNKNOWN:** mecanismo real de propagación de revocaciones entre rutas en entorno desplegado; no inferirlo de contratos separados.

### TC-06 — Rutas restringidas y suplantación de cabeceras

**Estado: NO ENSAYADO.** **Fuente:** `src/OwnerLoginGate.php`, `src/Routing/OwnerAuthenticatedRouter.php`, `tests/test_authenticated_router.py`, `tests/test_owner_access_htaccess.py`. **Resultado esperado (PROPUESTA):** las rutas privadas rechazan peticiones sin sesión confiable y la cabecera `REMOTE_USER` manipulada no confiere rol owner; rutas fuera de allowlist y traversal no exponen datos. Acreditar separadamente Basic Auth del borde y control aplicativo.

### TC-07 — Limitación de intentos y recuperación segura

**Estado: NO ENSAYADO.** **Fuente:** `docs/security/owner-login-throttle.md`, `src/OwnerLoginGate.php`, `tests/test_owner_login_gate.py`. **Resultado esperado (PROPUESTA):** el límite por IP deniega intentos excesivos con `429` y `Retry-After` coherente, y no permite desactivar el throttle como solución rápida. No ejecutar carga, fuerza bruta ni limpiar contadores reales fuera de una ventana operativa expresamente autorizada.

### TC-08 — Evidencia de identidad, versión y no filtración

**Estado: NO ENSAYADO.** **Fuente:** `docs/lista-salida-produccion.md`, `docs/hostinger-go-live-plan.md`, `public/login.php`. **Resultado esperado (PROPUESTA):** cada comprobación conserva resultado, evidencia opaca y versión/SHA del artefacto observado sin secretos ni datos de usuarios; `UNKNOWN` ante falta de fuente. Un `Validar` verde no sustituye `VALIDATED_IN_PRODUCTION` con pendientes vacíos.

## Plantilla de recibo seguro (no ejecutado)

- **Estado de ejecución:** `NO ENSAYADO`
- **Caso:** `TC-XX`
- **Versión / SHA de servicio observado:** `UNKNOWN`
- **Ámbito:** `PROPUESTA — instalación autorizada sin datos reales`
- **Operador autorizado:** `UNKNOWN` (no registrar identidad personal en este documento)
- **Resultado observado:** `NO ENSAYADO` / `UNKNOWN` (nunca marcar PASS por inferencia)
- **Identificador opaco de evidencia:** `UNKNOWN` (no URL firmada, cookie, token ni contenido de respuesta)
- **Incidencias, mitigación y rollback:** `NO ENSAYADO`
- **Decisión de salida:** `NO-GO`, sujeta al propietario y a D-059

## Gate de salida y trazabilidad

La fila **«Prueba de autenticación, CSRF, TOTP y accesos restringidos»** en [`docs/lista-salida-produccion.md`](lista-salida-produccion.md) continúa **`DEL_DUEÑO`**. Exige verificación real por operador y propietario, no solamente `tests/test_guion_login_doc.py`, `Validar`, SonarCloud o CodeQL. **Estado: NO ENSAYADO; NO-GO.** La hoja #961 autoriza solo este guion y su prueba documental.
