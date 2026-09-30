# Rollout de Recovery para ControlBot

Este slice aplica a ControlBot el perfil institucional de Disaster Recovery de #182 usando `RecoveryProfile` (#236). Es un rollout de **configuración/read-only** para la fase de construcción; no es evidencia de que exista una recuperación operativa completa.

## Perfil aplicado

ControlBot declara RPO objetivo de 15 minutos, RTO objetivo de 60 minutos, retención de 24 copias horarias, 7 diarias, 8 semanales y 12 mensuales, y un restore drill cada 168 horas. Database, repository y configuration son fuentes requeridas; media es `not_applicable`. La estrategia exige 3-2-1-1-0 y cifrado.

La configuración usa un `source_ref` de GitHub como provenance del rollout. No contiene `storage_ref`, credenciales, secretos, checksums de artefactos ni receipts sintéticos.

## Estado operativo fail-closed

`profile_status=configured` significa únicamente que la política fue declarada y pasa el contrato de `RecoveryProfile`. Mientras este slice no reciba evidencia operativa real, los estados siguen siendo:

- backup evidence: `unknown`;
- offsite copy: `unknown`;
- immutable copy: `unknown`;
- restore drill: `unknown`;
- DR status: `UNKNOWN`;
- restorable: `false`.

Por diseño, **configurado no significa HEALTHY**. Este slice no inventa un `BackupReceipt`, un restore exitoso, una copia offsite o RPO/RTO observados.

## Guardia de construcción

El test de rollout exige que `datos.yml` conserve `phase=construccion`, `d063_attestation.nothing_live=true` y `d063_attestation.no_real_customer_data=true`. Si cualquiera cambia, la aceptación falla cerrado: antes de operar live o con datos reales debe existir un rollout operativo separado con receipts reales, copia offsite/versionada/inmutable donde aplique y restore drill en target descartable.

## Fronteras

No ejecuta backups ni restores. Tampoco hace red, DB, scheduler, cron, uploads, provider writes, cambios en Hostinger ni creación de WorkItems. `RecoveryEvidence` (#257), `RecoveryDrillProjection` (#271), `ControlBotBackup` (#399) y las autoridades de Factory/FactoryRunner siguen siendo las fronteras para evidencia y ejecución reales.

La reversión es eliminar estas cuatro rutas nuevas; no hay migraciones, persistencia ni estado externo.
