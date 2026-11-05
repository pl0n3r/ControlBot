# ControlBot #1449 — Agentes, cuentas, sesiones y presencia

**Estado:** implementación solo lectura para fase `construccion`, sin credenciales, datos reales, acceso live ni despliegue.

La página **`GET /agentes`** se sirve únicamente tras el gate existente de sesión local del OWNER o la validación OAuth+TOTP de `handleAuthenticated`. La vista usa `ControlCenterShell` y muestra agentes (rol/presencia), cuentas (alias/estado/capacidad observada), sesiones (estado/presencia) y resumen de capacidad. Los valores desconocidos y las observaciones ausentes se expresan como **`UNKNOWN`**, nunca como capacidad disponible o presencia comprobada.

El endpoint **`GET /api/v1/agents/presence`** se registra automáticamente por `src/Routing/Routes/AgentRoutes.php` en `OwnerAuthenticatedRouter`; exige OAuth+TOTP del dueño, no acepta POST, emite JSON con `Cache-Control: no-store` y depende exclusivamente del adapter servidor `agents.presence` inyectado de confianza. Un adapter ausente responde **503**, sin inventar telemetría. La sesión local de contraseña no concede acceso al endpoint OAuth.

Contrato de origen sintético del adapter: un objeto con exactamente `accounts`, `agents`, `rows` y `capacity_observations`. Se validan modelos con `AgentRuntime` y cálculo de frescura/capacidad con `PresenceAdapter`. Solo se proyectan campos permitidos, jamás objetos originales, encabezados, tokens, cookies ni secretos. Datos inconsistentes o adicionales fallan cerrados; alias HTML se escapan para prevenir XSS.

**Verificación:** `python3 -m unittest -v tests.test_web_agents` comprueba AC-01 (`test_criterio_principal`) y AC-02 (`test_criterio_seguridad_y_bordes`): página, JSON, capacidades, desconocidos, acceso anónimo, solo lectura, adapter ausente, relaciones inválidas, secretos y XSS. AC-03 exige CI Factory exact-SHA verde. No se afirma presencia productiva ni cobertura E2E con cuenta real.

**Reversión:** revertir el squash del PR; sin migraciones, claves, operaciones de red ni cambios de estado.
