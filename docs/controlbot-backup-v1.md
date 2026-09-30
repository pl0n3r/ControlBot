# ControlBot Backup v1

## Propósito
Slice puro de #16 para demostrar backup verificable, restore drill aislado y retención segura sin ejecutar I/O real. Complementa #48: no emite Production Authority ni convierte un receipt en permiso de restore.

## Contratos
- `BackupReceipt`: scope project/environment/resource, timestamps, checksum SHA-256, tamaño, storage_ref opaco, cifrado y status completed.
- `RestoreReceipt`: liga un backup a un target no productivo, exige checksum destino = origen y evidencia opaca.
- `restorable`: solo true con backup válido + restore drill `passed` posterior al backup y checksum equivalente.
- `retentionPlan`: devuelve únicamente un plan `keep/delete`; nunca borra. Conserva el último restorable por scope y, si no existe evidencia restorable, conserva todo ese scope.

## Seguridad
URLs públicas/firmadas, tokens, cookies, DSN, credenciales y refs sensibles se rechazan. No hay DB, red, filesystem write, shell, cron, delete ni restore real.

## Reversión
El slice es aditivo en cuatro rutas. Revertir el PR elimina el core, escenarios, tests y esta documentación sin migraciones ni estado persistente.
