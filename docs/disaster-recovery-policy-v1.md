# DisasterRecoveryPolicy v1

## Objetivo

`DisasterRecoveryPolicy` es el contrato puro de AEGIS para describir objetivos y
restricciones de recuperación por proyecto. No crea backups, no restaura datos, no
elige proveedores comerciales y no contiene credenciales.

## Contrato

Una policy v1 declara de forma cerrada:

- `project_id`;
- `rpo_seconds` y `rto_seconds` positivos;
- retención `recent|daily|weekly|monthly`;
- estrategias separadas para `database|media|code|secrets`;
- capacidades explícitas: offsite, versionado/inmutabilidad, checksum, freshness y restore drill;
- destinos declarativos;
- `policy_ref` ligado al proyecto y referencias opacas de procedencia.

Los secretos nunca son contenido de backup dentro de este contrato. La única estrategia
admitida para el componente `secrets` es `vault_reference_only`.

## Evidencia fail-closed

`evidenceStatus()` solo produce `healthy|degraded|unknown|blocked`.

- evidencia ausente → `unknown`;
- evidencia stale → nunca `healthy`;
- evidencia incompleta → nunca `healthy`;
- `blocked` permanece `blocked`;
- solo evidencia `fresh`, completa y reportada `healthy` puede quedar `healthy`.

La policy no reemplaza `RecoveryEvidence`, `RecoveryProfile`, receipts de backup ni
restore drills. Define únicamente la semántica base para que esas evidencias puedan
ser evaluadas sin inventar estado verde.

## Destinos

Google Drive, si se declara, solo puede aparecer con el rol
`offsite_encrypted_copy`. No se considera primary runtime storage.

iCloud no puede declararse como `primary_runtime_storage` ni
`server_automation_dependency`.

Estas reglas no provisionan ni autentican ningún proveedor.

## Determinismo

`fingerprint()` normaliza mapas, destinos y referencias antes de serializar. Dos
policies semánticamente equivalentes producen el mismo SHA-256 aunque cambie el
orden permitido de mapas o listas.

Duplicados, campos extra, rangos inválidos, referencias sensibles, credenciales y
estrategias que impliquen backup de secretos en claro fallan cerrado.

## Frontera de side effects

El módulo no contiene:

- red ni provider calls;
- acceso DB;
- filesystem write;
- scheduler mutation;
- cifrado real;
- creación de backups;
- restore real;
- traffic switching;
- UI.

La ejecución operativa de disaster recovery pertenece a slices posteriores de AEGIS
y Factory, bajo authority/policy explícitas.
