# DisasterRecoveryPolicy v1

Contrato puro de AEGIS para recovery por proyecto. Declara `project_id`, RPO/RTO positivos, retención `recent|daily|weekly|monthly`, estrategias separadas para `database|media|code|secrets`, capacidades de offsite/versionado/checksum/freshness/restore drill, destinos y referencias opacas.

La estrategia de secretos es únicamente `vault_reference_only`: este módulo no admite credenciales ni secretos planos. Evidencia ausente devuelve `unknown`; stale o incompleta nunca queda `healthy`; `blocked` permanece bloqueado.

Google Drive solo puede declararse `offsite_encrypted_copy`. iCloud no puede ser `primary_runtime_storage` ni `server_automation_dependency`.

`normalize()` rechaza campos extra, duplicados, rangos inválidos y material sensible. `fingerprint()` usa la policy normalizada, por lo que entradas equivalentes bajo reordenamiento permitido producen el mismo SHA-256.

No hay red, DB, filesystem write, providers, scheduler mutation, backup/restore real, traffic switching ni UI. La ejecución operativa pertenece a slices posteriores.
