# Customer Success → Owner Inbox v1

`CustomerSuccessOwnerInboxProjection` es el boundary read-only entre señales agregadas de Customer Success (#256) y `OwnerInbox` (#276). Su único propósito es convertir una **excepción ya seleccionada por policy/caller** en una entrada canónica consumible por Executive Cockpit.

## Semántica

- `fromSupport()` normaliza primero el `SupportSignal` con `CustomerSuccessCore::supportSignal()`.
- `fromSnapshotDimension()` normaliza el `CustomerSuccessSnapshot`, selecciona una dimensión por nombre y conserva su freshness/provenance.
- `fresh|stale|unknown` se mapea a `current|stale|unknown`; `unknown` no fabrica `source_ref`, evidence ni `observed_at`.
- La salida pasa siempre por `OwnerInbox::entry()` y queda Venture-scoped.

## Authority y prioridad

La proyección **no** convierte `severity`, `churn_risk`, `confidence`, `nature` ni ningún valor de Customer Success en `FYI|WATCH|DECISION|CRITICAL`. El caller entrega de forma explícita `class`, copy, authority, decision/options refs y deadline. Así, un SupportSignal `critical` puede seguir siendo `watch` si esa es la decisión de policy, y un churn inferido nunca se presenta automáticamente como hecho o urgencia.

`confidence` y `nature` permanecen en el snapshot fuente de #256; Owner Inbox conserva una `source_ref` determinista para trazabilidad sin duplicar ni reinterpretar esos campos.

## Privacidad y límites

No copia tickets, transcripts, message bodies, nombres, emails, teléfonos ni payloads de CRM/helpdesk. Las refs de source/evidence son hashes opacos bajo namespace `controlbot:`. No hay DB, red, provider, scheduler, notificaciones, ranking, Factory dispatch ni ejecución.

Reversión: retirar este adaptador y sus pruebas/docs. No existe estado externo.
