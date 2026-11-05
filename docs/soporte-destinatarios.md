# Destinatarios de avisos y guardia — ControlBot #1428

**Estado:** implementación de backend y pruebas offline, sin despliegue ni envío real. La rama V3 autoriza exclusivamente este documento, el módulo PHP y su suite. El router web existente no está reclamado: conectar la pantalla/ruta efectiva requiere una hoja posterior con claims propios. La presencia de `handle()` no acredita accesibilidad en producción.

## Modelo de configuración

Los tipos admitidos son `incident` (incidente), `availability_down` (caída), `backup_failure` (fallo de respaldo), `owner_decision` (decisión del dueño) y `usage_limit` (límite de uso). Cada tipo tiene de cero a diez destinatarios con `email` y `order`, numerados consecutivamente desde 1. Un tipo vacío no constituye un canal activo. No se aceptan otros tipos ni campos adicionales.

Las guardias son turnos con días de semana `days` (0=domingo, 6=sábado), `start`, `end` (HH:MM), y correo `email`. La zona horaria es exclusivamente `America/Bogota`. Cada turno cumple inicio anterior al fin, sin solapamientos el mismo día y con máximo de 28 entradas. Para un turno que cruce la medianoche, crear dos intervalos en días consecutivos; nunca inferir horarios.

Ejemplo ficticio (dominio reservado, no son destinatarios reales):

```json
{
  "recipients": {
    "incident": [{"email":"alerts@example.invalid","order":1}],
    "availability_down": [],
    "backup_failure": [],
    "owner_decision": [],
    "usage_limit": []
  },
  "shifts": [{"days":[1,2,3,4,5],"start":"09:00","end":"17:00","email":"guard@example.invalid"}]
}
```

## Acceso seguro y contrato del adaptador

`ControlBot\Support\SupportNotificationRecipients::handle($server, $input, &$session, $authConfig, $privateDirectory, $now)` acepta solamente `GET` o `POST` sobre `/support-notifications` y responde JSON sin caché. Reutiliza el `OwnerLoginGate::requireOwner` de ControlBot, que valida sesión local y revisión de revocación en `OwnerLocalAuthStore`. Nunca se admite un `owner=true` remitido por el navegador. Sin una sesión real del propietario, la lectura y edición fallan cerradas; el módulo no crea sesiones autenticadas.

Un GET owner sin entrada devuelve `version`, `revision`, `settings`, `audit` y un token CSRF aleatorio de 256 bits en la sesión. Un POST debe contener exactamente `csrf`, `expected_revision` (entero no negativo) y `settings`; exige CSRF exacto y revisión vigente, y rota el token tras cada éxito. Errores: 403 owner/CSRF, 409 revisión obsoleta, 422 esquema y 503 almacenamiento privado. Prohibido usar esta operación desde GET, eventos privilegiados o sin sesión autenticada.

## Persistencia privada y auditoría

El adaptador debe pasar un directorio absoluto y escribible del servidor **fuera del árbol Git/web**. Se prohíben carpetas dentro del repositorio y symlinks explícitos. Los archivos privados `cb-support-notifications.json` y `cb-support-notifications.lock` se protegen con permisos 0600. Todas las lecturas y escrituras usan `flock`; el estado de configuración y el evento de auditoría se guardan juntos mediante escritura a temporal, `fflush/fsync` y `rename` atómico bajo exclusión mutua. Fallos de escritura o de disco bloquean la modificación sin eliminar el último estado válido.

Por modificación se añaden a un log append-only el propietario autenticado, fecha UTC, revisión, tipos/campos cambiados y hashes SHA-256 de los ajustes anterior y posterior. **El evento no duplica direcciones de correo**. No se purga el historial automáticamente: con 2048 eventos o 1 MiB el módulo se cierra para nuevas escrituras hasta tener una política de retención aprobada. El dueño debe aprobar finalidad, información a destinatarios, retención y borrado antes del uso real; ningún correo real debe aparecer en Git, logs, tests ni PRs.

## Simulación sin envío

Un GET owner con `action=simulate`, `type` y `at_utc` (epoch UTC en cadena numérica) convierte la fecha a `America/Bogota`. Si hay guardia en esa hora, esta ocupa la primera posición; siguen los destinatarios del tipo, en su orden, sin duplicados. El resultado contiene `recipients`, `order`, `role`, `status: preview|unconfigured` y `sent: false`. No existe integración con mail, SMS, push, colas, DNS ni proveedores, y el GET no genera auditoría ni muta la configuración.

## Aceptación ejecutable y límites

- **AC-01:** `tests/test_support_notification_recipients.py::SupportNotificationRecipientsTests::test_config_roundtrip_and_validation` ejecuta PHP real sobre configuración privada temporal, lectura posterior, revisiones obsoletas y rechazos de correo, turnos y esquema.
- **AC-02:** `...::test_owner_only_csrf_and_audit` comprueba `OwnerLoginGate` real, sesión revocada, CSRF incorrecto/reutilizado y bitácora sin datos personales duplicados.
- **AC-03:** `...::test_routing_simulation_sends_nothing` prueba fechas/guardia y escalamiento, no entrega, rechazo de tipo desconocido y ausencia de escritura.
- **AC-04:** `CI Factory v1 / Validar` terminal `success` sobre HEAD exacto, además de CodeQL/SonarCloud y revisión CodeRabbit FINAL según D-069/070. El CI existente ejecuta archivos `test_*.py` con `scripts/generate_coverage.py`; no atribuirle resultados sin comprobar logs del SHA.

Roles: ingeniería de software (módulo acotado), seguridad (owner, CSRF, validación y atomicidad), QA (fixtures negativos), legal-privacidad (minimización y retención pendiente de autorización), producto (prioridad configurable). **Fuera de alcance:** conectar dashboard o registrar ruta pública, cambiar autenticación general, enviar mensajes, contratar proveedores, gastos, migración productiva, secretos, PII reales, despliegues, go-live y programación de tareas. Para completar la pantalla real, crear un issue posterior con claim explícito sobre el router/cliente y pruebas HTTP/E2E de uso humano.
