# Vista del plan del scheduler — ControlBot #1450

## Contrato HTTP (solo lectura)

`GET /api/v1/scheduler` (ruta lógica `/scheduler` dentro del registro owner-v1 de #1445) se atiende
exclusivamente después de la verificación de la sesión **OAuth + TOTP** del
dueño. Una sesión local de contraseña no concede autoridad owner-v1.
El router responde JSON y siempre `Cache-Control: no-store`.
La ruta no acepta POST ni mutaciones.

Sin sesión válida: `403`. Sin backend servidor o ante datos inválidos:
`503` con error genérico. La ruta no intenta consultar GitHub,
crear reservas, ejecutar selecciones ni escribir un plan persistente.

La URL pública literal `/scheduler` no está registrada por el
front controller heredado: este issue tiene exclusivamente el claim
`src/Routing/Routes/SchedulerRoutes.php`, y el contrato de #1445
registra módulos bajo el prefijo `/api/v1/`. No se altera el
router central ni se anuncia un alias web inexistente.

## Origen de datos

El adaptador **servidor** opcional de
`ControlBotWebEntrypoint::handleAuthenticated(..., $ownerApiBackend)`
provee `scheduler.plan.read`; ninguna cabecera, cookie o campo GET elige
el adaptador. Sin wiring de un origen confiable configurado en producción,
la API permanece **503 fail-closed** (no inventa cuentas, proyectos ni trabajos).

Snapshot puro, versión 1:

```json
{
  "version": 1,
  "work_items": [
    {
      "work_item": {"...": "SchedulerCore::workItem schema"},
      "context": {"...": "SchedulerCore::readiness context"}
    }
  ]
}
```

El módulo valida cada WorkItem y su contexto con `SchedulerCore::candidate`;
`SchedulerSelection::request` normaliza el conjunto y calcula una
huella reproducible **sin seleccionar trabajo ni asignar una sesión**.
El payload incluye:

- `policy_ref=factory-dispatcher-v2` y `mode=read_only`.
- Prioridad, estado, dependencia y motivos de readiness de cada item.
- `reservation.held` y generación de la reserva, nunca el token o identidad de sesión.
- Conteos de candidatos listos/bloqueados y huella del request de selección.
- `assignment_committed=false`: no hubo asignación, reserva, CAS ni escritura.

Una respuesta con lista vacía es válida, pero **no** acredita
disponibilidad de capacidad ni readiness de producción.

## Pruebas y reversión

```sh
python3 -m unittest -v tests.test_web_scheduler
python3 -m unittest -v tests.test_owner_routes_registry
php -l src/Routing/Routes/SchedulerRoutes.php
```

Los tests usan sesiones sintéticas y backend en memoria. Verifican
múltiples prioridades y dependencias, bloqueo con reserva, orden/huella
deterministas, respuesta JSON autenticada, rechazo de anónimos/POST,
fuente ausente o inconsistente y **cero operaciones de escritura**.

Revertir el commit squash elimina solamente el archivo del registro,
las pruebas y esta documentación. No hay cambios de DB, secrets,
deploy, Hostinger ni go-live. La conexión a datos reales queda fuera
de alcance bajo D-059.
