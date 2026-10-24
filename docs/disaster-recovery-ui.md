# Security Center → Resilience / Disaster Recovery

`RecoveryCenterUi` es una proyección read-only por proyecto. **Factory #331** es la única autoridad de Recovery Health; ControlBot muestra ese estado literalmente y no lo recalcula desde RPO/RTO, backup o restore drill.

La vista combina `RecoveryProfile`, `RecoveryEvidence` y `RecoveryDrillProjection` para explicar database backup, offsite, inmutabilidad, media, drill y RPO/RTO. Detalles stale/unknown permanecen unknown. Si Factory Recovery Health falta, la vista muestra `UNKNOWN` con `RECOVERY_HEALTH_MISSING`; nunca infiere HEALTHY.

`execution=false` y `actions=[]` describen la proyección pura, que no crea WorkItems, no consulta Factory, no ejecuta restores ni invoca providers, scheduler, ranking, dispatch o escrituras.

## Ruta web integrada (solo lectura)

Desde #846 / PR #847, ControlBot ya dispone de `GET /security/resilience`. El router `ControlBotWebEntrypoint::handleAuthenticated` comprueba al owner por sesión OAuth + TOTP sellada mediante `OwnerAuthenticatedRouter::verifiedOwner` antes de delegar en `RecoveryCenterWebEntrypoint::handle`. La cabecera `REMOTE_USER` suministrada por cliente no concede acceso a esta ruta. Sin sesión válida se responde 403 y una petición POST se rechaza con 405. Si el perfil local es inválido, se responde 503 de forma genérica.

La vista lee un perfil local fijo y pasa evidencias nulas a `RecoveryCenterUi::project`: presenta `UNKNOWN` sin `BackupReceipt`, restore drill ni Factory Recovery Health. Los valores RPO 15 min y RTO 60 min son **objetivos**, no mediciones. La respuesta HTML delegada por `RecoveryCenterWebEntrypoint` contiene HTML escapado, `Cache-Control: no-store` y una CSP restrictiva; la vista no incluye formularios, scripts ni acciones mutantes. Las pruebas de aceptación son `tests/test_recovery_center_web.py`.

## Puerta operativa independiente

La decisión B en #577 es un antecedente histórico, sustituido para preparación acotada por la [opción A del OWNER en #858](https://github.com/pl0n3r/ControlBot/issues/858#issuecomment-6387350597) y el [journal auténtico `factory-human-decision` v2, opción A](https://github.com/pl0n3r/ControlBot/issues/858#issuecomment-6387355246). A permite preparar Backblaze B2, preflight read-only de versioning/Object Lock y diseño de credenciales least-privilege por canal privado, únicamente con acceso y permisos adecuados.

A **no autoriza** alta de cuenta, aceptar términos, pagos o gasto, almacenar credenciales en chat/GitHub, acceso a datos reales, escritura de backups, restore, operaciones destructivas o go-live. Cada permiso posterior debe verificarse por separado. ControlBot solo lee; Factory y FactoryRunner producen evidencia real.

Sin recibos verificables de cifrado, offsite, Object Lock, checksum, freshness y restore descartable, el estado sigue `UNKNOWN`, no HEALTHY. Los targets de RPO 15 y RTO 60 minutos no son RPO/RTO observados.
