# Persistencia de dominio — ControlBot #1453

## Migración aditiva y contrato
`migrations/002_projects_agents_intake.sql` crea 5 tablas: proyectos, agentes,
sesiones, intake y pausa, con clave compuesta `(scope_id, record_id)`, payload
JSON y `recorded_at`. SQL es compatible con SQLite/MariaDB, sin DROP/ALTER.
`src/Persistence/ProjectRepository.php` publica `DomainSchemaMigrator` y
`PdoProjectRepository`, `PdoAgentRepository`, `PdoAgentSessionRepository`,
`PdoIntakeRepository`, `PdoPauseRepository`. Cada repositorio implementa
`save(scopeId,recordId,payload,recordedAt)` y `find(scopeId,recordId)`;
usa parámetros SQL, transacciones y validación fail-closed, aislando scopes.

## Dry-run, backup y puertas humanas
`DomainSchemaMigrator::dryRun(PDO)` verifica 5 declaraciones aditivas y su
SHA-256 **sin modificar tablas**. `apply(PDO,receipt,scope,writeAt)` usa
`BackupGate::evaluate('migration.registry.reconcile',...)` y rechaza
backup ausente, vencido o de otro scope antes de invocar el migrador 001
histórico y aplicar la 002 versionada. En SQLite usa transacción con rollback;
**MariaDB auto-confirma DDL**, por lo que exigiría snapshot real, restore,
D-059 y autorización OWNER de despliegue fuera de este PR.
Los receipts de las pruebas son ficticios y su validación estructural **no**
certifica backup externo, restauración o go-live. Sin activación implícita.

## Verificación y reversión
`python3 -m unittest -v tests.test_domain_persistence` prueba dry-run,
base vacía y con 001, aplicación real a SQLite desechable, idempotencia,
relectura y aislamiento de cinco entidades, errores, recibos inválidos y
rollback. El cambio solo añade código/documentación offline. Reversión:
revertir squash, nunca eliminar tablas productivas sin autoridad humana.
