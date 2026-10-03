# Entrada pública del Orquestador en Hostinger

ControlBot sigue en **construcción**. Este tramo prepara la entrada web owner-only del Orquestador; no activa deploy, dominio ni go-live.

Para este sitio y esta fase, la decisión del dueño registrada en ControlBot#634 acepta **Git como respaldo** para D-059. No se exige un backup de Hostinger/hPanel antes de #630/#656 mientras la publicación siga compuesta únicamente por contenido versionado del repositorio. La excepción no cubre DB, DNS, cron, dominios, planes, otros productos ni datos fuera de Git.

## Límite y flujo

`public_html/index.php` → `public/index.php` → `FactoryOrchestratorWebEntrypoint` → `FactoryOrchestratorLiveEndpoint`.

La autenticación ocurre antes del runtime. `public/index.php` recibe la identidad solo por `REMOTE_USER` y la compara con `CONTROLBOT_OWNER_LOGIN`; identidad/config faltante falla cerrada. La superficie read-only es `GET /` (HTML) y `GET /api/orchestrator-live` (JSON). El navegador no consulta GitHub, no recibe tokens y no escribe estado.

## Snapshot local

La fuente es `var/orchestrator-live.json`, producida fuera del request por un proceso gobernado.

- máximo 2 MB;
- JSON canónico y fingerprint válido;
- `observed_at` no futuro;
- frescura máxima: **300 segundos**;
- ausente, inválido o stale → `UNKNOWN`, nunca evidencia inventada;
- caché local atómica en el directorio temporal.

El hardening del PR #630 bloquea `.json` y rutas internas. Orden obligatorio: **#630 → este PR**.

## D-059 · respaldo Git-first

Antes de fusionar el frente live, #627 debe registrar:
1. **SHA exacto de main** inmediatamente antes del merge.
2. **SHA del último despliegue sano observado**.
3. Rollback **Git-first** mediante **revert explícito del merge**, sin reescribir `main`.
4. Verificación posterior: `/src/` y `/config/` siguen en **403/404** y la raíz no expone fuentes.

Ese registro satisface D-059 para ControlBot en construcción bajo la decisión vigente. Una plantilla incompleta no cuenta como evidencia.

## Preparación operativa

En hPanel: proteger el directorio publicado con una cuenta exclusiva del dueño, configurar server-side `CONTROLBOT_OWNER_LOGIN`, y confirmar que el productor offline pueda escribir `var/orchestrator-live.json`. No activar `DOMAIN` ni `DEPLOY_ENABLED` por inferencia. No copiar usuarios, contraseñas, cookies, tokens ni valores sensibles en Issues, PRs o documentación.

## Comprobación posterior

- `/` sin credenciales no debe devolver 200.
- `/src/` y `/config/` deben quedar en 403/404.
- autenticado: `/` puede servir HTML y `/api/orchestrator-live` JSON read-only;
- snapshot ausente/corrupto/>300 segundos debe mostrar `UNKNOWN`;
- merge o HTTP 200 no equivalen a producción verde.

```bash
curl -I https://control.condorapp.com.co/
curl -I https://control.condorapp.com.co/src/
curl -I https://control.condorapp.com.co/config/
```

## Gate de merge

Este leaf es reversible y **no se fusiona** hasta que D-059 Git-first esté registrado en #627, #630 esté integrado primero y el HEAD exacto de este PR tenga CI/revisión terminal-green.

Orden: **#630 → este PR → variables `DOMAIN`/`DEPLOY_ENABLED` únicamente con decisión explícita.**

Nada aquí autoriza modificar DNS, Hostinger real, credenciales, planes, datos, tags o producción.
