# Rollout de Recovery para GrindFlow

Este slice aplica a GrindFlow el perfil institucional de Disaster Recovery de #182 mediante `RecoveryProfile` (#236). Es una adopción de política **read-only**: no demuestra que los datos o la media de GrindFlow ya sean recuperables.

## Perfil

Targets iniciales: RPO 15 minutos, RTO 60 minutos, retención 24 horarias / 7 diarias / 8 semanales / 12 mensuales y restore drill cada 168 horas. Las cuatro fuentes son `required`: database, media, repository y configuration. La estrategia exige 3-2-1-1-0 y cifrado.

El `source_ref` identifica únicamente este rollout de configuración. No es un receipt ni una prueba de backup.

## Límite con el deploy reversible existente

GrindFlow #175 integró el deploy reversible Factory y el contrato operativo documenta un backup del release `current` antes de activar otro artefacto. Esa capacidad protege el **release de código** y su rollback; no sustituye:

- backup consistente de MariaDB;
- backup/versioning de media;
- copia offsite e inmutable de esas fuentes;
- restore drill integral DB + media + release;
- RPO observado ni RTO demostrado.

Por ello este rollout no promueve esa capacidad a `RecoveryEvidence`, `RecoveryDrillProjection` ni `HEALTHY`.

## Estado fail-closed

Mientras no existan receipts/evidencia canónica reales:

- backup evidence: `unknown`;
- offsite copy: `unknown`;
- immutable copy: `unknown`;
- restore drill: `unknown`;
- observed RPO/RTO: `unknown`;
- DR status: `UNKNOWN`;
- restorable: `false`.

`profile_status=configured` significa solo “la política está declarada y validada”. No equivale a recuperación demostrada.

## Fronteras

Este slice no toca el repositorio ni la producción de GrindFlow. No ejecuta red, DB, Hostinger, uploads, providers, cron, scheduler, backups, restores, migraciones ni WorkItems. No contiene secretos, credenciales, provider URLs, storage refs, checksums de datos ni receipts sintéticos.

La siguiente fase operativa deberá producir evidencia real desde las fronteras Factory/FactoryRunner y entonces alimentar #257/#271 sin reinterpretar estados UNKNOWN como verdes.
