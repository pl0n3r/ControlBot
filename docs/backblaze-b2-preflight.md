# Preparación AEGIS / Backblaze B2 — preflight solo offline

**Autoridad:** la opción A autenticada de [#858](https://github.com/pl0n3r/ControlBot/issues/858#issuecomment-6387355246) solo permite preparación limitada para [#182](https://github.com/pl0n3r/ControlBot/issues/182). No es autorización de proveedor, coste, datos, escrituras, backup, restore, despliegue ni go-live.

BackblazeB2Preflight::assess evalúa solamente una proyección **saneada y sintética** de los metadatos retornables por b2_list_buckets. Nunca realiza GET ni se conecta al proveedor. No acepta nombres o IDs de buckets, claves, tokens, URL, headers, credenciales o payloads. No escribe en disco ni en GitHub.

## Contrato offline y lectura autorizada posterior

- Un bucket de backup debe ser allPrivate, con los metadatos de fileLockConfiguration legibles mediante readBucketRetentions, isFileLockEnabled=true y una retención predeterminada explícita governance/compliance y período positivo.
- Los metadatos de defaultServerSideEncryption deben ser legibles con readBucketEncryption y declarar mode=SSE-B2. Esto describe **configuración**, no prueba cifrado de un archivo ni de copias históricas.
- Si la API oculta los valores por falta de permiso, el preflight da incomplete, no pasa por prueba de Object Lock. Si Object Lock está habilitado pero el modo/período predeterminado son nulos, informa default_retention_missing: habilitar la capacidad del bucket no hace inmutables los archivos sin retención.
- Los únicos resultados del contrato son configuration_status=consistent|incomplete, operational_status=UNKNOWN y códigos de razón allowlisted sin argumentos originales. **Incluso consistent mantiene UNKNOWN.**

**Para un preflight real** faltan operador autorizado, canal privado de acceso al proveedor y capacidades de lectura vigentes. Ningún fixture, check de CI o PR acredita un bucket B2, versionado, inmutabilidad de **objetos almacenados**, un BackupReceipt, verificación de checksum, freshness, un restore descartable o RPO/RTO medidos. Factory/FactoryRunner deben aportar esa evidencia por el canal oficial cuando exista la autoridad independiente. Está prohibido activar Object Lock, retención, credenciales o endpoints por inferencia; habilitar Object Lock puede tener consecuencias irreversibles en la política del bucket.

## Fuentes técnicas

- [Backblaze Native API b2_list_buckets](https://www.backblaze.com/apidocs/b2-list-buckets): fileLockConfiguration y defaultServerSideEncryption y sus puertas de lectura.
- [Backblaze Object Lock Native API](https://www.backblaze.com/docs/cloud-storage-enable-object-lock-with-the-native-api): diferencia entre flag de bucket y período predeterminado; permisos readBucketRetentions.
- [Backblaze Object Lock](https://www.backblaze.com/docs/cloud-storage-object-lock): protección aplicada a archivos/versiones, diferenciación governance/compliance.

## Ejecución y reversión

Comprobación determinista sin red: python3 -m unittest tests.test_b2_recovery_preflight -v; solo ejecuta php con fixtures falsos. Revertir este slice elimina evaluador, pruebas y runbook sin cambios a sistemas de backups ni perfiles existentes. El parent #182 continúa bloqueado para criterios operativos hasta disponer de receipts auténticos.
