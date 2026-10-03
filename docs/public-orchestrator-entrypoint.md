# Entrada pública del Orquestador en Hostinger

ControlBot sigue en **construcción**. Este tramo prepara la entrada web owner-only del Orquestador; no activa deploy, dominio ni go-live.

Para este sitio y esta fase, la decisión del dueño registrada en ControlBot#634 acepta **Git como respaldo** para D-059. No se exige un backup de Hostinger/hPanel antes de #630/#656 mientras la publicación siga compuesta únicamente por contenido versionado del repositorio. Esta excepción no se extiende a DB, DNS, cron, dominios, planes, otros productos ni datos fuera de Git.

## Límite y flujo

El flujo previsto después de integrar el hardening de raíz es:

`public_html/index.php` → `public/index.php` → `FactoryOrchestratorWebEntrypoint` → `FactoryOrchestratorLiveEndpoint`.

La autenticación ocurre antes del runtime de ControlBot, en la protección de directorio del servidor. El entrypoint recibe esa identidad únicamente mediante `REMOTE_USER` y la compara con `CONTROLBOT_OWNER_LOGIN`. Si falta identidad, configuración o la identidad no coincide, la respuesta falla cerrada y no carga el snapshot.

El request solo admite la superficie read-only existente:
- `GET /` para HTML;
- `GET /api/orchestrator-live` para JSON.

El navegador nunca consulta GitHub, no recibe tokens y no escribe estado.

## Fuente local y freshness

La fuente canónica del request es el archivo local:

`var/orchestrator-live.json`

Debe contener un `FactoryLiveSnapshot` canónico producido **fuera del request web** por un recolector/workflow gobernado y desplegado como artefacto local. Los tests no instalan fixtures en esa ruta y el runtime no fabrica datos de producción.

Contrato de lectura:
- máximo 2 MB;
- JSON canónico con fingerprint válido;
- `observed_at` no puede estar en el futuro;
- frescura máxima: **300 segundos**;
- archivo ausente, inválido o con más de 300 segundos produce una proyección explícita `UNKNOWN`, no datos anteriores inventados;
- la caché de respuesta es local y atómica, en el directorio temporal del servidor.

El hardening del PR #630 bloquea archivos `.json` y rutas internas desde HTTP. Por eso el orden de merge es obligatorio: **#630 → este PR**. El snapshot nunca se expone como asset público.

## D-059 · respaldo Git-first

Antes de fusionar el frente que auto-despliega, #627 debe registrar evidencia verificable y no sensible de:

1. **SHA exacto de main** inmediatamente antes del merge.
2. **SHA del último despliegue sano observado** para ControlBot.
3. Plan de rollback **Git-first**: revert explícito del merge, sin reescribir `main`.
4. Verificación posterior del rollback: `/src/` y `/config/` continúan deny-by-default con 403/404 y la raíz no expone fuentes internas.

Ese registro satisface D-059 para ControlBot en construcción bajo la decisión vigente del dueño. No se debe fabricar un “backup real” de hPanel ni tratar plantillas incompletas como evidencia.

## Preparación en hPanel

Estas acciones pertenecen a la preparación operativa posterior y no sustituyen el respaldo Git-first.

1. En hPanel, abrir el sitio `control.condorapp.com.co`.
2. Activar la protección con contraseña para el directorio publicado usando una cuenta exclusiva del dueño.
3. Configurar server-side `CONTROLBOT_OWNER_LOGIN` con el mismo identificador que el servidor entrega en `REMOTE_USER`.
4. No habilitar `DOMAIN` ni `DEPLOY_ENABLED` por inferencia; esas variables requieren su autorización explícita.
5. Confirmar que el productor offline del snapshot escribe `var/orchestrator-live.json` con permisos de lectura del proceso PHP y sin secretos.

No copies usuario, contraseña, cookies, tokens ni valores sensibles en Issues, PRs, comandos compartidos o este documento.

## Comprobación posterior

Después de #630 y este PR, pero antes de declarar el sitio live:

1. Una petición a `/` **sin credenciales** debe ser rechazada por la protección del servidor; no debe devolver `200`.
2. `/src/` y `/config/` deben responder **403/404** y nunca listar o servir código/configuración.
3. Con autenticación del dueño, `/` puede devolver HTML del Orquestador.
4. Con autenticación del dueño, `/api/orchestrator-live` puede devolver JSON read-only.
5. Si `var/orchestrator-live.json` falta, está corrupto o supera 300 segundos, el panel debe mostrar `UNKNOWN`; eso no se interpreta como producción verde.
6. Un merge o un HTTP 200 no sustituyen smoke/observer ni validación exact-SHA cuando esos gates apliquen.

Ejemplos deliberadamente sin credenciales:

```bash
curl -I https://control.condorapp.com.co/
curl -I https://control.condorapp.com.co/src/
curl -I https://control.condorapp.com.co/config/
```

El primer comando debe ser distinto de 200 sin autenticación; los dos últimos deben quedar en 403/404.

## Gate de merge y activación

Este leaf es build-ahead reversible. **No se fusiona** mientras falte cualquiera de estas condiciones:

- D-059 Git-first registrado en #627 con los tres datos de evidencia y la verificación 403/404 descrita arriba;
- PR #630: hardening de raíz con preflight de riesgo alto completado e integrado antes de este PR;
- ausencia de hallazgos bloqueantes en CI/revisión del HEAD exacto de este PR.

Orden operativo:

**#630 → este PR → variables `DOMAIN`/`DEPLOY_ENABLED` únicamente con decisión explícita.**

Nada en este documento autoriza modificar DNS, Hostinger real, credenciales, planes, datos de clientes, tags o producción.
