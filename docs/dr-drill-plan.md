# Plan OFFLINE del simulacro de recuperación de ControlBot

> **Estado del simulacro: NO ENSAYADO.** Este es un guion de preparación, no una ejecución, copia de seguridad, restauración, certificación de HEALTH ni permiso de go-live. **Resultado actual: UNKNOWN.** Cualquier cifra de este documento es **PROPUESTA** hasta que el propietario y el operador validen la política y midan un ejercicio real.

## Fuentes, alcance y autoridad

- [Lista de salida](lista-salida-produccion.md): fila literal «Recuperación de desastres | RPO/RTO medidos y simulacro de recuperación independiente | DEL_DUEÑO», que permanece **DEL_DUEÑO**. Esta entrega no modifica la lista.
- [AEGIS / Recovery #182](https://github.com/pl0n3r/ControlBot/issues/182): el ensayo real, el almacén externo, la restauración aislada y los recibos de integridad siguen sin certificación; **UNKNOWN**.
- [Contrato de backup D-059](hostinger-d059-backup.md) y [decisiones D-054/D-059](../decisiones.yml): un cambio real exige respaldo auténtico previo y referencia verificable; una descripción de pasos no sustituye el respaldo.
- Alcance **PROPUESTA**: restauración de una copia autorizada de ControlBot en un destino descartable, sin modificar producción, datos de clientes, credenciales, DNS ni tráfico. La cuenta, el operador, el destino, la copia y la fecha están **UNKNOWN**.

## Objetivos RPO/RTO (PROPUESTA)

| Indicador | Objetivo PROPUESTA | Método de medición futuro | Estado actual |
| --- | --- | --- | --- |
| RPO (pérdida máxima tolerable) | 15 minutos, pendiente de aprobación OWNER | Diferencia entre último dato íntegro recuperable y el instante de corte simulado, con marcas UTC y procedencia de la copia | NO ENSAYADO / UNKNOWN |
| RTO (tiempo máximo de recuperación) | 60 minutos, pendiente de aprobación OWNER | Diferencia entre declaración del incidente de prueba y verificación funcional independiente del servicio recuperado, con marcas UTC | NO ENSAYADO / UNKNOWN |

Los objetivos anteriores son **PROPUESTA** y no corresponden a cifras medidas ni a un compromiso contractual aprobado. Si el propietario no acepta estos umbrales, el resultado permanece **UNKNOWN** y debe actualizarse el plan antes de ensayar.

## Roles y responsabilidades

| Rol | Preparación y evidencia requeridas | Asignación |
| --- | --- | --- |
| Propietario (OWNER) | Aprueba el ejercicio, alcance, cuenta, conservación, privacidad y umbrales; autoriza por separado cualquier operación real | UNKNOWN |
| Operador autorizado | Identifica el entorno descartable y el restore point; controla el inicio/parada y registra tiempos UTC sin exponer secretos | UNKNOWN |
| Responsable de QA independiente | Comprueba integridad, acceso y recuperación con pruebas negativas y firma el resultado | UNKNOWN |
| Custodio de seguridad/privacidad | Revisa minimización, permisos, retención y ausencia de datos privados en evidencias públicas | UNKNOWN |

## Condiciones de entrada y parada segura

Antes de cualquier ejercicio real, se requieren **todos** estos elementos: decisión autenticada y específica del OWNER; consentimiento y clasificación de datos; identidad del operador y alcance; destino aislado autorizable; copia genuina identificada por referencia opaca y comprobada; restauración y rollback documentados; controles de privacidad/seguridad; mecanismo de parada y recepción de evidencia versionada. **Ninguno consta validado en este documento**.

Si falta una condición, hay riesgo de sobrescritura, no coincide el SHA/identidad, se detecta un secreto o no se puede recuperar de forma segura, **NO INICIAR / ABORTAR**; dejar todos los resultados como **UNKNOWN**. No ejecutar comandos, conexiones, backups, migraciones o restauraciones desde este guion.

## Procedimiento paso a paso (NO EJECUTADO)

1. **Autoridad:** el OWNER documenta aprobación específica, límites y responsables fuera de datos sensibles; QA comprueba que la autorización corresponde a este ejercicio. Estado: NO ENSAYADO.
2. **Línea base:** el operador registra versiones y SHA verificables de aplicación, esquema y copia, hora UTC y estado del origen sin exportar credenciales. Estado: NO ENSAYADO.
3. **Aislamiento:** el operador demuestra que el destino descartable no puede escribir en producción ni notificar a clientes. Se verifica por una persona distinta antes de operar. Estado: NO ENSAYADO.
4. **Integridad de copia:** validar referencia opaca, alcance, checksum, cifrado y frescura real; toda discrepancia cancela el ejercicio. Estado: NO ENSAYADO.
5. **Cronometraje:** registrar comienzo UTC del incidente simulado, punto recuperable de datos y restauración de prueba en el destino aislado, solo bajo permisos ya emitidos. Estado: NO ENSAYADO.
6. **Validación independiente:** QA verifica integridad de datos mínima, identidad SHA/version, acceso restringido, checks negativos y funcionalidad; no declarar salud solo por respuesta HTTP. Estado: NO ENSAYADO.
7. **Medición y cierre:** calcular RPO y RTO con la fórmula acordada y las pruebas firmadas; comprobar reversión, eliminación autorizada del destino efímero y ausencia de efectos externos. Estado: NO ENSAYADO.
8. **Revisión:** publicar solo referencias opacas y resultados no sensibles; conservar la fila **DEL_DUEÑO** hasta evidencia productiva real, autenticada e independiente. Estado: NO ENSAYADO.

## Hoja de resultados (plantilla vacía; NO ENSAYADO)

| Campo obligatorio | Resultado actual | Quién lo acredita |
| --- | --- | --- |
| Identificador de autorización OWNER | UNKNOWN | OWNER |
| Fecha/hora UTC de inicio y cierre | UNKNOWN | Operador |
| Entorno aislado y verificación de no conexión con producción | UNKNOWN | Operador y QA |
| Referencia opaca, alcance, SHA y checksum de la copia | UNKNOWN | Operador y QA |
| Último dato recuperable y hora del incidente de prueba | UNKNOWN | Operador |
| Hora UTC de recuperación funcional verificada | UNKNOWN | QA |
| RPO medido (minutos) | UNKNOWN | QA |
| RTO medido (minutos) | UNKNOWN | QA |
| Integridad, permisos, seguridad y rollback comprobados | UNKNOWN | QA y seguridad |
| Desviaciones, incidentes y firma de revisión independiente | UNKNOWN | QA |
| Veredicto final | NO ENSAYADO | OWNER y QA |

## Criterios de aprobado (PROPUESTA; NO APLICADOS)

Solo un simulacro **real autorizado** podría resultar APROBADO si concurren recibo auténtico del respaldo y restauración en aislamiento, integridad/seguridad verificadas independientemente, SHA/version correctos, RPO medido dentro del objetivo aprobado, RTO medido dentro del objetivo aprobado, reversión probada y hojas de resultados firmadas. Una prueba sintética, la existencia de este plan, CI verde o un PR fusionado **no** cumplen esos criterios.

Cualquier medida **UNKNOWN**, aprobación ausente, discrepancia de SHA, datos sin permiso o evidencia incompleta implica resultado **NO VERIFICABLE**, nunca APROBADO. Este documento no emite VALIDATED_IN_PRODUCTION ni altera la puerta de recuperación [#182](https://github.com/pl0n3r/ControlBot/issues/182) o el go-live.

## Reversión de esta entrega documental

El único efecto de esta hoja es añadir documentación y pruebas offline. Revertir su futuro commit mediante un PR de reversión retira ambos archivos; **no** revierte ni restaura sistemas, porque no se ha ejecutado operación alguna.
