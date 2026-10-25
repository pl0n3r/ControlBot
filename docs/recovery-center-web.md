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

La decisión OWNER **B** de ControlBot **#577** es histórica para la preparación:
la opción **A** autenticada en **#858** autoriza preparación B2 limitada y read-only
por canal privado, pero **no autoriza provisión** de cuenta/bucket, Object Lock,
credenciales en GitHub/chat, gasto, backups reales, restores ni go-live.
La entrega de la UI no cumple por sí sola la épica #182 ni habilita Recovery live.
Solo se fusiona con reserva **V3** auténtica, CI y seguridad exact-HEAD; fuera
de alcance: deploy, secretos, datos reales, gasto y go-live. Revertir su único
squash revierte el route y la pantalla sin migraciones.


## Portfolio Recovery de siete proyectos — preparación offline (#896)

La misma ruta **owner-only** y GET incorpora una tabla escapada y sin acciones
para `autofactory`, `brvtal`, `condor`, `controlbot`, `factory`,
`factoryrunner` y `grindflow`. La allowlist es fija, no viene de URI/query.
Lee solo seis perfiles locales JSON versionados bajo `config/recovery/`;
`FactoryRunner` no tiene perfil/targets fuente verificable y queda `UNKNOWN`
con objetivos **No declarado**, sin inventarlos. Si un JSON es inválido,
sensible o pertenece a otro proyecto, se responde 503 genérico, sin eco.
La proyección pura `RecoveryPortfolio` impide entradas externas y solo muestra
RPO/RTO como **objetivos**, nunca medidas. El estado operativo de los siete
proyectos siempre es `UNKNOWN` sin Factory Recovery Health ni BackupReceipt:
**No confirma backups**, cifrado/offsite, retención real, restore ni RPO/RTO
observados. El estado `configured` de un perfil solo demuestra política local
legible. No hay acceso HTTP, proveedor, escritura, cron, dispatch ni live.
La opción A de #858 tampoco concede estas acciones ni cierra el épico #182.
