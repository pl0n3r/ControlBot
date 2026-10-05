# Refresco cron-ready del snapshot del Orquestador

El colector `scripts/orchestrator-evidence-collector.php` hace GET read-only a GitHub y el wrapper `scripts/orchestrator-snapshot-cron.php` consume la evidencia local. El colector permanece apagado por defecto y el wrapper también; ambos quedan fuera de requests web.

Guardrails de transporte: el adapter es **HTTPS-only** sobre `https://api.github.com` y fuerza `CURLPROTO_HTTPS`. Un primer 5xx puede conservar body no-JSON para que el collector lo contabilice y haga un **retry 5xx** único; solo HTTP 200 exige JSON válido. Factory **Issue #767** se acepta como RUNNING únicamente con número exacto 767, autor `pl0n3r` y un único marker canónico; cualquier ambigüedad falla cerrado.

El colector publica únicamente evidencia que los contratos actuales pueden demostrar. Para Issues con labels de workflow allowlisted conserva `signal.state=pending` y `data.labels` sin derivar un `data.status`; el consumidor los presenta como `unknown`. Los PRs abiertos usan `data.status=in_review`, los bloqueos viven solo en `blockers` y el PR fusionado reciente usa `data.status=merged`. Un **PR fusionado no equivale a release**: `releases` permanece vacío hasta disponer de provenance real de tag/release. **No fabrica `work_inventory`, ranking ni porcentajes**.

## Credencial read-only fuera del repo

La credencial y sus valores concretos permanecen **fuera del repositorio** y se administran únicamente en la configuración privada del servidor.

Crea un fine-grained personal access token limitado a los siete repos gobernados con **Metadata: read**, **Issues: read** y **Pull requests: read**; sin permisos de escritura.

En el servidor, guárdalo sin eco:

```sh
install -d -m 700 "$HOME/.controlbot"
read -r -s CONTROLBOT_GITHUB_READ_TOKEN && printf '%s' "$CONTROLBOT_GITHUB_READ_TOKEN" > "$HOME/.controlbot/github-read-token" && unset CONTROLBOT_GITHUB_READ_TOKEN
chmod 600 "$HOME/.controlbot/github-read-token"
```

## Guardrails del colector

- Transporte **HTTPS-only** contra `api.github.com`; cualquier otro scheme, host, userinfo o puerto no permitido falla cerrado.
- Máximo **40 requests HTTP reales** por ejecución, contando retries.
- Máximo **2 MB por respuesta**, **8 MB acumulados descargados por ejecución** y **2 MB para la evidencia final**.
- Cada respuesta debe reportar `bytes` reales; no se estiman reserializando JSON.
- Un primer **5xx** puede hacer **retry 5xx** aunque el body no sea JSON; una respuesta HTTP 200 sí debe contener JSON válido.
- Factory **Issue #767** solo se considera RUNNING si el payload corresponde exactamente al Issue 767, el autor es `pl0n3r`, existe un único marker y su JSON exacto es `{"version":1,"state":"RUNNING","owner":"pl0n3r"}`. Duplicados, marker inválido, autor distinto o issue distinto fallan cerrado.
- Issues pueden paginar como máximo dos páginas de 100; closed PRs consultan solo **una página reciente de 10** y no solicitan page=2 porque únicamente se necesita el primer merge reciente.
- Ante rate-limit, retry-after, budget excedido o transporte inválido, se conserva el archivo anterior mediante reemplazo atómico.
- El transporte continúa siendo REST/GET-only. La reducción de la página de PRs evita ampliar la superficie a GraphQL/POST para resolver este incidente.

## Diagnóstico seguro del CLI

Ante fallo, el CLI emite solo un código allowlisted y, cuando existe, el path de API sin query y el estado HTTP. Nunca imprime el token, cabeceras, body de GitHub, mensaje crudo de excepción ni rutas privadas del servidor.

Códigos operativos:

- `download_budget_exceeded`
- `request_budget_exceeded`
- `token_file_invalid`
- `evidence_path_unwritable`
- `transport_unavailable`
- `rate_limited`
- `github_response_invalid`
- `github_read_failed`
- `evidence_budget_exceeded`
- `signal_budget_exceeded`
- `request_policy_denied`
- `clock_invalid`
- `http_status_<código>`
- `internal_error` como fallback fail-closed para una causa no clasificada

Ejemplo seguro:

```text
orchestrator-evidence-collector: http_status_403 path=/repos/pl0n3r/Factory/issues status=403
```

## Ruta canónica del snapshot

El repositorio desplegado vive en:

```text
/home/u151692719/domains/control.condorapp.com.co/public_html
```

