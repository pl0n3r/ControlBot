# Canales de soporte por proyecto — contrato de ControlBot (#1358)

Estado: **contrato de construcción, no servicio HTTP/persistencia desplegado**. No implementación operativa: solo contrato offline y pruebas. La reserva del Issue permite únicamente este documento y su prueba. La integración de escritura, GET real, UI y migración requiere hojas posteriores con claims propios, sin afirmar que estén disponibles.

## Fuente única y ámbitos

ControlBot es la fuente canónica de la configuración de soporte para **Condor**, **GrindFlow** y **brvtal**. Cada canal pertenece exactamente a un proyecto (venture) y tiene un identificador estable dentro de él. Una edición nunca escribe archivos en los repositorios de producto; se realiza desde una interfaz autorizada del Identity Center cuando ese adaptador exista.

- Tipos permitidos: **email**, **form**, **messaging** (correo, formulario, mensajería). No enviar mensajes ni realizar llamadas a terceros.
- Campos de cada canal: `id`, `type`, `destination`, `schedule` (`timezone`, `hours`), `responsible_ref`, `status`, `sla_text` y `revision`.
- La zona horaria es siempre **America/Bogota**; el horario es un dato configurable, no una inferencia. Antes de su carga muestra literalmente **[COMPLETAR POR EL DUEÑO]**. El mismo marcador representa destino y responsable sin definir.
- `responsible_ref` es una referencia de identidad interna, nunca un nombre, correo personal, contraseña o credencial. Su resolución queda en Identity Center.
- `destination` puede ser un correo de soporte **público**, URL HTTPS de formulario o identificador público de mensajería aportado por el dueño. Rechazar destinos con tokens, userinfo en URL, parámetros de autenticación o identificadores de clientes.
- `status` es `unconfigured` hasta que el dueño complete y apruebe destino, horario y responsable; después `configured`. **No se hace público un destino incompleto como si estuviera listo**.
- Texto invariable por defecto: **mejor esfuerzo, sin SLA**. No existen valores numéricos de SLA ni promesa de tiempo de respuesta sin decisión humana específica.
- `revision` es entero no negativo; cada actualización incrementa de manera atómica y exige `expected_revision`, para rechazar escrituras concurrentes obsoletas. No eliminar silenciosamente el historial.

## Ejemplo normativo de configuración inicial — datos ficticios, NO configuración activa

<!-- support-channels-initial-v1 -->
```json
{
  "version": 1,
  "projects": {
    "Condor": [
      {
        "id": "support-primary",
        "type": "email",
        "destination": "[COMPLETAR POR EL DUEÑO]",
        "schedule": {"timezone": "America/Bogota", "hours": "[COMPLETAR POR EL DUEÑO]"},
        "responsible_ref": "[COMPLETAR POR EL DUEÑO]",
        "status": "unconfigured",
        "sla_text": "mejor esfuerzo, sin SLA",
        "revision": 0
      }
    ],
    "GrindFlow": [
      {
        "id": "support-primary",
        "type": "form",
        "destination": "[COMPLETAR POR EL DUEÑO]",
        "schedule": {"timezone": "America/Bogota", "hours": "[COMPLETAR POR EL DUEÑO]"},
        "responsible_ref": "[COMPLETAR POR EL DUEÑO]",
        "status": "unconfigured",
        "sla_text": "mejor esfuerzo, sin SLA",
        "revision": 0
      }
    ],
    "brvtal": [
      {
        "id": "support-primary",
        "type": "messaging",
        "destination": "[COMPLETAR POR EL DUEÑO]",
        "schedule": {"timezone": "America/Bogota", "hours": "[COMPLETAR POR EL DUEÑO]"},
        "responsible_ref": "[COMPLETAR POR EL DUEÑO]",
        "status": "unconfigured",
        "sla_text": "mejor esfuerzo, sin SLA",
        "revision": 0
      }
    ]
  }
}
```
<!-- /support-channels-initial-v1 -->

## Contratos para los adaptadores posteriores

**Escritura ControlBot exclusivamente:** `PATCH /internal/v1/support-channels/{project}/{id}` requiere sesión Identity Center verificada, autorización explícita `support_channels.manage` en el proyecto exacto, protección CSRF y `expected_revision`. Rechazar proyectos desconocidos, ID cruzado, roles ajenos, payloads extra, horario ausente o zona distinta. No existe una escritura desde el GET ni desde Condor, GrindFlow o brvtal.

**Bitácora transaccional Identity Center:** cada cambio aceptado debe registrar, en una transacción junto con la configuración, `actor_ref` verificado, `occurred_at` UTC, `scope`, `project`, `channel_id`, `action=support_channels.update`, `previous_value`, `new_value`, `previous_revision`, `new_revision` y `outcome`. El log es append-only y solo accesible al dueño con permisos de auditoría. Una escritura sin bitácora, audit write fallida o identidad desconocida se revierte; nunca se registra ni se muestra credencial, token, sesión o dato de cliente. El cambio que introduzca almacenamiento real debe revisar `datos.yml` y las puertas de privacidad antes de activarse.

**Lectura para producto:** `GET /api/v1/support-channels?project={project}`, con consumidor autorizado y autenticado, capacidad `support_channels.read` scoped exactamente al proyecto solicitado. Respuesta con `project`, `channels` (solo campos del contrato) y `revision`. No devolver actor, bitácora, sesiones, secretos, otros proyectos ni tokens. Consumidor sin autorización, proyecto inválido o falta de mapeo exacto: **403/404 fail-closed**, nunca fallback a un canal de otro proyecto. **GET sin efecto lateral**, sin cambios de estado o auditoría de escritura. Cache/Vary no debe mezclar tenants; respuestas privadas `Cache-Control: no-store` hasta que exista un contrato verificado de cache tenant-bound.

**Semántica de estado:** si falta dato, el GET debe devolver el marcador literal y `status=unconfigured` para mostrar una interfaz de soporte pendiente. No hacer `mailto:`, enlace o redirección a marcadores; no inventar un canal alternativo ni un SLA. Una configuración inexistente nunca significa canal público operativo.

**Orden para implementación real:** (1) storage y modelo de revisión por proyecto, (2) servicio autenticado de edición + bitácora Identity Center atómica, (3) GET de lectura scoped + pruebas negativas y `datos.yml` si aplica, (4) UI y adaptadores de producto en issues/claims específicos. Este PR no ejecuta esos pasos, no habilita producción ni toma decisiones legales.

## Aceptación offline y reversión

La prueba `tests/test_support_channels.py::SupportChannelsTests::test_criterio_principal` valida este ejemplo estructurado, los tres proyectos, valores desconocidos, tipos, zona, responsable, revisionado, lectura GET, auditoría y ausencia de SLA/secretos inventados; casos negativos cubren datos cruzados, campos extra, URLs inseguras, credenciales, falta de control de acceso y ausencia de auditoría. Las pruebas son **del contrato**, no prueba de un endpoint real.

Revertir el squash retira exclusivamente el contrato y las pruebas. No se han creado ni modificado credenciales, proveedores, bases de datos, datos personales de clientes ni servicios de soporte.
