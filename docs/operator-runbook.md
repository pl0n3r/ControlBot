# ControlBot · Runbook del operador

**Estado: NO ENSAYADO · NO-GO · fase construcción.** Es una guía de preparación offline, no un servicio desplegado. **UNKNOWN** significa evidencia externa ausente; **PROPUESTA** significa procedimiento pendiente de validación. CI verde, fixtures y consultas a GitHub no son VALIDATED_IN_PRODUCTION. Fuente: [lista de salida](lista-salida-produccion.md), [plan Hostinger](hostinger-go-live-plan.md), [#625](https://github.com/pl0n3r/ControlBot/issues/625).

**Fila vinculada:** [«Runbook operador de arranque, fallos y mantenimiento»](lista-salida-produccion.md), área «Soporte y operación diaria», estado **DEL_DUEÑO**. La preparación no altera la fila; operador, permiso por operación y ensayo real todavía faltan. Fuente: [lista de salida](lista-salida-produccion.md).

## Autoridad y precondiciones (NO ENSAYADO)

1. **PROPUESTA:** obtener autorización concreta del OWNER, identidad del operador Hostinger, alcance y revocación antes de cualquier acción externa; registrar referencia opaca, fecha UTC y SHA en el canal autorizado, nunca contraseñas ni información personal. Fuente: [#625](https://github.com/pl0n3r/ControlBot/issues/625).
2. **UNKNOWN:** backup real previo a cambios, restore aislado, rollback probado, privacidad validada, autenticación y HTTPS owner-only. Si falta cualquiera, conservar NO-GO. Los preflights sintéticos no acreditan backups. Fuente: [D-059](hostinger-d059-backup.md), [Recovery](controlbot-recovery-rollout.md).
3. **PROPUESTA:** mantener fase construccion y los flags de deploy sin activar. No ejecutar despliegues, migraciones, cron ni DNS desde este documento. Fuente: [deploy.yml](../.github/workflows/deploy.yml), [lista de salida](lista-salida-produccion.md).

## Arranque (NO ENSAYADO)

**Paso 1 — preparación local, sin conexión ni secretos.** Ejecutar desde la raíz del checkout; fuente: [plan Hostinger](hostinger-go-live-plan.md).

~~~sh
python3 -m unittest discover -s tests -p 'test_hostinger_go_live_plan.py' -v
~~~

**Salida esperada offline:** resumen de tests y OK si pasan; cualquier fallo es de la preparación, no de producción. Fuente: tests/test_hostinger_go_live_plan.py.

**Paso 2 — evaluación con SHA y datos sintéticos únicamente.** Fuente: [plan Hostinger](hostinger-go-live-plan.md).

~~~sh
printf '%s\n' '{"version":1,"target":"control.condorapp.com.co","sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","phase":"construccion","evidence":[]}' | python3 scripts/hostinger_go_live_plan.py
~~~

**Salida esperada offline:** JSON con decisión NO_GO y ocho gates faltantes. La salida no concede permisos, no arranca servicios y no es un recibo. Fuente: scripts/hostinger_go_live_plan.py, [plan Hostinger](hostinger-go-live-plan.md).

**Paso 3 — arranque real:** **NO ENSAYADO / UNKNOWN**. Reservado al operador con autoridad para la operación, backup y restore verificados, rollback, SHA exacto, privacidad y transporte privado. No hay secuencia de comandos live autorizada en este documento; falta evidencia D-043 de VALIDATED_IN_PRODUCTION sin pendientes. Fuente: [#625](https://github.com/pl0n3r/ControlBot/issues/625), [deploy.yml](../.github/workflows/deploy.yml).

## Parada y reversión (NO ENSAYADO)

**Parada de cambios — PROPUESTA:** cuando fallen gates o la identidad sea dudosa, no repetir despliegue, no ejecutar migraciones, no tocar secretos o hosting. Consultar exclusivamente metadatos de ejecuciones GitHub sin mutaciones (fuente: [deploy.yml](../.github/workflows/deploy.yml)):

~~~sh
gh run list --workflow deploy.yml --limit 3 --json databaseId,status,conclusion,headSha
~~~

**Salida esperada:** arreglo JSON (posiblemente vacío) de metadata de jobs. **UNKNOWN** hasta consultarlo: un success no acredita el estado productivo. El operador requiere su propia autenticación GitHub; el comando no muestra secretos. Fuente: .github/workflows/deploy.yml.

**Parada/apagado o rollback real:** **NO ENSAYADO / UNKNOWN**. Requieren operador, ventana y permiso específicos, backup recuperable y reversión probada. ops/factory/rollback --offline-dry-run solo produce SYNTHETIC_ONLY: no restaura, no detiene procesos ni acredita D-059. No borrar archivos, tocar DNS, reescribir main o restaurar datos antiguos por inferencia. Fuente: [rollback offline](ops/factory-rollback-preflight.md), [D-059](hostinger-d059-backup.md).

## Lectura de health (NO ENSAYADO)

**Contrato de código, no resultado operativo:** public/health.php llama a HealthIdentityHttpEndpoint. Exige HTTPS, Basic Auth realmente autenticado en servidor, propietario configurado y manifiesto privado firmado con SHA/versión coincidentes. Sin autenticación devuelve 403; si no puede acreditar manifiesto, 503; solo con identidad válida devuelve 200 y JSON con status, version, release_sha. La instalación sigue **UNKNOWN**. Fuente: [health identity](health-identity-endpoint.md), public/health.php.

~~~sh
python3 -m unittest discover -s tests -p 'test_health_http_identity_endpoint.py' -v
~~~

**Salida esperada offline:** resumen de tests y OK si pasan. Esto no ejecuta GET real, ni certifica autenticación externa, ni autoriza compartir firmantes o claves. **PROPUESTA:** el operador legítimo compara recibo de deploy y health owner-only bajo canal privado; hasta VALIDATED_IN_PRODUCTION y pending=[] el estado es NO-GO. Fuente: [health identity](health-identity-endpoint.md), [#625](https://github.com/pl0n3r/ControlBot/issues/625).

## Fallos conocidos y respuesta (NO ENSAYADO)

| Síntoma | Respuesta propuesta / estado | Fuente |
| --- | --- | --- |
| Deploy u observación skipped | No hubo medición. Revisar gates de DOMAIN y DEPLOY_ENABLED sin modificarlos; NO-GO. | [deploy](../.github/workflows/deploy.yml), [observador](../.github/workflows/observar.yml) |
| Health 403 | Rechazo correcto si no está autenticado el propietario. No debilitar Basic Auth. Estado real UNKNOWN. | [health](health-identity-endpoint.md) |
| Health 503 o SHA distinto | Falta manifiesto privado válido o identidad del artefacto; detener cambios y contrastar recibo mediante operador. | [health](health-identity-endpoint.md) |
| 5xx o HTTPS inaccesible | No afirmar GREEN, no iniciar deploy repetido; escalar a operador autorizado. | [#625](https://github.com/pl0n3r/ControlBot/issues/625) |
| signal_budget_exceeded, más de 400 señales | Conservar stale y conteos omitidos; el test offline no satisface #760 AC-04 collector→cron→snapshot→UI real. | [#760](https://github.com/pl0n3r/ControlBot/issues/760), [cron](runbooks/orchestrator-snapshot-cron.md) |
| token_file_invalid, rate_limited, snapshot_target_invalid | Fallo cerrado: conservar snapshot previo si procede; no revelar tokens, paths del host ni respuestas crudas. | [cron](runbooks/orchestrator-snapshot-cron.md) |
| Backup o rollback UNKNOWN | NO-GO; solicitar al operador restore aislado autenticado y rollback seguro, no fingir BACKUP_VERIFIED. | [Recovery](controlbot-recovery-rollout.md), [D-059](hostinger-d059-backup.md) |
| Validar rojo | Corregir la rama bajo V3 y verificar checks exact-HEAD; el CI no sustituye producción. | [lista de salida](lista-salida-produccion.md) |

**Salida esperada ante puertas incumplidas:** NO-GO / UNKNOWN, nunca remediación automática de hosting. Fuente: [lista de salida](lista-salida-produccion.md).

## Mantenimiento semanal (PROPUESTA · NO ENSAYADO)

1. **Consultar** observador sin iniciar jobs; fuente: [observador](../.github/workflows/observar.yml).
   ~~~sh
   gh run list --workflow observar.yml --limit 3 --json databaseId,status,conclusion,headSha
   ~~~
   **Salida esperada:** arreglo JSON de ejecuciones, posiblemente skipped. No mide por sí mismo el servidor y no muestra credenciales.
2. **Comprobar contratos offline**, no la instalación (fuente: tests/test_runbook_operador_doc.py y tests/test_lista_salida_produccion.py):
   ~~~sh
   python3 -m unittest discover -s tests -p 'test_runbook_operador_doc.py' -v
   python3 -m unittest discover -s tests -p 'test_lista_salida_produccion.py' -v
   ~~~
   **Salida esperada:** OK de cada suite si pasa; fallo requiere corrección en PR, no habilita go-live.
3. **PROPUESTA:** con el operador autorizado revisar alertas, identidad del release SHA, frescura de collector/snapshot, expiración de accesos, backup restaurable y contacto de soporte. Sin recibos, todo **UNKNOWN**; no inventar guardias ni ventanas. Fuente: [lista de salida](lista-salida-produccion.md), [Recovery](controlbot-recovery-rollout.md), [cron](runbooks/orchestrator-snapshot-cron.md).
4. **PROPUESTA:** registrar fecha UTC, SHA y referencias opacas de evidencias autorizadas; sin secretos ni datos de usuarios. **No cambiar la fila DEL_DUEÑO** por documentación. Fuente: [lista de salida](lista-salida-produccion.md).

**Cierre:** runbook comprobable offline; **NO ENSAYADO** por operador externo. La salida a live conserva **NO-GO**. Fuente: [#959](https://github.com/pl0n3r/ControlBot/issues/959), [#625](https://github.com/pl0n3r/ControlBot/issues/625).


## Ensayo local reproducible (#1361 · 2026-10-30)

**Ámbito:** ensayo del procedimiento de control en un entorno Python aislado, con
SHA ficticio `aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa` y `evidence=[]`.
No se usaron credenciales, usuarios, chats, base de datos, Hostinger, red productiva,
variables de deploy ni contenido de clientes. **OK significa solo que se observó el
comportamiento offline esperado; nunca salud o autorización de producción.**
Un resultado de error controlado (exit 1 + `NO_GO`) se registra OK cuando ese es
el resultado esperado. Ejecutar siempre desde la raíz del checkout.

### 1. Preflight y arranque en seco

~~~sh
python3 -m py_compile scripts/hostinger_go_live_plan.py
printf '%s\n' '{"version":1,"target":"control.condorapp.com.co","sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","phase":"construccion","evidence":[]}' | python3 scripts/hostinger_go_live_plan.py
~~~

Esperado: compilación sintáctica sin salida; el simulador finaliza con **código 1**
y JSON `decision=NO_GO`, `missing_gates` con exactamente ocho elementos y
`runtime_authorized=false`, `deploy_authorized=false`, `go_live_authorized=false`.
Esto prueba la **negativa a arrancar** sin evidencias, no inicia un servidor.

### 2. Comprobar salud sin confundir contrato y red

~~~sh
python3 -m unittest discover -s tests -p 'test_health_http_identity_endpoint.py' -v
~~~

Esperado: `OK` en un checkout con PHP del CI; ejercita los estados 200/403/503
del endpoint con fixtures locales. No consultar el endpoint productivo ni publicar
cabeceras de autenticación. En ausencia de PHP 8.5 o de checkout completo, registrar
`FALLA` para ese entorno y usar el CI exact-HEAD como evidencia de contrato.

### 3. Fallos típicos y respuesta segura

~~~sh
printf '%s\n' '{}' | python3 scripts/hostinger_go_live_plan.py
~~~

Esperado: código de salida 1, JSON `NO_GO` con `reason=invalid_input`.
No reintentar deploy ni habilitar `DEPLOY_ENABLED`; revisar el gate ausente
mediante los canales del dueño. Ante health 403, no debilitar Basic Auth; 503 o
SHA distinto exige detener cambios y contrastar identidad de artefacto.
El runbook anterior detalla los otros síntomas sin asumir un servidor vivo.

### 4. Mantenimiento rutinario

~~~sh
python3 -m unittest discover -s tests -p 'test_operator_runbook.py' -v
python3 -m unittest discover -s tests -p 'test_runbook_operador_doc.py' -v
~~~

Esperado: `OK` para pruebas puramente documentales y simulador local con árbol
completo. Registrar anomalías en el Issue/PR, mantener acceso owner-only, examinar
la frescura de smoke/backup **solo con evidencia autorizada**, y no ejecutar cron,
restauración ni cambios de hosting. Consultas `gh run list` de las secciones
anteriores requieren autenticación del operador y no forman parte del ensayo aislado.

### Registro OK/FALLA del ensayo reproducible

| Paso | Resultado local | Evidencia / límite |
| --- | --- | --- |
| preflight | OK | `py_compile` sin errores en copia de trabajo local del preflight; sin checkout completo |
| arranque | OK | fixture vacío → `NO_GO`, ocho gates faltantes, código de salida 1; no levanta servicios |
| salud | FALLA | PHP local 8.4, distinto al PHP 8.5 declarado: suite PHP de health no acreditada en este entorno; CI del PR debe validarla |
| fallos | OK | entrada JSON inválida → negativa `NO_GO`, sin ejecución remota |
| mantenimiento | FALLA | tests de documentación integrales requieren checkout completo; se exige CI exact-HEAD antes de fusionar |

**Repetición en checkout completo:** instalar las dependencias del repositorio sin
credenciales, ejecutar los bloques anteriores (no los comandos `gh`), anotar
exit code/salida resumida y sustituir cada `FALLA` solamente con evidencia
real de la ejecución. La prueba de aceptación
`docs/operator-runbook.md::Operator-runbookTests::test_criterio_principal`
está implementada en `tests/test_operator_runbook.py`; evita que se rompan
comandos referenciados o se agreguen secretos y valida el preflight ejecutable.

**Límite permanente:** ni el registro de laboratorio ni el CI autorizan
`VALIDATED_IN_PRODUCTION`; despliegue, owner, D-059, backup, restore y live
permanecen **UNKNOWN / NO-GO** hasta pruebas externas con autoridad vigente.
