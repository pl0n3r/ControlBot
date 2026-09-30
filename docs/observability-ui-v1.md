# Observability UI v1

Superficie read-only del parent #16 para mostrar evidencia del vigilante y recuperación sin ejecutar ninguna acción.

## Fuentes canónicas

- `ExternalMonitorCore` determina `application_state`, probe y `alert_intent`.
- `ControlBotBackup::backupReceipt()` valida el último backup.
- `ControlBotBackup::restoreReceipt()` valida el restore drill aislado.
- `ControlBotBackup::restorable()` es la única fuente del estado `restorable`.

La UI no recalcula salud, no ejecuta alertas, no corre backup/restore ni genera retention plans.

## Minimización

Se muestran estado, timestamps, HTTP/latencia, versión, SHA abreviado, IDs de backup/restore, tamaño, cifrado, entorno aislado y estado de restaurabilidad. No se muestran `storage_ref`, checksum completo, evidence refs, URLs, payloads, tokens, cookies, credenciales ni secretos.

Ausencia de monitor, probe, backup o restore se representa como `unknown` o `sin evidencia`; nunca se convierte en healthy/restorable.

## UX

HTML server-side, mobile-first, viewport explícito, foco visible, reduced-motion y sin forms, inputs o botones. La tarjeta de alerta solo proyecta si el canal externo es requerido; el transporte permanece fuera de la UI.

## Reversión

Cuatro rutas aditivas, sin DB, red, filesystem write, migraciones ni side effects. Revertirlas elimina la vista y sus pruebas sin afectar ExternalMonitorCore, backup/restore ni el cron externo.
