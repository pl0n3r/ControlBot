# Disaster Recovery Drill — ControlBot RESTORE_DRILL

Este slice de #182 proyecta un restore drill ya evaluado por **Factory #330**. ControlBot no ejecuta restore, decrypt, migraciones, health, smoke, integrity ni provider I/O.

## Autoridad y frontera

- Factory #330 es la autoridad de `PASSED|BREACHED`, RPO/RTO observados y reasons.
- Factory #331 es la autoridad que deriva Recovery Health y clases canónicas de drift/WorkItem.
- ControlBot conserva la proyección por proyecto y aplica freshness fail-closed.
- `BackupReceipt` nominal, `RecoveryProfile` y `RecoveryEvidence` deben pertenecer al mismo proyecto.

La proyección **no recalcula** PASSED/BREACHED comparando métricas. Solo valida schema, scope, targets declarados, provenance y límites de seguridad.

## Freshness

Un resultado `fresh` conserva el status reportado. `stale|unknown` proyecta `status=UNKNOWN`, pero mantiene `reported_status` y métricas para explicación. UNKNOWN/stale nunca equivale a HEALTHY.

## Seguridad

Solo se acepta target `disposable`, checks `health|smoke|integrity=true`, `authority=unchanged` y `execute=false`. Production/cutover, campos extra, refs sensibles o material de backup fallan cerrado. La proyección conserva refs, no secretos ni payloads.

## Fuera de alcance

Recovery Health, WorkItems, readiness, scheduler, FactoryRunner, restore real, producción, DB/media writes y failover/cutover. Esas responsabilidades permanecen fuera de este slice.
