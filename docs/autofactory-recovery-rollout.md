# Rollout de Recovery para AutoFactory

Este slice adopta RecoveryProfile para AutoFactory como configuración declarativa y read-only. No acredita Recovery Health HEALTHY, no ejecuta backups/restores y no cambia AutoFactory ni su runtime.

## Perfil institucional

AutoFactory usa los mismos objetivos declarativos ya adoptados por los demás perfiles ControlBot: RPO 15 minutos, RTO 60 minutos, retención 24 hourly / 7 daily / 8 weekly / 12 monthly y restore drill cada 168 horas. La estrategia exige 3-2-1-1-0 y cifrado.

Por la arquitectura vigente de AutoFactory, solo repository y configuration son fuentes requeridas. database y media son not_applicable: el bridge aislado no opera una base de datos ni media de producto.

profile_status=configured significa únicamente que esta política pasa el contrato RecoveryProfile. No demuestra que exista una copia offsite, inmutable o restaurable.

## Estado operativo fail-closed

No existe evidencia canónica que permita afirmar backup, copia offsite, inmutabilidad o restore drill de AutoFactory. RPO observado y RTO demostrado permanecen desconocidos; el estado DR sigue UNKNOWN y restorable=false.

La decisión vigente de no provisionar todavía B2/live continúa intacta. D-061 también sigue bloqueando el runtime real de Factory Control. Este rollout no levanta ninguna de esas puertas.

## Fronteras

No se provisionan providers, buckets, credenciales o secrets. No se crean receipts, checksums ni referencias de almacenamiento. No se hace red, browser automation, I/O externo, DB, scheduler, cron, uploads, pairing ni tratamiento de contenido de chats.

La evidencia operativa futura debe llegar por los contratos canónicos de Recovery y solo entonces podrá proyectarse a Recovery Health/Readiness. La configuración declarativa no sustituye esa evidencia.
