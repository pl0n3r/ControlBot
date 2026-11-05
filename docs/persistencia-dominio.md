# Persistencia de dominio ControlBot — #1453

## Alcance ejecutable (solo construcción/offline)

La migración `migrations/002_projects_agents_intake.sql` crea cinco tablas
**aditivas** para proyectos, agentes, sesiones de agentes, intake y estado de
pausa. Cada tabla guarda `scope_id`, `record_id`, `payload_json`,
`recorded_at`; la clave compuesta `(scope_id, record_id)` garantiza separación
de ámbitos en SQL. No se realizan borrados, ALTER, renombres ni cambios de
configuración. Compatible con SQLite (fixtures desechables) y MariaDB (tipos
SQL básicos y `CREATE TABLE IF NOT EXISTS`).

El archivo `src/Persistence/ProjectRepository.php` implementa
`DomainSchemaMigrator::dryRun(PDO)`, `::apply(PDO, ?array, array, int)` y
cinco repositorios `PdoProjectRepository`, `PdoAgentRepository`,
`PdoAgentSessionRepository`, `PdoIntakeRepository` y
`PdoPauseRepository`. Cada repositorio admite `save(scopeId, recordId,
payload, recordedAt)` y `find(scopeId, recordId)`. La persistencia usa
parámetros SQL, validación de IDs/payload, transacciones (delete+insert) y
rollback de fallo de escritura. Se impide leer un registro de otro ámbito.

## Preflight y backup fail-closed

- `dryRun` valida el modo de errores PDO, integridad sintáctica de las cinco
  DDL aditivas y calcula SHA-256. **Nunca ejecuta SQL de escritura**.
- `apply` consulta primero `BackupGate::evaluate('migration.registry.reconcile',
  scope, receipt, writeAt)`; sin recibo, con scope distinto, caducado o
  inutilizable, aborta **antes de ejecutar incluso la migración 001**.
- Tras pasar la puerta, llama al `SchemaMigrator::apply` preexistente para
  asegurar `001_decisions.sql` y después aplica la 002 íntegra desde fuente
  versionada, volviendo a comprobar SHA-256. `001` permanece sin modificar.
- SQLite ejecuta toda la operación dentro de una transacción y hace rollback
  ante errores. **MariaDB realiza autocommit de DDL**: no se promete rollback
  transaccional; para cualquier ejecución real se exige snapshot auténtico,
  autorización OWNER, D-059, comprobación de restore y operación independiente
  de este PR. Un recibo *estructuralmente válido* no acredita por sí solo
  autenticidad del proveedor ni restaurabilidad.
- No hay aplicación implícita desde un router, cron o boot. No se accede a
  producción, secretos, datos personales ni servicios externos.

## Pruebas y límites

`python3 -m unittest -v tests.test_domain_persistence` cubre los métodos
exactos `PersistenceTests.test_criterio_principal` y
`PersistenceTests.test_criterio_seguridad_y_bordes`: base SQLite vacía/001,
dry-run sin efectos, 002 idempotente, round-trip real PHP/PDO de las cinco
entidades, aislamiento entre dos scopes, entrada inválida, JSON malformado,
recibo de backup ausente/cruzado/vencido y rollback de trigger SQLite. Los
recibos de tests son exclusivamente **sintéticos** y no autorizan migraciones
reales. El único criterio de fusión es CI canónico en HEAD exacto y puertas
PLAN-AGENTES; migración productiva permanece **NO GO**.

**Reversión:** revertir el squash elimina la capacidad nueva; para un despliegue
futuro no existe `DOWN` destructivo, por seguridad. Los datos preexistentes de
001 no son modificados por el cambio de código hasta que un operador autorizado
aplique explícitamente `apply`.
