# Refresco cron-ready del snapshot del Orquestador

El colector `scripts/orchestrator-evidence-collector.php` hace GET read-only a GitHub y el wrapper `scripts/orchestrator-snapshot-cron.php` consume la evidencia local. El colector permanece **apagado por defecto** y el wrapper también; ambos quedan fuera de requests web.

El contrato separa dos pasos: el colector obtiene evidencia y el wrapper construye/escribe `var/orchestrator-live.json`. Un fallo del wrapper nunca instala el cron, nunca cambia live y nunca amplía autoridad.

## Credencial read-only fuera del repositorio

La credencial y sus valores concretos permanecen **fuera del repositorio** y se administran únicamente en la configuración privada del servidor.

Crea un fine-grained personal access token limitado a los siete repos gobernados con **Metadata: read**, **Issues: read** y **Pull requests: read**; sin permisos de escritura.

En el servidor, guárdalo sin eco:

```sh
install -d -m 700 "$HOME/.controlbot"
read -r -s CONTROLBOT_GITHUB_READ_TOKEN && printf '%s' "$CONTROLBOT_GITHUB_READ_TOKEN" > "$HOME/.controlbot/github-read-token" && unset CONTROLBOT_GITHUB_READ_TOKEN
chmod 600 "$HOME/.controlbot/github-read-token"
```

## Guardrails del colector

- Transporte HTTPS-only contra `api.github.com`.
- Máximo 40 requests HTTP reales por ejecución, contando retries.
- Máximo 2 MB por respuesta, 8 MB acumulados descargados y 2 MB para la evidencia final.
- Factory Issue #767 solo se considera RUNNING con Issue, autor y marker exactos; cualquier ambigüedad falla cerrado.
- Issues pueden paginar como máximo dos páginas de 100; closed PRs usan una sola página reciente de 10.
- Ante rate-limit, retry-after, budget excedido o transporte inválido se conserva la evidencia anterior.
- El transporte sigue siendo REST/GET-only y no publica secretos, headers ni cuerpos remotos.

## Diagnóstico seguro del wrapper

El wrapper ya no aplasta todos los fallos a `execution failed`. Ante error emite únicamente un código allowlisted:

| Código | Significado operativo |
| --- | --- |
| `clock_invalid` | reloj/epoch no utilizable |
| `snapshot_target_invalid` | target no absoluto, symlink o shape no permitido |
| `snapshot_directory_unwritable` | directorio ausente o no escribible |
| `evidence_invalid` | evidencia ausente, ilegible o JSON inválido |
| `snapshot_build_failed` | la evidencia no puede producir el snapshot canónico |
| `snapshot_size_invalid` | salida fuera del budget de bytes |
| `temp_write_failed` | no pudo crear/escribir/verificar el temporal seguro |
| `atomic_rename_failed` | falló rename y también el fallback verificado |
| `internal_error` | causa no clasificada; fail-closed |

Ejemplo:

```text
orchestrator-snapshot-cron: snapshot_directory_unwritable
```

Nunca se imprimen rutas privadas, contenido de evidencia, cookies, headers, hashes de archivos, mensajes crudos de excepción ni secretos.

### Diagnóstico de entorno opcional

Para una ejecución manual de diagnóstico puede habilitarse temporalmente:

```sh
export CONTROLBOT_ORCHESTRATOR_SNAPSHOT_DIAGNOSTICS=1
```

El sufijo solo puede contener señales read-only y allowlisted:

```text
dir_exists=1 dir_writable=1 dir_owner_match=1 dir_mode=0700
```

No incluye el path. `dir_owner_match=unknown` es válido cuando el runtime no expone el UID efectivo. La variable debe permanecer ausente en operación normal.

## Escritura robusta en hosting compartido

La escritura conserva el enfoque fail-closed:

1. valida target y directorio antes de tocar el snapshot;
2. construye el JSON en memoria y aplica el límite de tamaño;
3. crea el temporal **en el mismo directorio** del snapshot;
4. escribe el temporal con `LOCK_EX` y verifica bytes;
5. solicita modo `0640`; si `chmod` falla, solo continúa cuando el modo observado ya es seguro (`0600` o `0640`);
6. intenta `rename` como reemplazo atómico primario;
7. si `rename` falla por una variación del hosting compartido, usa `LOCK_EX` sobre el target y verifica byte por byte el resultado;
8. si el fallback no puede verificarse, restaura el snapshot previo cuando existe y termina con `atomic_rename_failed`.

Así, una variación de `chmod` o `rename` no convierte un fallo cosmético del filesystem en caída del Orquestador, pero tampoco acepta una escritura no comprobada.

## Ruta canónica del snapshot

El repositorio desplegado vive en:

```text
/home/u151692719/domains/control.condorapp.com.co/public_html
```

`FactoryOrchestratorWebEntrypoint` lee, por defecto, `var/orchestrator-live.json` relativo a esa raíz. El productor offline debe escribir en:

```text
$HOME/domains/control.condorapp.com.co/public_html/var/orchestrator-live.json
```

Prepara el directorio una sola vez. `var/` está ignorado por Git y no debe ser symlink:

```sh
SITE_ROOT="$HOME/domains/control.condorapp.com.co/public_html"
install -d -m 700 "$SITE_ROOT/var"
test ! -L "$SITE_ROOT/var"
```

El `.htaccess` del sitio bloquea `.json` y rutas internas; el archivo está destinado al lector PHP local, no a descarga pública.

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

Si falla, activa `CONTROLBOT_ORCHESTRATOR_SNAPSHOT_DIAGNOSTICS=1` solo para una ejecución manual, lee el código y el tuple `dir_*`, corrige el entorno y vuelve a desactivarlo. No copies rutas privadas o credenciales a Issues.

## Cron en hPanel

Configura cada 5 minutos el mismo encadenamiento `colector && wrapper`, usando PHP 8.5 y un log estable. **La línea es instalable tal cual** para este hosting y no contiene el token, solo la ruta privada del archivo de credencial:

```cron
*/5 * * * * cd "$HOME/domains/control.condorapp.com.co/public_html" && { export CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED=1 CONTROLBOT_GITHUB_READ_TOKEN_FILE="$HOME/.controlbot/github-read-token" CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH="$HOME/domains/control.condorapp.com.co/private/orchestrator-evidence.json" CONTROLBOT_ORCHESTRATOR_CRON_ENABLED=1 CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH="$HOME/domains/control.condorapp.com.co/public_html/var/orchestrator-live.json"; /opt/alt/php85/usr/bin/php scripts/orchestrator-evidence-collector.php && /opt/alt/php85/usr/bin/php scripts/orchestrator-snapshot-cron.php; } >> "$HOME/.controlbot/orchestrator-snapshot-cron.log" 2>&1
```

Este runbook no modifica hPanel por sí mismo, no cambia `DOMAIN`, `DEPLOY_ENABLED`, DNS ni go-live. **ControlBot #625** conserva la autoridad separada de producción y activación live.

## Reversión

Deshabilita `CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED` y `CONTROLBOT_ORCHESTRATOR_CRON_ENABLED`. Sin esas señales no hay red ni escrituras nuevas. El cambio de #726 es reversible por `revert` y no modifica el contrato de evidencia ni del snapshot.
