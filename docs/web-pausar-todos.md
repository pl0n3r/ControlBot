# Pausar todos — control owner web (issue #1452)

## Implementación y autoridad

Este slice se prepara solo en construcción, con fixtures sintéticos. La dependencia #1445 ya incorporó `OwnerAuthenticatedRouter` y módulos en `src/Routing/Routes/`; ese registro sirve exclusivamente rutas `/api/v1/*` después de verificar sesión OAuth + TOTP del dueño. Una cabecera `REMOTE_USER` o un login local de contraseña no confieren acceso a esa API.

El control expone `GET /api/v1/pausa` para consultar el estado global y `POST /api/v1/pausa` para las acciones `pause` y `resume`. Toda escritura requiere CSRF en `X-CSRF-Token`, JSON estricto, confirmación explícita, backend server-bound y comprobación CAS de snapshot; una respuesta no certificada se trata como 503, nunca como éxito. Se reutilizan `PauseControl::state/effective/release` y `PauseTransitionAudit::event/append`; el adaptador debe persistir estado y bitácora **de manera atómica**.

La página privada literal `GET /pausa` se sirve desde `ControlBotWebEntrypoint::handleAuthenticated` únicamente tras verificar OAuth + TOTP del dueño. El claim extra `src/ControlBotWebEntrypoint.php` fue incorporado mediante renovación V3 autenticada `de937637-0001-5fd7-971c-d10abeb6a482`. La interfaz llama `GET/POST /api/v1/pausa` con `credentials=same-origin`, token CSRF server-issued y confirmación explícita. El login local sin OAuth/TOTP nunca habilita esta página.

## Criterio de menos de 30 segundos

El scheduler que vaya a asignar trabajo **nuevo** debe consultar la misma fuente global versionada antes de confirmar cada asignación, aplicar el estado `global / active` a `SchedulerCore::readiness` como `freeze_state=active` y demostrar visibilidad antes del segundo 30. La ruta requiere confirmación del adaptador de que no hubo asignaciones nuevas después de pausar; ni el mero POST ni el check en memoria de un test constituyen observación de un scheduler remoto. El estado actual de WorkItems `running` se conserva al pausar y al reanudar; el resume nunca crea un WorkItem ni ExecutionOrder.

## Límites y regresión

`tests/test_web_pause_all.py::PauseTests` cubre flujo sintético, sesión owner, CSRF, confirmación, transiciones y bitácora, CAS, SLA simulado y ausencia de nuevas asignaciones. Sin datos reales, despliegue, gastos, secretos, proveedores ni cambio en producción. Reversión: revert del squash; cualquier integración productiva futura necesita su propio gate de autoridad y evidencia terminal.

## Contrato mínimo del adaptador server-bound

`pause.global.snapshot` devuelve exactamente `revision`, `pause` (PauseState v1 o null), `audit` (lista de PauseAuditEvent v1), `active_work` (ids de trabajo ya en curso) y `actor` derivado de la sesión confiable. `pause.global.commit` debe validar `expected_revision` y `expected_sha256`, realizar **en una sola transacción** el cambio de estado y el append de auditoría, preservar `active_work`, y responder con `committed=true`, `revision` incrementada, `pause`, `audit`, `active_work`, `new_assignments=0` y `scheduler_observed_at` entre el inicio y el segundo 29. La ausencia de evidencia responde 503. Nunca aceptar autoridad, estado inicial o contexto por JSON del cliente.

Esta implementación **no proporciona por sí sola** un adaptador persistente/live ni observa el scheduler remoto: con `ownerApiBackend=null`, el router responde `503 source_unavailable`. El criterio de los 30 segundos se acredita **solo en el fixture determinista**. Integración productiva del scheduler/almacenamiento requiere su propia autorización y verificación, no se infiere del PR. No se modifica ninguna tarea activa, ni hay cancelación/reasignación o creación automática de órdenes durante resume.
