# Pausar todos — control owner web (issue #1452)

## Implementación y autoridad

Este slice se prepara solo en construcción, con fixtures sintéticos. La dependencia #1445 ya incorporó `OwnerAuthenticatedRouter` y módulos en `src/Routing/Routes/`; ese registro sirve exclusivamente rutas `/api/v1/*` después de verificar sesión OAuth + TOTP del dueño. Una cabecera `REMOTE_USER` o un login local de contraseña no confieren acceso a esa API.

El control expone `GET /api/v1/pausa` para consultar el estado global y `POST /api/v1/pausa` para las acciones `pause` y `resume`. Toda escritura requiere CSRF en `X-CSRF-Token`, JSON estricto, confirmación explícita, backend server-bound y comprobación CAS de snapshot; una respuesta no certificada se trata como 503, nunca como éxito. Se reutilizan `PauseControl::state/effective/release` y `PauseTransitionAudit::event/append`; el adaptador debe persistir estado y bitácora **de manera atómica**.

La página privada literal `GET /pausa` necesita una delegación adicional del front controller `src/ControlBotWebEntrypoint.php`, fuera de los tres claims originales. No puede darse por implementada por la simple presencia de un módulo `Routes/PauseRoutes.php`; antes de modificar el front controller se exige una renovación V3 autenticada que incorpore ese claim.

## Criterio de menos de 30 segundos

El scheduler que vaya a asignar trabajo **nuevo** debe consultar la misma fuente global versionada antes de confirmar cada asignación, aplicar el estado `global / active` a `SchedulerCore::readiness` como `freeze_state=active` y demostrar visibilidad antes del segundo 30. La ruta requiere confirmación del adaptador de que no hubo asignaciones nuevas después de pausar; ni el mero POST ni el check en memoria de un test constituyen observación de un scheduler remoto. El estado actual de WorkItems `running` se conserva al pausar y al reanudar; el resume nunca crea un WorkItem ni ExecutionOrder.

## Límites y regresión

`tests/test_web_pause_all.py::PauseTests` cubre flujo sintético, sesión owner, CSRF, confirmación, transiciones y bitácora, CAS, SLA simulado y ausencia de nuevas asignaciones. Sin datos reales, despliegue, gastos, secretos, proveedores ni cambio en producción. Reversión: revert del squash; cualquier integración productiva futura necesita su propio gate de autoridad y evidencia terminal.
