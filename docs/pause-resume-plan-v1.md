# PauseResumePlan v1

`PauseResumePlan` demuestra que liberar una pausa no crea trabajo nuevo ni reinicia un intento.

## Fuentes canónicas

- `PauseControl::state()` valida el PauseState original y el liberado.
- `SchedulerCore::workItem()` valida WorkItem/generation/attempt/ownership.
- `RunnerGateway::order()` valida la ExecutionOrder vigente y `orderFingerprint()` liga su contenido.

El PauseState liberado debe conservar exactamente identidad y provenance del estado activo/releasing previo; solo cambia a `released` y obtiene `released_at`.

## Resume

Solo se planifica sobre WorkItem `assigned|running|review` con reservation y owner vigentes. WorkItem y ExecutionOrder deben coincidir en `work_item_id`, `generation` y `attempt`. El plan conserva también `issued_at/expires_at` para poder recomputar y verificar el fingerprint de la orden completa durante replay.

La salida preserva los IDs y datos de la orden actual y declara:

- `create_work_item=false`;
- `create_order=false`;
- `reuse_current_attempt=true`;
- `requires_existing_authority=true`.

Por tanto, resume no reencola, no reasigna, no incrementa generation/attempt y no concede autoridad.

## Idempotencia

`assertReplay()` acepta solo el mismo plan normalizado. El mismo resume key con cualquier drift de payload/fingerprint falla cerrado.

## Límites

Sin DB, red, filesystem write, shell, Scheduler selection, requeue ni ejecución Runner. La aplicación efectiva del resume pertenece a un adapter posterior. Reversión: eliminar las cuatro rutas del slice.
