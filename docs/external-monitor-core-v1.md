# ExternalMonitorCore v1

Contrato puro de interpretación para el vigilante externo de ControlBot. No ejecuta probes, workflows ni alertas y no elige proveedor.

## Entradas

- `ProbeObservation`: `health|home`, timestamp, outcome, HTTP/latencia opcionales, versión/SHA reportados, freshness y referencia de evidencia.
- `WorkflowObservation`: visibilidad pública/privada, SHA, conclusión, presencia de runner/steps y referencia de evidencia.
- Estado presupuestario ya normalizado por #9: `normal|warning|critical|exhausted|blocked|unknown`.

## Semántica fail-closed

Un probe externo fresh 2xx puede producir `healthy`. Probe ausente, `freshness=unknown` o evidencia futura no se convierten en healthy. Un probe stale/expirado queda `degraded`; timeout/network error o 5xx fresh quedan `down`.

Un workflow `failure` exige evidencia de runner y steps. `startup_failure` exige ausencia de ambos y se clasifica separado como `blocked`, sin atribuir el fallo al código o YAML.

## Fixture #78

Sin probe externo, el estado de aplicación permanece `unknown`. La secuencia private success → private startup_failure para el mismo SHA, un run público sano y budget `exhausted` constituyen evidencia convergente de pérdida de capacidad del runner. El mecanismo final de billing permanece `unknown` mientras no exista evidencia adicional.

## Alert intent

`alert_intent` es solo una instrucción portable y secret-free. `application_down` o capacidad privada agotada requieren `external_channel_required=true`, precisamente para que GitHub Actions privado no sea el único canal.

## Fuera de alcance

HTTP real, webhooks, email/push, cron, persistencia, creación de incidentes, backup/restore, retención y UI. Esos consumidores deben conservar las mismas referencias y no ampliar autoridad.
