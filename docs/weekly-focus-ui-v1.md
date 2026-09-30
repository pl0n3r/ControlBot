# Weekly Focus UI v1

`WeeklyFocusUi` proyecta una superficie privada accesible sobre `WeeklyFocus v1`; no reimplementa ranking ni muta Scheduler.

## Interacción

- Desktop expone drag/reorder y mobile/teclado siempre ofrece acciones equivalentes `move_up` / `move_down` con foco visible.
- Items `unavailable` permanecen visibles con razón canónica y `dispatchable=false`.
- El guardado optimista envía únicamente `ordered_refs + expected_version`; una versión stale devuelve `conflict`, exige refresh/reconcile y nunca autoriza overwrite.
- `clear_focus` envía una lista vacía para futuras selecciones y declara `affects_running_work=false`.

## Frontera

No hay DB, providers, red, filesystem writes, pause, requeue, preemption ni prioridad paralela. La validación de refs/versiones se apoya en `WeeklyFocus::normalize()` y la persistencia real queda fuera de este slice.
