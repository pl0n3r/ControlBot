# Security Center → Resilience / Disaster Recovery

`RecoveryCenterUi` es una proyección read-only por proyecto. **Factory #331** es la única autoridad de Recovery Health; ControlBot muestra ese estado literalmente y no lo recalcula desde RPO/RTO, backup o restore drill.

La vista combina `RecoveryProfile`, `RecoveryEvidence` y `RecoveryDrillProjection` para explicar database backup, offsite, inmutabilidad, media, drill y RPO/RTO. Detalles stale/unknown permanecen unknown. Si Factory Recovery Health falta, la vista muestra `UNKNOWN` con `RECOVERY_HEALTH_MISSING`; nunca infiere HEALTHY.

`execution=false` y `actions=[]`. no crea WorkItems, no llama Factory, no ejecuta restores, providers, scheduler, ranking, dispatch ni writes. HTML/routing final queda fuera de este slice.
