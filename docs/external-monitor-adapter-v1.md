# External Monitor Adapter v1

## Límite
El adapter ejecuta el transporte; `ExternalMonitorCore` sigue siendo la única autoridad de diagnóstico y `alert_intent`. El runner PHP es apto para cron de Hostinger y no depende de GitHub Actions.

## Probe
Solo HTTPS, sin userinfo/query/fragment, con endpoint `/health` o `/`. Timeout, redirects y tamaño de body están acotados. Del body solo se extraen `version` semver y `sha` de 40 hex cuando son válidos; el body nunca entra al resultado.

## Alerta externa
El adapter envía únicamente `version,severity,code,dedupe_key,evidence_refs`. El receipt contiene `delivery_id,channel,status,code,observed_at,evidence_ref`; URL, token, respuesta del proveedor y payload arbitrario no se registran.

## Cron
`scripts/external-monitor.php` requiere `CONTROLBOT_MONITOR_URL` y `CONTROLBOT_MONITOR_WEBHOOK` por entorno. Configuración ausente falla cerrado. Código 0=healthy, 2=diagnóstico no healthy con canal operativo, 3=fallo de canal, 64=configuración/uso y 70=ejecución inválida.

## Reversión
Cinco rutas aditivas, sin DB/migraciones ni estado persistente. Revertirlas elimina adapter, runner, pruebas y documentación.
