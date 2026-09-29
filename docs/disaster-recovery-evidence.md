# Disaster Recovery Evidence — ControlBot BACKUP_EVIDENCE

Este slice de #182 proyecta evidencia observada sobre el perfil de #236. No crea ni valida backups.

## Frontera canónica
- Factory #305 define Recovery Manifest, pipeline, restore drill y Recovery Health.
- FactoryRunner ejecuta adapters/jobs y produce evidencia técnica.
- BackupReceipt es el productor nominal y conserva evidencia operativa para las puertas de escritura.
- RecoveryEvidence recibe estados y refs; no abre objetos y no valida el contenido del backup.
- AegisEvidence sigue siendo el consumer genérico de postura para slices posteriores.

ControlBot no crea un segundo motor de backup y este slice no calcula Recovery Health.

## Contrato v1
El envelope contiene project_ref, profile_ref, controles checksum/encryption/offsite/immutability y sources database/media/repository/configuration. Cada observación conserva reported_state, estado efectivo, evidence_ref, observed_at y freshness.

Solo evidencia fresh conserva verified/failed. stale se proyecta unknown; unknown no puede portar provenance que aparente verificación. La aplicabilidad viene de RecoveryProfile: not_applicable permanece explícito y un source required nunca puede autocertificarse así.

## Provenance de proyecto
RecoveryEvidence.project_ref, RecoveryProfile.project_ref y el proyecto del BackupReceipt nominal deben coincidir. No se infiere ownership parseando profile_ref, manifest_ref ni evidence_ref; strings o arrays autocertificados no sustituyen al productor nominal.

## Minimización y seguridad
Solo se aceptan refs ControlBot/GitHub permitidas. Digest crudo, backup payload, URL de provider/bucket, token, password, credential o key material fallan cerrado sin eco. Los cuatro sources aparecen exactamente una vez y se normalizan en orden canónico.

## Fuera de alcance
No calcula HEALTHY/DEGRADED/UNKNOWN/BLOCKED, RPO/RTO, drift, authority ni WorkItems. No ejecuta backup/restore, provider API ni FactoryRunner. Revertir estas cuatro rutas elimina la proyección sin tocar storage, DB, credenciales ni producción.
