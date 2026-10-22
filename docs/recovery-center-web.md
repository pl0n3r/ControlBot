# AEGIS Recovery: owner-only web route (offline)

El endpoint **GET /security/resilience** sirve una pantalla **owner-only** únicamente
mediante `ControlBotWebEntrypoint::handleAuthenticated`: OAuth sellado y
TOTP válidos del dueño, nunca `REMOTE_USER` ni cabeceras del cliente. El método
POST falla con 405; sin sesión verificada, 403. Se aplican CSP, no-store,
nosniff, frame-deny y HTML escapado; no hay formularios, scripts, acciones ni
endpoints de escritura. No se amplía navegación pública.

La página consulta solo el perfil declarativo versionado
`config/recovery/controlbot.json` (ruta fija local, límite 32 KiB) y reutiliza
`RecoveryCenterUi::project` del #282 **sin** `RecoveryEvidence`, `BackupReceipt`,
`RecoveryDrillProjection` ni Factory Recovery Health. Por diseño muestra
**UNKNOWN**, señales desconocidas, RPO/RTO como **objetivos**, nunca medidas.
Un perfil ausente/roto produce 503 genérico sin eco de datos. La vista no lee
servidores, bases de datos, Hostinger, Google Drive ni B2.

La decisión OWNER **B** en ControlBot **#577** permanece activa: no provisionar
Backblaze B2 (cuenta, bucket, Object Lock, credenciales) ni ejecutar restores.
La entrega de la UI no cumple por sí sola la épica #182 ni habilita Recovery live.
Solo se fusiona con reserva **V3** auténtica, CI y seguridad exact-HEAD; fuera
de alcance: deploy, secretos, datos reales, gasto y go-live. Revertir su único
squash revierte el route y la pantalla sin migraciones.
