# Backup D-059 para la raíz Hostinger de ControlBot

## Propósito

Este contrato define la evidencia operativa exigida por D-059 antes de fusionar ControlBot PR #630. Preparar o fusionar este documento **no crea un backup**, no satisface D-059 por sí solo y no autoriza deploy, DNS, go-live ni cambios en Hostinger.

PR #630 permanece en draft hasta que ControlBot #627 contenga evidencia verificable de un backup real que cumpla este contrato.

## Alcance del backup

El backup previo debe representar el estado de la publicación de ControlBot en Hostinger inmediatamente antes del merge de PR #630:

- recurso: `public_html` asociado al sitio de ControlBot;
- contenido: estado actualmente publicado/restaurable de esa raíz;
- momento: creado antes del merge que pueda auto-desplegar desde `main`;
- base de datos: **no aplica en este gate** mientras ControlBot no use una DB live para esta publicación;
- secretos/credenciales: nunca se copian al Issue, al repo ni al chat.

El backup puede crearlo el dueño mediante hPanel o una sesión futura con acceso Hostinger/hPanel autenticado y autoridad suficiente. La mera capacidad de lectura no autoriza crear, restaurar ni borrar backups.

## Evidencia obligatoria en #627

D-059 se considera satisfecho únicamente cuando #627 registra, sin secretos:

1. **Fecha/hora UTC** de creación del backup.
2. **Alcance**: `public_html` de ControlBot.
3. **Referencia opaca** al backup o restore point, suficiente para identificarlo sin publicar URLs firmadas, tokens, cookies ni credenciales.
4. **Mecanismo de creación**: hPanel u otro mecanismo autorizado.
5. **Verificación**: evidencia de que el restore point existe y corresponde al alcance declarado.
6. **Restauración**: pasos concretos para seleccionar ese restore point y recuperar la raíz si fuese necesario.

No son evidencia suficiente: capturas sin identificador verificable, texto “backup hecho” sin referencia, una copia local no restaurable, documentación del procedimiento o un workflow verde que no creó el backup.

## Verificación del gate

Antes de sacar PR #630 de draft, una sesión distinta debe releer #627 y confirmar que los seis campos anteriores existen y son coherentes. Ausencia, ambigüedad o staleness mantienen el gate cerrado.

La verificación del backup no reemplaza los demás requisitos de #627: segunda pasada read-only de riesgo alto, HEAD exacto, gates terminales y ausencia de hallazgos bloqueantes.

## Rollback de PR #630

El rollback normal es no destructivo y Git-first:

1. identificar el merge commit exacto de PR #630;
2. crear un **revert explícito** de ese merge en GitHub, sin reescribir `main`;
3. esperar el mecanismo normal de publicación asociado a `main`;
4. verificar que el sitio vuelve al comportamiento esperado y que rutas internas continúan deny-by-default, respondiendo 403/404 según corresponda;
5. si el despliegue/revert no restaura el estado esperado, usar el restore point D-059 registrado en #627 y repetir la verificación.

No se borran sitios, archivos, bases, DNS ni planes por inferencia. Cualquier acción destructiva sigue requiriendo autorización explícita del dueño.

## Estado y límites

- **Contrato preparado**: este documento y sus pruebas están integrados.
- **Backup real existente**: evidencia verificable registrada en #627.
- **D-059 satisfecho**: solo el segundo estado permite evaluar sacar #630 de draft; el primero nunca lo implica.

Este contrato no cambia `DOMAIN`, `DEPLOY_ENABLED`, DNS, cron, DB, secretos, credenciales, planes, pagos o fase de producción.
