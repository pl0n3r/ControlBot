# ControlBot · preparación del apagado de emergencia y revocación de accesos

**Estado: NO ENSAYADO · PROPUESTA OFFLINE · NO-GO.** Este documento no es una orden de ejecución, prueba productiva ni autorización de operación. El operador y el OWNER deben validar cada paso en el hosting real antes de cambiar el estado. UNKNOWN indica falta de evidencia, no que el servicio esté detenido.

## Alcance, autoridad y fuentes

- **Alcance:** planificar contención, apagado del runtime, revocación de accesos y verificación sin ejecutar acciones reales. Fuente: [ControlBot #962](https://github.com/pl0n3r/ControlBot/issues/962).
- **Separación crítica:** [Factory #767](https://github.com/pl0n3r/Factory/issues/767) regula el despacho de agentes; una pausa **no** apaga PHP/cron, sesiones, hosting, DNS ni tokens de ControlBot. Estado del servicio desplegado: **UNKNOWN / NO ENSAYADO**. Fuente: [lista de salida](lista-salida-produccion.md), apartado «Reversión y apagado».
- **Autoridad:** un operador con acceso legítimo a Hostinger y el OWNER validan el procedimiento, el alcance y cada permiso por separado. No se obtienen ni muestran credenciales. Fuente: [#625](https://github.com/pl0n3r/ControlBot/issues/625) y [#750](https://github.com/pl0n3r/ControlBot/issues/750).
- **Límite del software existente:** CapabilityGrantRevoker solo revoca grants sintéticos en memoria dentro del mismo proceso; **no** revoca credenciales ni sesiones reales. Fuente: [capability-grant-revocation.md](capability-grant-revocation.md).
- **Limitación de validación:** la observación real de más de 400 señales y el recibo D-043 VALIDATED_IN_PRODUCTION siguen pendientes; CI no demuestra interrupción ni recuperación operativa. Fuente: [#760](https://github.com/pl0n3r/ControlBot/issues/760) y [#625](https://github.com/pl0n3r/ControlBot/issues/625).

## Condiciones previas y disparadores — PROPUESTA

1. **Confirmación humana:** definir incidente, motivo, autoridad solicitante, operador autorizado y riesgos para terceros. Estado: **NO ENSAYADO**. Fuente: [#625](https://github.com/pl0n3r/ControlBot/issues/625).
2. **Identidad de despliegue:** verificar versión/SHA, dominio y estado realmente desplegados por observación autenticada. Sin evidencia exacta, anotar UNKNOWN; main no equivale a producción. Estado: **UNKNOWN**. Fuente: [#750](https://github.com/pl0n3r/ControlBot/issues/750).
3. **Recuperación y límites:** exigir backup y restore verificables, rollback ensayado, autorización puntual y privacidad antes de cambios irreversibles. Sin evidencia, escalar al OWNER y no ejecutar destrucción, migraciones ni borrados. Estado: **NO ENSAYADO**. Fuente: [#625](https://github.com/pl0n3r/ControlBot/issues/625).
4. **Comunicación:** preparar aviso al OWNER y bitácora mínima sin credenciales, payloads, PII ni datos de clientes. Estado: **PROPUESTA**. Fuente: [#962](https://github.com/pl0n3r/ControlBot/issues/962).

## Secuencia propuesta para detener el servicio — NO ENSAYADO

1. **Autorizar contención:** OWNER y operador registran alcance y ventana de actuación. Detener nuevas operaciones solo mediante controles reales y autorizados; nunca asumir botones o mecanismos no identificados. Evidencia: UNKNOWN hasta ejecución.
2. **Detener entrada y automatizaciones:** operador identifica mecanismo concreto para suspender tráfico privado y cron/jobs, verificando tareas iniciadas. No cambiar DNS ni hosting por inferencia. Mecanismo/comando exacto: **UNKNOWN**; necesita runbook Hostinger y permiso específico.
3. **Preservar evidencia:** preparar auditoría saneada y rollback; no imprimir variables de entorno, tokens ni datos personales. Método real de preservación y backup: **NO ENSAYADO**.
4. **Separar control de agentes:** solicitar explícitamente al OWNER cualquier cambio de Factory #767; la eventual pausa de agentes **no sustituye** el apagado del servicio.

Fuentes de esta secuencia: [#625](https://github.com/pl0n3r/ControlBot/issues/625), [#760](https://github.com/pl0n3r/ControlBot/issues/760) y [lista de salida](lista-salida-produccion.md). Ningún paso se ha probado sobre el servicio desplegado.

## Revocación de tokens, grants y sesiones — NO ENSAYADO

1. **Inventariar categorías, nunca valores:** sesiones owner-only, autorizaciones del panel, tokens de GitHub/Sentry y grants o perfiles emparejados. Recuento y estado de origen: **UNKNOWN**. Fuente: [AGENTES.md](../AGENTES.md) y [#625](https://github.com/pl0n3r/ControlBot/issues/625).
2. **Revocar en origen solo con permiso puntual:** OWNER/operador seleccionan controles administrativos oficiales, invalidan sesiones y verifican propagación. No publicar, copiar, rotar ni borrar secretos reales desde esta preparación. Revocación entre procesos: **UNKNOWN / NO ENSAYADO**. Fuente: [capability-grant-revocation.md](capability-grant-revocation.md) y [#45](https://github.com/pl0n3r/ControlBot/issues/45).
3. **Registrar prueba saneada:** tipo de permiso, alias del operador autorizado, instante UTC, resultado observado y referencia privada al recibo. Nunca registrar tokens, cookies, contraseñas, TOTP, cabeceras, emails ni datos personales. Fuente: [#962](https://github.com/pl0n3r/ControlBot/issues/962).
4. **Comprobar rechazo:** usar identidades de prueba autorizadas para confirmar que las sesiones previas fallan y no se elevan privilegios. Sin respuesta productiva terminal el resultado sigue **UNKNOWN**, nunca REVOCADO por tests offline. Fuente: [#750](https://github.com/pl0n3r/ControlBot/issues/750).

## Verificación de apagado y criterios de rechazo — NO ENSAYADO

- **Servicio:** observar independientemente que el endpoint privado dejó de atender operaciones y muestra el estado previsto; sin identidad y respuesta verificables, estado UNKNOWN.
- **Trabajos:** verificar en el scheduler real que cron y tareas de fondo cesaron; un run skipped o check de CI no prueba esto. Estado UNKNOWN.
- **Autenticación:** ensayo autorizado del rechazo de sesiones/grants anteriores sin registrar payloads, PII ni secretos. Estado NO ENSAYADO.
- **Auditoría:** conciliar trazas saneadas y timestamps UTC con la versión real; cualquier operación después del corte invalida el recibo y obliga a escalar. Estado NO ENSAYADO.
- **Restablecimiento:** no reactivar sin autoridad puntual, backup/restore, rollback, smoke D-043 y puertas legales para el artefacto exacto. Estado NO ENSAYADO.

Fuentes: [#625](https://github.com/pl0n3r/ControlBot/issues/625), [#750](https://github.com/pl0n3r/ControlBot/issues/750), [#760](https://github.com/pl0n3r/ControlBot/issues/760).

## Tiempos objetivo — PROPUESTA, NO ENSAYADO

| Hito desde incidente | Objetivo tentativo | Evidencia requerida | Estado |
| --- | --- | --- | --- |
| Clasificación y autorización | T+5 min | decisión y operador autorizados | NO ENSAYADO |
| Contención de entrada | T+15 min | prueba del control real en hosting | NO ENSAYADO |
| Revocación y sesiones | T+30 min | recibos de origen sin valores sensibles | NO ENSAYADO |
| Verificación y comunicación | T+45 min | runtime, jobs y accesos comprobados | NO ENSAYADO |

Los tiempos son objetivos **PROPUESTA** para revisar con el OWNER: no son SLO aprobados, no son mediciones reales y no sustituyen el procedimiento del proveedor. Fuente: [#962](https://github.com/pl0n3r/ControlBot/issues/962).

## Plantilla de recibo saneado — NO ENSAYADO

Estado del recibo: NO ENSAYADO
Referencia de incidente: UNKNOWN
Operador autorizado (alias sin PII): UNKNOWN
Aprobación específica del OWNER: UNKNOWN
Release/version/SHA observada: UNKNOWN
Inicio y fin UTC medidos: UNKNOWN
Contención del servicio y cron: UNKNOWN
Revocación de accesos/sesiones: UNKNOWN
Backup y rollback verificados: UNKNOWN
Verificación negativa independiente: UNKNOWN
Referencia privada a evidencia saneada: UNKNOWN
Desviaciones y siguientes acciones: UNKNOWN
Conclusión: NO ACREDITADO / NO ENSAYADO

La plantilla no contiene valores reales. Un UNKNOWN no se convierte en éxito sin recibos auténticos del operador y evidencia productiva terminal. Fuente: [#962](https://github.com/pl0n3r/ControlBot/issues/962) y [#625](https://github.com/pl0n3r/ControlBot/issues/625).

## Enlace a la lista de salida y deuda pendiente

Fila canónica: **«Apagado de emergencia del runtime y revocación de accesos»**, área **Reversión y apagado**, estado **DEL_DUEÑO** en [docs/lista-salida-produccion.md](lista-salida-produccion.md). Esta es solo una preparación offline; **no cambia esa fila ni acredita LISTO**. Sin ensayo real del OWNER/operador, la salida sigue **NO-GO**. Fuentes: [lista de salida](lista-salida-produccion.md) y [#625](https://github.com/pl0n3r/ControlBot/issues/625).
