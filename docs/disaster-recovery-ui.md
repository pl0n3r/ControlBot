# Security Center → Resilience / Disaster Recovery

`RecoveryCenterUi` es una proyección read-only por proyecto. **Factory #331** es la única autoridad de Recovery Health; ControlBot muestra ese estado literalmente y no lo recalcula desde RPO/RTO, backup o restore drill.

La vista combina `RecoveryProfile`, `RecoveryEvidence` y `RecoveryDrillProjection` para explicar database backup, offsite, inmutabilidad, media, drill y RPO/RTO. Detalles stale/unknown permanecen unknown. Si Factory Recovery Health falta, la vista muestra `UNKNOWN` con `RECOVERY_HEALTH_MISSING`; nunca infiere HEALTHY.

`execution=false` y `actions=[]` describen la proyección pura, que no crea WorkItems, no consulta Factory, no ejecuta restores ni invoca providers, scheduler, ranking, dispatch o escrituras.

## Ruta web integrada (solo lectura)

Desde #846 / PR #847, ControlBot ya dispone de `GET /security/resilience`. El router `ControlBotWebEntrypoint::handleAuthenticated` comprueba al owner por sesión OAuth + TOTP sellada mediante `OwnerAuthenticatedRouter::verifiedOwner` antes de delegar en `RecoveryCenterWebEntrypoint::handle`. La cabecera `REMOTE_USER` suministrada por cliente no concede acceso a esta ruta. Sin sesión válida se responde 403 y una petición POST se rechaza con 405. Si el perfil local es inválido, se responde 503 de forma genérica.

La vista lee un perfil local fijo y pasa evidencias nulas a `RecoveryCenterUi::project`: presenta `UNKNOWN` sin `BackupReceipt`, restore drill ni Factory Recovery Health. Los valores RPO 15 min y RTO 60 min son **objetivos**, no mediciones. La respuesta es HTML escapado con `Cache-Control: no-store`, CSP restrictiva y sin formularios, scripts o acciones mutantes. Las pruebas de aceptación son `tests/test_recovery_center_web.py`.

## Puerta operativa independiente

La decisión del propietario **B en #577** sigue vigente: no provisionar Backblaze B2, buckets ni credenciales, y no activar Recovery live. La existencia de esta ruta no acredita copia offsite, versioning/Object Lock, checksums, restore ejecutado ni RPO/RTO observados; la ausencia de esa evidencia debe seguir siendo `UNKNOWN`, nunca `HEALTHY`.
