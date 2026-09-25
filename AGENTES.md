# AGENTES.md: ControlBot

> **Antes de trabajar, lee y aplica [PLAN-AGENTES.md](https://github.com/pl0n3r/factory/blob/main/PLAN-AGENTES.md)**. Si contradice este archivo, gana el plan.

## Contrato técnico

- **Stack:** PHP 8.5 + MariaDB, desplegable en Hostinger shared hosting (sin procesos Node permanentes ni WebSockets; tareas periódicas por cron).
- **Separación:** app, base de datos y deploy propios; nunca comparte código ni base con Condor.
- **Seguridad:** acceso solo del dueño (passkey o contraseña + 2FA TOTP), CSRF, rate limiting y sesiones cortas; la API del puente solo acepta llaves por perfil emparejadas y revocables.
- **Nunca** se guardan ni escriben contraseñas de ChatGPT, ni se resuelven captchas o verificaciones; el login de cada cuenta lo hace el dueño.
- Los tokens de GitHub y Sentry viven solo en el `.env` del servidor.
- Bitácora de toda acción del dueño y de los agentes.
- **Decisiones del dueño:** `decisiones.yml` es normativo (D-054 a D-059); no se revierte.
- **Factory v1:** CI, coordinación `/tomar`, criterios de aceptación, roles, etiquetas, releases, política, privacidad, deploy y observación se consumen desde `pl0n3r/factory/.github/workflows/...@v1`.
- **Privacidad:** alias de cuentas, estados de agentes, bitácora y, opcionalmente, respuestas de chats se declaran en `datos.yml`. Los datos del responsable permanecen `[COMPLETAR POR EL DUEÑO]` durante construcción.
- **Producción:** deploy y observación permanecen fail-closed hasta que un Issue explícito configure dominio, secretos y adapters; un merge de gobernanza no equivale a go-live.
- **AutoFactory:** es el puente externo estable del navegador. No se modifica desde trabajo ordinario de ControlBot mientras siga estable; solo se abre/toma trabajo allí cuando una integración concreta de ControlBot lo requiera.

## Orden vigente del dueño

1. #2 — adopción del kit Factory v1.
2. #3 — aprobaciones con un clic.
3. #4 — centro de decisiones.
4. Dashboard básico siguiendo la dirección visual de #18.
5. Después, Issues restantes por prioridad y dependencias.
