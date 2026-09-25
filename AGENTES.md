# AGENTES.md: ControlBot

> **Antes de trabajar, lee y aplica [PLAN-AGENTES.md](https://github.com/pl0n3r/factory/blob/main/PLAN-AGENTES.md)** (protocolo común de la fábrica: prioridades, límites, formatos, roles y decisiones del dueño). Si contradice este archivo, gana el plan.

## Contrato técnico

- **Stack:** PHP 8.5 + MariaDB, desplegable en Hostinger shared hosting (sin procesos Node permanentes ni WebSockets; tareas periódicas por cron).
- **Separación:** app, base de datos y deploy propios; nunca comparte código ni base con Condor.
- **Seguridad:** acceso solo del dueño (passkey o contraseña + 2FA TOTP), CSRF, rate limiting, sesiones cortas; la API del puente solo acepta llaves por perfil emparejadas y revocables.
- **Nunca** se guardan ni escriben contraseñas de ChatGPT, ni se resuelven captchas o verificaciones; el login de cada cuenta lo hace el dueño.
- Los tokens (GitHub, Sentry) viven solo en el `.env` del servidor.
- Bitácora de toda acción del dueño y de los agentes.
- **Decisiones del dueño:** `decisiones.yml` es normativo (D-054 a D-059); no se revierte.
- **Kit de factory:** CI, coordinación `/tomar`, criterios de aceptación, roles, etiquetas, releases, política, privacidad, deploy y observación se consumen desde `pl0n3r/factory/.github/workflows/...@v1`.
- **Privacidad:** ControlBot trata alias de cuentas, estados de agentes, bitácora y, opcionalmente, respuestas de chats; todo se declara en `datos.yml`. Los datos del responsable permanecen `[COMPLETAR POR EL DUEÑO]` durante construcción.
- **Producción:** deploy y observación permanecen deshabilitados hasta que un Issue explícito configure dominio, secretos y adapters; un merge de gobernanza no equivale a go-live.
