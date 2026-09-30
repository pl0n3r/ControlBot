# Rollout de Recovery para Factory

Este slice aplica a Factory el perfil institucional de Disaster Recovery de #182 usando `RecoveryProfile` (#236). Es configuración de control y una reconciliación fail-closed de evidencia histórica; no declara que Factory tenga Recovery Health `HEALTHY`.

## Perfil aplicado

Factory declara como targets iniciales RPO de 15 minutos y RTO de 60 minutos, retención de 24 copias horarias, 7 diarias, 8 semanales y 12 mensuales, y restore drill cada 168 horas. `repository` y `configuration` son fuentes requeridas; `database` y `media` son `not_applicable`.

La estrategia exige 3-2-1-1-0 y cifrado. El `source_ref` del perfil identifica esta adopción en ControlBot; no es evidencia de un backup real.

## Evidencia histórica real de Factory #36

Factory #36 documentó un restore externo real del SHA `19580a32fe504e74f4941bd64cefcc5b5a77d07a`. El artefacto se materializó desde una copia persistente externa a GitHub, se restauraron 155 archivos en un directorio limpio y el manifiesto verificó 155/155 SHA256. La fuente pública de esa evidencia es el comentario `Factory#36#issuecomment-5813381932`.

Ese drill antecede a los contratos canónicos actuales. Por eso este rollout **no** crea retrospectivamente un `BackupReceipt`, `RecoveryEvidence` ni `RecoveryDrillProjection`. Tampoco infiere que la copia fuera inmutable o cifrada, ni calcula un RTO que no fue medido.

## Estado operativo fail-closed

`profile_status=configured` significa únicamente que la política declarada pasa `RecoveryProfile`. La evidencia histórica permite conservar `legacy_external_restore=verified` como provenance, pero el estado canónico permanece:

- canonical backup receipt: ausente;
- canonical recovery evidence: ausente;
- encryption evidence: `unknown`;
- immutability evidence: `unknown`;
- demonstrated RTO: `unknown`;
- canonical restore drill: `unknown`;
- DR status: `UNKNOWN`;
- restorable: `false`.

Por diseño, un restore histórico verificable no se promueve a `HEALTHY` sin las evidencias que exigen #257 y #271.

## Fronteras

Este slice es read-only. No ejecuta backups ni restores, no accede a red o DB, no hace uploads, no usa providers, no crea scheduler/cron ni WorkItems y no modifica Factory. Tampoco registra identificadores internos o rutas privadas del almacenamiento externo usado en #36.

La siguiente evidencia operativa compatible debe emitirse por las fronteras canónicas de Factory/FactoryRunner y entonces podrá ser consumida por `RecoveryEvidence` y `RecoveryDrillProjection`.
