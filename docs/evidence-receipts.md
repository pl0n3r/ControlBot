# ControlBot · Recibos opacos de evidencia por SHA

**Estado de este documento: PROPUESTA. Ejecución real: NO ENSAYADO.**
**Fuente:** [ControlBot #963](https://github.com/pl0n3r/ControlBot/issues/963) y [lista de salida a producción, fila de atestación D-043](https://github.com/pl0n3r/ControlBot/blob/40cb9c72018e740053e0097a0f69613817d4181e/docs/lista-salida-produccion.md#L44).

## Propósito y límites

Esta es una **plantilla offline**, no un recibo de operación. Un recibo de un operador autorizado debe identificar qué se verificó sobre un SHA concreto, cuándo y con qué evidencia rastreable, sin copiar secretos, información personal, cookies, logs sensibles ni credenciales. Los recibos no aprueban por sí mismos el despliegue, la privacidad, los backups ni el paso a live.

La fila «Atestación D-043 VALIDATED_IN_PRODUCTION sin pendientes» de [docs/lista-salida-produccion.md](./lista-salida-produccion.md) conserva el estado **DEL_DUEÑO**. Su prueba real y el resultado con pendientes vacíos son **UNKNOWN** hasta obtener una observación autorizada. Esta plantilla no cambia la fila ni permite marcarla como terminada.

## Campos mínimos del recibo

| Campo | Formato | Regla |
| --- | --- | --- |
| requisito | Identificador de fila, por ejemplo D-043-ATESTACION | Debe enlazarse a su fuente documental |
| sha | 40 dígitos hexadecimales del commit observado | Nunca se deduce del nombre de una versión |
| hora_bogota | ISO 8601 con desplazamiento -05:00 | Hora observada en zona America/Bogota; no inventarla |
| operador | Alias opaco de quien hizo la verificación | Sin nombre, correo, teléfono ni identificadores personales |
| resultado | UNKNOWN, NO ENSAYADO o estado real acreditado | No declarar VALIDATED_IN_PRODUCTION sin recibo auténtico y revisión |
| enlace | URL canónica de la evidencia con acceso controlado | Apuntar al hecho, no exponer contenido sensible |
| clasificacion | PROPUESTA o evidencia verificada explícitamente | Distinguir ejemplo de hecho |

El operador debe comprobar correspondencia entre SHA, versión desplegada, fuente, hora y resultado; si no puede, consignar **UNKNOWN**. Una URL aislada o un check de CI verde no acreditan observación en producción. El sistema de origen sigue determinando autorización, acceso, conservación y eliminación; no se fija aquí un período de retención ficticio.

## Plantilla para el operador autorizado

Se completa **fuera del repositorio público** cuando exista fuente real y permiso por operación. La bitácora pública solo puede contener metadatos no sensibles, y los enlaces deben respetar los permisos del destino.

| Campo | Valor por completar |
| --- | --- |
| requisito | D-043-ATESTACION |
| sha | UNKNOWN |
| hora_bogota | UNKNOWN |
| operador | UNKNOWN |
| resultado | NO ENSAYADO |
| enlace | UNKNOWN |
| clasificacion | PROPUESTA |

## Ejemplo ficticio (NO ENSAYADO)

El SHA formado solo por ceros **no identifica un despliegue**. La fecha, el alias y el enlace siguientes son ejemplos sintéticos. No reutilizar este bloque como atestación válida.

    requisito: D-043-ATESTACION
    sha: 0000000000000000000000000000000000000000
    hora_bogota: 2026-01-01T00:00:00-05:00
    operador: OPERADOR_FICTICIO
    resultado: NO ENSAYADO
    enlace: https://github.com/pl0n3r/ControlBot/issues/963
    clasificacion: PROPUESTA

## Validación y trazabilidad

1. Consultar la [fila original de atestación D-043](https://github.com/pl0n3r/ControlBot/blob/40cb9c72018e740053e0097a0f69613817d4181e/docs/lista-salida-produccion.md#L44), que permanece **DEL_DUEÑO**, y el [issue #625](https://github.com/pl0n3r/ControlBot/issues/625) como referencia del permiso de operador.
2. Confirmar con el operador autorizado la identidad opaca, la hora de Bogotá y el commit real. Toda evidencia ausente queda **UNKNOWN** o **NO ENSAYADO**, nunca se sustituye por una prueba sintética.
3. Verificar un enlace de evidencia genuina accesible solo a sus destinatarios; redactar o excluir información personal, secretos y payloads antes de registrar cualquier metadato.
4. Reconciliar la identidad exact-SHA y pending=[] únicamente mediante el gate auténtico D-043 y la autoridad vigente. Si no hay prueba real, mantener **DEL_DUEÑO**. No cambiar configuración, hosting, DNS ni go-live con esta plantilla.

**Prueba offline:** tests/test_recibos_evidencia_doc.py, clase DocContractTests. Valida formato, fuente de la fila, ejemplo ficticio y barreras ante datos sensibles. No ejecuta peticiones de red ni modifica la lista de salida.
