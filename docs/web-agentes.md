# Vista de Agentes, cuentas y sesiones (ControlBot #1449)

## Alcance y autoridad

La página `GET /agentes` y `GET /api/v1/agents/presence` son proyecciones de **solo lectura**, no mecanismos de conexión, autorización para operar agentes ni fuentes de credenciales. El front controller público `ControlBotWebEntrypoint::handle()` rechaza `/agentes`. El canal HTML se habilita solo en `handleAuthenticated()` después de verificar la sesión del propietario mediante `OwnerSessionService`, la identidad configurada `CONTROLBOT_OWNER_LOGIN` y la sesión OAuth/TOTP existente. `handleLocalOwner()` presenta un estado `UNKNOWN` sin sustituir la autenticación completa.

La API de presencia se enruta por el registro autenticado `OwnerAuthenticatedRouter` y exige una operación `agents.presence` proporcionada desde el servidor. No se aceptan encabezados `REMOTE_USER`, `Authorization` ni cookies del navegador como sustituto de la sesión verificada.

## Contrato de origen y minimización

El backend `agents.presence` debe entregar exactamente cuatro campos: `accounts`, `agents`, `rows` y `capacity_observations`; las colecciones deben ser listas válidas y consistentes. `AgentRuntime` y `PresenceAdapter` validan la estructura, identidad de agentes/cuentas, sesiones y antigüedad. Datos incoherentes, campos extra, credenciales embebidas o relaciones cruzadas se rechazan sin exponer valores; la ausencia de observaciones se representa como `UNKNOWN`, no como presencia positiva.

La proyección pública al propietario contiene únicamente identificadores y alias operativos autorizados, roles, presencia, estado de sesiones y capacidad observada. El HTML escapa contenido variable y aplica `Cache-Control: no-store`. Ni la API ni la página devuelven tokens, cookies, credenciales o payloads del adaptador. Los errores son no reveladores y evitan registrar contenido del backend.

## Pruebas y límites operativos

`python3 -m unittest -v tests.test_web_agents tests.test_owner_routes_registry` cubre el criterio principal, dueño/anonimato, origen no confiable, relaciones inconsistentes, sanitización, estados UNKNOWN y bloqueo de rutas públicas. Los fixtures son sintéticos: **no acreditan cuentas, presencia real, HEALTH productivo ni go-live**. Se mantiene `datos.yml.phase=construccion`, sin secretos ni datos de clientes.

Esta hoja reconstituye los cambios probados del PR anterior #1465, cerrado sin merge bajo autorización específica del OWNER #1492/A y journal BOT v2/A. La revisión anterior de CodeRabbit fue rate-limited sobre un SHA distinto y **no** autoriza la nueva fusión. El nuevo PR requiere V3 auténtica, `CI Factory v1 / Validar` terminal exact-HEAD, SonarCloud, CodeQL y una sola revisión CodeRabbit FINAL sin hallazgos graves.

Reversión: revertir el squash de este PR; no requiere modificación del esquema, proveedor externo ni despliegue.
