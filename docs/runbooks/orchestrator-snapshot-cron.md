# Refresco cron-ready del snapshot del Orquestador

El colector `scripts/orchestrator-evidence-collector.php` hace GET read-only a GitHub y el wrapper `scripts/orchestrator-snapshot-cron.php` consume la evidencia local. El colector permanece apagado por defecto y el wrapper también; ambos quedan fuera de requests web.

El colector publica únicamente evidencia que los contratos actuales pueden demostrar: trabajo abierto como `pending` con labels de workflow allowlisted, bloqueos solo ante label explícita de bloqueo, decisiones del dueño y el PR fusionado reciente como `work.status=merged`. **Un PR fusionado no demuestra una release**: `releases` permanece vacío/UNKNOWN hasta que exista provenance real de release/tag. **No fabrica `work_inventory` ni porcentajes**: el panel central queda `UNKNOWN` cuando no existe una fuente canónica para esa señal.

## Límites fail-closed

- Solo se permiten GET a `https://api.github.com`; el adapter live rechaza otro scheme/host y fuerza HTTPS en cURL.
- Cada invocación real del transporte, incluido un retry de 5xx, consume el presupuesto máximo de **40 requests**.
- Un 5xx puede reintentarse una vez aunque el cuerpo no sea JSON; el JSON solo se decodifica para una respuesta HTTP 200.
- Cada respuesta debe reportar sus bytes reales. El total descargado acumulado no puede superar **2 MB** y la evidencia JSON final tampoco puede superar **2 MB**.
- Issues abiertos usan paginación acotada de hasta dos páginas de 100; si una tercera página sería necesaria, el colector falla cerrado.
- PRs cerrados se leen solo en una página reciente de 100 porque el contrato consume únicamente el primer PR fusionado reciente; no se recorre el historial completo.
- Factory #767 solo se considera RUNNING cuando la respuesta corresponde exactamente al Issue 767, autor `pl0n3r` y contiene un único marker canónico `version=1,state=RUNNING,owner=pl0n3r`.
- Retry-After, rate-limit bajo, shape ambiguo, bytes ausentes, truncación o cualquier error preservan la evidencia anterior; nunca dejan un archivo parcial.

## Credencial read-only fuera del repo

La credencial y sus valores concretos permanecen **fuera del repositorio** y se administran únicamente en la configuración privada del servidor.

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

Estos comandos son una referencia operativa; el token, el scheduler/cron, la configuración de hosting y cualquier valor live permanecen **fuera del repositorio**. Este cambio de código no instala ni activa ninguno de ellos.

## Cron en hPanel

Este runbook **no contiene una expresión de cron instalable** ni modifica hPanel por sí mismo. Cuando el dueño lo decida, programa el encadenamiento `colector && wrapper` con `/opt/alt/php85/usr/bin/php` y variables privadas del servidor. Este runbook no cambia `DOMAIN`, `DEPLOY_ENABLED`, DNS ni go-live. **ControlBot #625** conserva la autoridad separada de producción y activación live.

## Reversión

Deshabilita `CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED` y `CONTROLBOT_ORCHESTRATOR_CRON_ENABLED`. Sin esas señales no hay red ni escrituras nuevas.
