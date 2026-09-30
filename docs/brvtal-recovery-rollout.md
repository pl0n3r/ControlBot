# Rollout de Recovery para BRVTAL

Este slice aplica a BRVTAL el perfil institucional de Disaster Recovery de #182 mediante `RecoveryProfile` (#236). Es una adopción declarativa y read-only: no afirma que BRVTAL ya tenga Recovery Health `HEALTHY` ni que exista evidencia canónica de backup/restore.

## Perfil

Targets iniciales: RPO de 15 minutos, RTO de 60 minutos, retención de 24 copias horarias, 7 diarias, 8 semanales y 12 mensuales, con restore drill cada 168 horas. Las cuatro fuentes son `required`: database, media, repository y configuration. La estrategia exige 3-2-1-1-0 y cifrado.

El `source_ref` identifica únicamente esta adopción de configuración en ControlBot. No es un receipt, checksum ni prueba de restauración.

## BRVTAL #389 como capability histórica

BRVTAL #389 incorporó capacidad de backup programado y un boundary opcional para Google Drive. Esa capacidad conserva valor operativo e histórico, pero su propio alcance no demuestra:

- primary offsite immutable live;
- `BackupReceipt` canónico;
- copia inmutable verificada;
- restore drill canónico;
- RPO observado;
- RTO demostrado.

Google Drive permanece como capability opcional para copia externa cifrada. No se promueve a primary runtime storage ni a evidencia canónica de Recovery.

## Bloqueo de proveedor #470

ControlBot #470 mantiene pendiente la selección del object storage primario inmutable para Recovery live. Este rollout no elige proveedor, no autoriza gasto y no fabrica un `storage_ref` mientras esa decisión permanezca abierta.

La ausencia de esa decisión y de receipts reales conserva el estado fail-closed:

- canonical backup receipt: `unknown`;
- offsite copy: `unknown`;
- immutable copy: `unknown`;
- canonical restore drill: `unknown`;
- observed RPO: `unknown`;
- demonstrated RTO: `unknown`;
- DR status: `UNKNOWN`;
- restorable: `false`.

`profile_status=configured` significa únicamente que la política declarada pasa `RecoveryProfile`.

## Fronteras

Este slice no ejecuta backups ni restores, no autentica Google Drive, no toca Hostinger o BRVTAL producción, no accede a DB/media, no hace uploads, no modifica scheduler/cron, no llama providers y no genera receipts sintéticos.

Tampoco contiene secretos, OAuth tokens, provider URLs, storage refs, checksums de datos ni identificadores de backups/restores.

La evidencia operativa futura deberá emitirse por los contratos canónicos de Recovery y solo entonces podrá alimentar `RecoveryEvidence` (#257) y `RecoveryDrillProjection` (#271).
