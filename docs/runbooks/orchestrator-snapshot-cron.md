# Refresco cron-ready del snapshot del Orquestador

El colector `scripts/orchestrator-evidence-collector.php` hace GET read-only a GitHub y el wrapper `scripts/orchestrator-snapshot-cron.php` consume la evidencia local. El colector permanece apagado por defecto y el wrapper también; ambos quedan fuera de requests web.

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
- Máximo **2 MB** acumulados descargados por ejecución y **2 MB** para la evidencia final.
- Cada respuesta debe reportar `bytes` reales; no se estiman reserializando JSON.
- Un primer **5xx** puede hacer **retry 5xx** aunque el body no sea JSON; una respuesta HTTP 200 sí debe contener JSON válido.
- Factory **Issue #767** solo se considera RUNNING si el payload corresponde exactamente al Issue 767, el autor es `pl0n3r`, existe un único marker y su JSON exacto es `{"version":1,"state":"RUNNING","owner":"pl0n3r"}`. Duplicados, marker inválido, autor distinto o issue distinto fallan cerrado.
- Issues pueden paginar como máximo dos páginas de 100; closed PRs consultan solo **una página reciente** de 100 y no solicitan page=2 aunque esa primera página venga llena.
- Ante rate-limit, retry-after, budget excedido o transporte inválido, se conserva el archivo anterior mediante reemplazo atómico.

## Variables y prueba manual

```sh
export CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED=1
export CONTROLBOT_GITHUB_READ_TOKEN_FILE="$HOME/.controlbot/github-read-token"
export CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH="$HOME/domains/control.condorapp.com.co/private/orchestrator-evidence.json"
export CONTROLBOT_ORCHESTRATOR_CRON_ENABLED=1
export CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH="$HOME/domains/control.condorapp.com.co/private/orchestrator-live.json"
/opt/alt/php85/usr/bin/php scripts/orchestrator-evidence-collector.php && /opt/alt/php85/usr/bin/php scripts/orchestrator-snapshot-cron.php
```

El colector usa un máximo de 40 requests reales. Issues pueden paginar como máximo dos páginas de 100 y fallan cerrado si existiría una tercera; closed PRs consultan solo **una página reciente** de 100 porque únicamente se publica el primer merge observado y no se recorre historial. El snapshot admite como máximo 24 fronts: si el trabajo activo excede el contrato, el colector falla cerrado en vez de truncar o rankear. Ante rate-limit/error/budget excedido conserva el archivo anterior mediante reemplazo atómico.

## Cron en hPanel

Este runbook **no contiene una expresión de cron instalable** ni modifica hPanel por sí mismo. Cuando el dueño lo decida, programa cada 5 minutos el mismo encadenamiento `colector && wrapper` con `/opt/alt/php85/usr/bin/php` y variables privadas del servidor. Este runbook no cambia `DOMAIN`, `DEPLOY_ENABLED`, DNS ni go-live. **ControlBot #625** conserva la autoridad separada de producción y activación live.

## Reversión

Deshabilita `CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED` y `CONTROLBOT_ORCHESTRATOR_CRON_ENABLED`. Sin esas señales no hay red ni escrituras nuevas.