`FactoryOrchestratorWebEntrypoint` lee, por defecto, `var/orchestrator-live.json` relativo a esa raíz. Por tanto, el productor offline debe escribir exactamente en:

```text
$HOME/domains/control.condorapp.com.co/public_html/var/orchestrator-live.json
```

Prepara el directorio una sola vez. `var/` está ignorado por Git, por lo que el despliegue que reemplaza archivos rastreados no debe pisar el snapshot. No uses enlaces simbólicos:

```sh
SITE_ROOT="$HOME/domains/control.condorapp.com.co/public_html"
install -d -m 700 "$SITE_ROOT/var"
test ! -L "$SITE_ROOT/var"
```

El `.htaccess` del sitio bloquea `.json` y rutas internas; el archivo queda destinado al lector PHP local, no a descarga pública.

## Variables y prueba manual

Ejecuta desde la raíz desplegada:

```sh
cd "$HOME/domains/control.condorapp.com.co/public_html"
export CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED=1
export CONTROLBOT_GITHUB_READ_TOKEN_FILE="$HOME/.controlbot/github-read-token"
export CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH="$HOME/domains/control.condorapp.com.co/private/orchestrator-evidence.json"
export CONTROLBOT_ORCHESTRATOR_CRON_ENABLED=1
export CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH="$HOME/domains/control.condorapp.com.co/public_html/var/orchestrator-live.json"
/opt/alt/php85/usr/bin/php scripts/orchestrator-evidence-collector.php && /opt/alt/php85/usr/bin/php scripts/orchestrator-snapshot-cron.php
```

Comprueba permisos y edad sin imprimir secretos:

```sh
SNAPSHOT="$HOME/domains/control.condorapp.com.co/public_html/var/orchestrator-live.json"
ls -ld "$(dirname "$SNAPSHOT")" "$SNAPSHOT"
test -f "$SNAPSHOT" && echo "snapshot_age_seconds=$(( $(date +%s) - $(stat -c %Y "$SNAPSHOT") ))"
```

El colector usa un máximo de 40 requests reales y un budget acumulado de **8 MB** de bytes descargados; cada respuesta individual conserva el tope de 2 MB y la evidencia serializada también está limitada a 2 MB. Cada retry consume requests y bytes reales, sin fallback estimado. Issues pueden paginar como máximo dos páginas de 100 y fallan cerrado si existiría una tercera; closed PRs consultan solo **una página reciente** de 10 porque únicamente se publica el primer merge observado y no se recorre historial. El snapshot admite como máximo 24 fronts: si el trabajo activo excede el contrato, el colector falla cerrado en vez de truncar o rankear. Ante rate-limit/error/budget excedido conserva el archivo anterior mediante reemplazo atómico.

La regresión de #720 cubre una respuesta grande de aproximadamente **1,8 MB** y un conjunto de respuestas de varios repos que supera 5 MB sin rebasar el límite acumulado de 8 MB. El problema observado originalmente provenía de pedir 100 PRs cerrados de Factory en una sola respuesta; limitar esa consulta a 10 reduce el volumen sin cambiar el modelo de evidencia ni los permisos del token.

## Cron en hPanel

Configura **cada 5 minutos** el mismo encadenamiento `colector && wrapper`, usando PHP 8.5 y un log estable. La línea es instalable tal cual para este hosting y no contiene el token, solo la ruta privada del archivo de credencial:

```cron
*/5 * * * * cd "$HOME/domains/control.condorapp.com.co/public_html" && { export CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED=1 CONTROLBOT_GITHUB_READ_TOKEN_FILE="$HOME/.controlbot/github-read-token" CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH="$HOME/domains/control.condorapp.com.co/private/orchestrator-evidence.json" CONTROLBOT_ORCHESTRATOR_CRON_ENABLED=1 CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH="$HOME/domains/control.condorapp.com.co/public_html/var/orchestrator-live.json"; /opt/alt/php85/usr/bin/php scripts/orchestrator-evidence-collector.php && /opt/alt/php85/usr/bin/php scripts/orchestrator-snapshot-cron.php; } >> "$HOME/.controlbot/orchestrator-snapshot-cron.log" 2>&1
```

Este runbook no modifica hPanel por sí mismo, no cambia `DOMAIN`, `DEPLOY_ENABLED`, DNS ni go-live. **ControlBot #625** conserva la autoridad separada de producción y activación live.

## Reversión

Deshabilita `CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED` y `CONTROLBOT_ORCHESTRATOR_CRON_ENABLED`. Sin esas señales no hay red ni escrituras nuevas.
