# Disaster Recovery Contract — ControlBot DR_CONTRACT

Este slice pertenece a ControlBot #182 y define solo la **proyección de gobierno por proyecto**. El contrato técnico de Recovery Manifest, adapters, pipeline y restore drill sigue siendo **Factory #305**. ControlBot no crea un segundo motor de backup.

## Límite arquitectónico

`RecoveryProfile` responde: **¿qué exige este proyecto y de qué manifest canónico proviene esa configuración?**

No responde: **¿este backup real es utilizable para una escritura o restore?** Esa autoridad permanece en `BackupReceipt` y `BackupGate`, junto con la evidencia que produzcan Factory/FactoryRunner. `RecoveryProfile` no valida un backup real, no abre red y no ejecuta provider writes.

## RecoveryProfile v1

Campos cerrados:

- `project_ref`: ref `controlbot:project/...`.
- `manifest_ref`: ref opaca `controlbot:recovery-manifest/...` que identifica la proyección del manifest gobernado por Factory #305.
- `targets.rpo_minutes` / `targets.rto_minutes`: objetivos explícitos, nunca garantías.
- `retention.hourly|daily|weekly|monthly`: política resumida por proyecto, sin defaults silenciosos.
- `sources.database|media|repository|configuration`: cada source es `required` o `not_applicable`.
- `strategy`: contrato 3-2-1-1-0 exacto: 3 copias, 2 medios, offsite requerido, copia inmutable requerida y 0 fallos de restore no detectados.
- `encryption_required=true`.
- `restore_drill_cadence_hours`: cadence explícita.
- `source_ref`, `observed_at`, `freshness`: provenance de la configuración.

Los valores de secretos nunca forman parte del perfil. Recuperar secretos pertenece a un canal/vault separado; ControlBot solo puede conservar referencias seguras en otras fronteras diseñadas para ello.

## Fail-closed

`freshness=fresh` con provenance completa puede proyectar `configured`.

`stale`, `unknown` o ausencia del perfil proyectan `unknown`. Un perfil `unknown` no puede llevar provenance que aparente observación, y un perfil `stale` debe conservar la provenance histórica que explica por qué quedó vencido.

`configured` describe únicamente **configuración vigente**. No significa `HEALTHY`, backup disponible, offsite verificado, restore probado ni RTO demostrado. Esas conclusiones pertenecen a los slices posteriores de evidencia/status.

## Provider neutrality

El dominio no contiene vendors ni APIs concretas. Object storage, cold-copy, DB snapshots y proveedores se resuelven detrás de Factory #305 / FactoryRunner. Cambiar de proveedor no cambia este contrato.

## Privacidad y datos

El perfil contiene metadata operativa de proyecto y refs técnicas, no payloads de negocio ni secretos. No añade una finalidad de datos personales, proveedor receptor ni dato sensible; por eso este slice no modifica `datos.yml`.

## Reversión

El slice es puro y sin estado externo. Revertir `RecoveryProfile.php`, sus tests y esta documentación elimina la proyección sin modificar backups, bases de datos, storage, manifests ni secretos existentes.


## Inventario de perfiles: cobertura declarativa (Factory / ControlBot #182)

La matriz `tests/test_recovery_profile_matrix.py` valida **políticas offline**, nunca
backup receipts. Los siete repositorios del grupo se representan así:

| Repositorio | Clave canónica | Perfil `config/recovery` | Estado |
| --- | --- | --- | --- |
| AutoFactory | `autofactory` | `autofactory.json` | Configurado, no HEALTHY |
| BRVTAL | `brvtal` | `brvtal.json` | Configurado, no HEALTHY |
| Condor | `condor` | Ninguno | UNKNOWN |
| ControlBot | `controlbot` | `controlbot.json` | Configurado, no HEALTHY |
| Factory | `factory` | `factory.json` | Configurado, no HEALTHY |
| FactoryRunner | `factoryrunner` | Ninguno | UNKNOWN |
| GrindFlow | `grindflow` | `grindflow.json` | Configurado, no HEALTHY |

**Condor y FactoryRunner** carecen de perfil en este repositorio: se consideran
`UNKNOWN` y no se les inventan objetivos RPO/RTO ni retenciones. Los cinco
perfiles existentes declaran RPO/RTO, retención, fuentes y requisitos de
cifrado, offsite e inmutabilidad. `configured` solo acredita configuración:
**No confirma backups**, Object Lock, checksums de una copia real, restore
drill, RPO observado ni RTO demostrado.

## Autoridad sucesora vigente: opción A, preparación sin live

La [decisión B histórica de #577](https://github.com/pl0n3r/ControlBot/issues/577)
es un antecedente: quedó sustituida **solo** para preparación acotada por la
[opción A del OWNER en #858](https://github.com/pl0n3r/ControlBot/issues/858#issuecomment-6387350597)
y el [journal `factory-human-decision` v2 autenticado, opción A](https://github.com/pl0n3r/ControlBot/issues/858#issuecomment-6387355246).
Preparar Backblaze B2 permite preflight read-only de versioning/Object Lock y
credenciales mínimas por canal privado con autorización real del proveedor.
Una prueba declarativa **no autoriza provisión** ni certifica bucket alguno.

La A no autoriza alta de cuenta, aceptar términos, pagos o gasto, credenciales
en GitHub/chat, uso de datos reales, escritura de backup, restore, destrucción
o go-live. Las decisiones de compra, privacidad y operación requieren
autoridad independiente.

ControlBot consume en lectura; Factory y FactoryRunner son productores de
evidencia genuina de checksum, cifrado, offsite, Object Lock, freshness y
restore descartable. Hasta tener receipts reales, se conserva `UNKNOWN`,
no HEALTHY. RPO/RTO observados siguen sin demostrarse. Condor y FactoryRunner
siguen sin perfiles locales y sus estados continúan UNKNOWN.
