# AGENTES.md: factory-control

> **Antes de trabajar, lee y aplica [PLAN-AGENTES.md](https://github.com/pl0n3r/factory/blob/main/PLAN-AGENTES.md)** (protocolo común de la fábrica: prioridades, límites, formatos, roles y decisiones del dueño). Si contradice este archivo, gana el plan.

## Contrato técnico

- **Stack:** PHP 8.5 + MariaDB, desplegable en Hostinger shared hosting (sin procesos Node permanentes ni WebSockets; tareas periódicas por cron).
- **Separación:** app, base de datos y deploy propios; nunca comparte código ni base con Condor.
- **Seguridad:** acceso solo del dueño (passkey o contraseña + 2FA TOTP), CSRF, rate limiting, sesiones cortas; la API del puente solo acepta llaves por perfil emparejadas y revocables.
- **Nunca** se guardan ni escriben contraseñas de ChatGPT, ni se resuelven captchas o verificaciones; el login de cada cuenta lo hace el dueño.
- Los tokens (GitHub, Sentry) viven solo en el `.env` del servidor.
- Bitácora de toda acción del dueño y de los agentes.
- Mientras no exista el kit v1 de factory, seguir las convenciones de Condor para CI, etiquetas, releases y coordinación.
