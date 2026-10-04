# Refresco cron-ready del snapshot del Orquestador

El flujo permanece apagado por defecto. El colector \`scripts/orchestrator-evidence-collector.php\` hace solo GET a \`api.github.com\`; el wrapper \`scripts/orchestrator-snapshot-cron.php\` solo lee la evidencia local y escribe el snapshot. Ninguno se invoca desde requests web.

## Token read-only fuera del repo

Crea un fine-grained personal access token limitado a los siete repos gobernados, con **Metadata: read**, **Issues: read** y **Pull requests: read**. No concedas Content write, Actions write, Administration ni otros permisos. El valor no se pega en Issues, PRs, logs ni chat.

En el servidor, guárdalo sin eco y con permisos 600:

\`\`\`sh
install -d -m 700 "$HOME/.controlbot"
read -r -s CONTROLBOT_GITHUB_READ_TOKEN && printf '%s' "$CONTROLBOT_GITHUB_READ_TOKEN" > "$HOME/.controlbot/github-read-token" && unset CONTROLBOT_GITHUB_READ_TOKEN
chmod 600 "$HOME/.controlbot/github-read-token"
\`\`\`

## Variables y ejecución manual

\`\`\`sh
export CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED=1
export CONTROLBOT_GITHUB_READ_TOKEN_FILE="$HOME/.controlbot/github-read-token"
export CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH="$HOME/domains/control.condorapp.com.co/private/orchestrator-evidence.json"
export CONTROLBOT_ORCHESTRATOR_CRON_ENABLED=1
export CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH="$HOME/domains/control.condorapp.com.co/private/orchestrator-live.json"
/opt/alt/php85/usr/bin/php scripts/orchestrator-evidence-collector.php && /opt/alt/php85/usr/bin/php scripts/orchestrator-snapshot-cron.php
\`\`\`

El colector falla cerrado si el token/ruta/permisos son inválidos, el rate limit está bajo o GitHub responde con error. La escritura es atómica y conserva la evidencia anterior ante fallo.

## Cron en hPanel

Cuando el dueño decida instalarlo, programa cada 5 minutos el mismo encadenamiento \`colector && wrapper\` con \`/opt/alt/php85/usr/bin/php\`, definiendo las variables en configuración privada del servidor. No cambies \`DOMAIN\`, \`DEPLOY_ENABLED\`, DNS ni go-live desde este runbook.

## Reversión

Deshabilita \`CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED\` y \`CONTROLBOT_ORCHESTRATOR_CRON_ENABLED\`. Sin esas señales no hay red ni escrituras nuevas; el consumidor conserva su fail-closed de frescura.
