# AGENTES.md: ControlBot

> **Antes de trabajar, lee y aplica [PLAN-AGENTES.md](https://github.com/pl0n3r/factory/blob/main/PLAN-AGENTES.md)** (protocolo común de la fábrica: prioridades, límites, formatos, roles y decisiones del dueño). Si contradice este archivo, gana el plan.

## Contrato técnico

- **Stack:** PHP 8.5 + MariaDB, desplegable en Hostinger shared hosting (sin procesos Node permanentes ni WebSockets; tareas periódicas por cron).
- **Separación:** app, base de datos y deploy propios; nunca comparte código ni base con Condor.
- **Seguridad:** acceso solo del dueño (passkey o contraseña + 2FA TOTP), CSRF, rate limiting, sesiones cortas; la API del puente solo acepta llaves por perfil emparejadas y revocables.
- **Nunca** se guardan ni escriben contraseñas de ChatGPT, ni se resuelven captchas o verificaciones; el login de cada cuenta lo hace el dueño.
- Los tokens (GitHub, Sentry) viven solo en el `.env` del servidor.
- Bitácora de toda acción del dueño y de los agentes.
- **Decisiones del dueño:** `decisiones.yml` es normativo (copia de las vigentes en factory, D-054 a D-059); no se revierte.
- **Kit de factory:** CI, coordinación `/tomar`, etiquetas, releases, política, privacidad y observación se consumen desde `pl0n3r/factory/.github/workflows/...@v1` (épico de adopción en este repo). Hasta completarlo, seguir las convenciones de Condor.
- **Privacidad:** ControlBot trata alias de cuentas, estados de agentes y, opcionalmente, respuestas de chats: todo debe declararse en `datos.yml` (privacidad como código, factory#54).
