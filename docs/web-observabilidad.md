# ControlBot #1451 — observabilidad e incidentes (solo lectura)

## Contrato de acceso

Solo la sesión owner **OAuth + TOTP** verificada por
`ControlBotWebEntrypoint::handleAuthenticated` puede consultar estas rutas:
- `GET /observabilidad`: página HTML con el estado por proyecto, fuentes y
  alertas **pasivas**. No publica mensajes ni crea incidentes.
- `GET /incidentes/{incident_id}`: página HTML con la línea de tiempo o
  `UNKNOWN` cuando no existe evidencia.
- `GET /api/v1/observabilidad` y
  `GET /api/v1/incidentes/{incident_id}`: proyecciones JSON tipadas.

Las rutas pública, local password-only, `REMOTE_USER`, cabeceras de
autorización inventadas y sesiones sin TOTP **no** habilitan estas páginas.
Una lectura inválida, caducada o inaccesible entrega 503 sin filtrar datos;
el HTML aplica escape y CSP restrictiva, con `Cache-Control: no-store`.

## Fuente confiable y fail-closed

El bootstrap `public/index.php` pasa únicamente la configuración
de servidor `CONTROLBOT_OBSERVABILITY_SNAPSHOT_PATH`. Debe apuntar a un
archivo JSON privado absoluto, fuera de `DOCUMENT_ROOT`, sin symlinks,
con permisos 0600 y tamaño máximo de 256 KiB, actualizado en los últimos
300 segundos. El archivo no se crea automáticamente y el repositorio
**no contiene datos de producción**. Ejemplo de contrato:

```json
{
  "version": 1,
  "observed_at": 14000,
  "project_ids": ["project-controlbot", "project-condor"],
  "events": [],
  "ttl_by_source": {"health": 120, "ci": 120, "deploy": 120, "agent": 120},
  "incidents": {}
}
```

`events` contiene eventos del contrato `ObservabilityEvent` y
`incidents` indexa `incident:<32 hex>` a `IncidentTimeline` o null.
La lectura privada es **read-only**; no hay conexión live ni
proveedor nuevo. Sin archivo o sin evidencia válida: no se declara salud
`HEALTHY` ni se finge actividad. Un proyecto con señales ausentes
mantiene `UNKNOWN`.

## Proyección por proyecto

El adaptador `ObservabilityReadSource` implementa `observability.snapshot` desde la fuente privada verificada y devuelve exactamente
{project_ids: [...], events: [...], ttl_by_source: {...}}.
Se normaliza cada evento por ObservabilityEvent y las fuentes health,
ci, deploy y agent por ObservabilityProjectStatus.

Solo con las cuatro fuentes fresh y severidad comprobada se permite
HEALTHY (todas info), DEGRADED (warning/error) o DOWN (critical).
Una sola fuente ausente, caducada o desconocida produce UNKNOWN.
Cada estado muestra su frescura, severidad, timestamp y evidencia
opaca. No inventa una medición de salud.

Las alertas son indicadores pasivos por proyecto, sin envío:
una clave estable sha256(project_id|status), sent=false y
notifications_sent=0. UNKNOWN se representa con indicador REVIEW.
No hay correo, push, Webhooks ni creación de incidentes.

## Línea de tiempo por incidente

La operación `incidents.read` devuelve `{timeline: raw-or-null}`
para el incident_ref derivado del identificador de ruta.
IncidentTimeline::build valida identidad, tipos, orden, timestamps,
evidencias y secuencias. Solo evidencia autenticable habilita
status OPEN o RECOVERED. timeline:null se proyecta como UNKNOWN
sin eventos ni duración. Identidad inconsistente o datos inválidos
fallan cerrado (503), sin exponer payload sin validar.

## Verificación y límites

Ejecutar:
- php -l src/Routing/Routes/ObservabilityRoutes.php
- php -l src/ObservabilityReadSource.php
- php -l src/ControlBotWebEntrypoint.php
- php -l public/index.php
- python3 -m unittest -v tests.test_web_observability
- python3 -m unittest -v tests.test_owner_routes_registry tests.test_observability_project_status

Pruebas nuevas con backend sintético en memoria: estados sanos solo con
cuatro fuentes, DEGRADED con warning/error, DOWN con critical, UNKNOWN
con ausencias y stale, detalles de incidente,
rechazo de ID y fuentes alteradas y ausencia de notificaciones. Las pruebas HTTP del propio lote verifican la página HTML owner-only, OAuth/TOTP,
403/404/405/503, fuente privada 0600, timestamps stale, symlink y no-store.
El núcleo protegido de OwnerAuthenticatedRouter no cambia.
No se usa estado live, Hostinger, despliegue, cuentas, datos reales,
gasto, secretos ni endpoints mutantes. Un squash-revert elimina estos
tres archivos sin migración ni cambios productivos.
