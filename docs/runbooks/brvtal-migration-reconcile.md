# BRVTAL#681 — Migration Reconcile Runbook

Este runbook demuestra la composición de Production Authority sin ejecutar producción.

## Secuencia

`migration.status → database.backup → migration.registry.reconcile → migration.verify → health.check → smoke.run → revoke`.

`BrvtalMigrationReconcileRunbook::project()` devuelve únicamente la siguiente intención. No llama Hostinger, SSH, DB, SecretsBroker ni HostingerExecutor.

## Guardas

- `migration.registry.reconcile` reutiliza `BackupGate`; sin receipt `database_dump` válido, matching, ready y usable no existe intención de write.
- `ambiguous` aborta con `baseline_allowed=false`.
- `migration.verify` espera cero migraciones pendientes.
- `health.check` exige `schema_up_to_date=true`.
- `smoke.run` queda ligado al `run_id` y SHA exactos.
- cualquier estado terminal proyecta revocación del grant.
- un fallo intermedio queda `recoverable` con `resume_from`; nunca se presenta como success.

El agente recibe solo identificadores y evidencia saneada. Credenciales SSH/DB, secretos, payloads de backup, shell arbitrario, writes reales y ejecución live de BRVTAL#681 quedan fuera de alcance.
