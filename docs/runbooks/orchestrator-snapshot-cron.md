# Refresco cron-ready del snapshot del Orquestador

El colector `scripts/orchestrator-evidence-collector.php` hace GET read-only a GitHub y el wrapper `scripts/orchestrator-snapshot-cron.php` consume la evidencia local. Ambos permanecen apagados por defecto y fuera de requests web.

El colector publica únicamente evidencia que los contratos actuales pueden demostrar: frentes desde labels explícitos, bloqueos, decisiones del dueño y PRs fusionados recientes. **No fabrica `work_inventory` ni porcentajes**: hoy no existe un productor canónico que derive `READY/ALL_BLOCKED/...` desde GitHub crudo, por lo que el panel central queda `UNKNOWN` hasta que exista ese contrato.

## Credencial read-only fuera del repo

Crea un fine-grained personal access token limitado a los siete repos gobernados con **Metadata: read**, **Issues: read** y **Pull requests: read**; sin permisos de escritura.

En el servidor, guárdalo sin eco:

```sh
install -d -m 700 "$HOME/.controlbot"
read -r -s CONTROLBOT_GITHUB_READ_TOKEN && printf '%s' "$CONTROLBOT_GITHUB_READ_TOKEN" > "$HOME/.controlbot/github-read-token" && unset CONTROLBOT_GITHUB_READ_TOKEN
chmod 600 "$HOME/.controlbot/github-read-token"
```

## Variables y prueba manual

```sh
export CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED=1
export CONTROLBOT_GITHUB_READ_TOKEN_FILE="$HOME/.controlbot/github-read-token"
export CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH="$HOME/domains/control.condorapp.com.co/private/orchestrator-evidence.json"
export CONTROLBOT_ORCHESTRATOR_CRON_ENABLED=1
export CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH="$HOME/domains/control.condorapp.com.co/private/orchestrator-live.json"
/opt/alt/php85/usr/bin/php scripts/orchestrator-evidence-collector.php && /opt/alt/php85/usr/bin/php scripts/orchestrator-snapshot-cron.php
```

El colector usa un máximo de 40 requests, pagina como máximo dos páginas por endpoint, falla cerrado ante truncación/rate-limit/error y reemplaza la evidencia de forma atómica; ante fallo conserva el archivo anterior.

## Cron en hPanel

Cuando el dueño lo decida, programa cada 5 minutos el mismo encadenamiento `colector && wrapper` con `/opt/alt/php85/usr/bin/php` y variables privadas del servidor. Este runbook no cambia `DOMAIN`, `DEPLOY_ENABLED`, DNS ni go-live.

## Reversión

Deshabilita `CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED` y `CONTROLBOT_ORCHESTRATOR_CRON_ENABLED`. Sin esas señales no hay red ni escrituras nuevas.
