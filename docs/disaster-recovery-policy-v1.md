# DisasterRecoveryPolicy v1

Contrato puro de AEGIS para describir recovery por proyecto sin crear backups, restaurar datos, autenticar proveedores ni almacenar credenciales.

## Contrato
Declara `project_id`, RPO/RTO positivos, retención `recent|daily|weekly|monthly`, estrategias separadas para `database|media|code|secrets`, capacidades de offsite/inmutabilidad/checksum/freshness/restore drill, destinos y referencias opacas. Secretos usa únicamente `vault_reference_only`.

## Fail-closed
Evidencia ausente es `unknown`; stale o incompleta nunca es `healthy`; `blocked` permanece bloqueado. Solo evidencia fresh, completa y reportada healthy queda healthy.

Google Drive solo puede declararse `offsite_encrypted_copy`. iCloud no puede ser `primary_runtime_storage` ni `server_automation_dependency`. Estas reglas no ejecutan llamadas a proveedores.

## Determinismo y límites
`fingerprint()` normaliza mapas/listas permitidas y produce SHA-256 estable. Campos extra, duplicados, rangos inválidos y material sensible fallan cerrado.

No hay red, DB, filesystem write, scheduler mutation, cifrado real, backup/restore, traffic switching, persistencia ni UI. La ejecución operativa pertenece a slices posteriores bajo authority/policy.
