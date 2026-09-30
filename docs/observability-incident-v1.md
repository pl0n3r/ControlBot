# ObservabilityIncident v1

Core puro que convierte señales revalidadas por `ObservabilityEvent::normalize()` en incidentes deterministas. No persiste, notifica, congela ni muta Scheduler.

## Contrato
- Entrada: eventos raw + `now`, TTL y ventanas inyectadas; nada del caller se usa sin normalización canónica.
- Retries con fingerprint idéntico aumentan `occurrence_count` sin duplicar fingerprint ni timeline.
- Correlación exige project/environment compatibles y evidencia compartida; proximidad temporal sola no basta.
- Post-deploy exige además `deployment_ref|release_ref|sha` compartida dentro de la ventana.
- Incidente no correlacionable queda separado; severity solo sube.
- Recovery/monitoring solo actúa con señal `fresh`; stale/unknown nunca resuelve.
- Salida cerrada: identidad, scope, status, severity, fingerprints, razón, tiempos, contador y `timeline_events`.
- Estado interno de correlación nunca se expone.

## Timeline
`timeline_events` usa `event_id,timestamp,sequence,kind,source,evidence_ref` y se valida con `IncidentTimeline::build()`.

## Límites
Sin DB, red, filesystem write, shell, email/push, freeze/circuit breaker, Production Authority, Scheduler mutation, adapters ni UI. Reversión: eliminar las cuatro rutas del slice.
