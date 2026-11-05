# ControlBot #1451 — observabilidad e incidentes (solo lectura)

## Estado y alcance

El registro owner-v1 expone GET /api/v1/observabilidad y
GET /api/v1/incidentes/{incident_id}, identificador incident-<32 hex>.
El registro existente OwnerAuthenticatedRouter solo sirve endpoints al
propietario con OAuth/TOTP válido y entrega Cache-Control: no-store.

**NO ACREDITADO:** la especificación exige las páginas HTML literales
/observabilidad e /incidentes/{id}; el front controller
src/ControlBotWebEntrypoint.php aún no las cablea y está reclamado por
reservas V3 ajenas (#1429, #1449 y #1452). El contrato no se amplió
ni se invadió. El JSON API no sustituye la pantalla HTML ni permite
certificar AC-01 en su integridad. Tras liberar el claim se necesitará
renovar el contrato V3, integrar la vista y probar ambas rutas.

## Proyección por proyecto

El adaptador confiable $ownerApiBackend implementa la operación
observability.snapshot y devuelve exactamente
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

La operación incidents.read devuelve {timeline: raw-or-null}
para el incident_ref derivado del identificador de ruta.
IncidentTimeline::build valida identidad, tipos, orden, timestamps,
evidencias y secuencias. Solo evidencia autenticable habilita
status OPEN o RECOVERED. timeline:null se proyecta como UNKNOWN
sin eventos ni duración. Identidad inconsistente o datos inválidos
fallan cerrado (503), sin exponer payload sin validar.

## Verificación y límites

Ejecutar:
- php -l src/Routing/Routes/ObservabilityRoutes.php
- python3 -m unittest -v tests.test_web_observability
- python3 -m unittest -v tests.test_owner_routes_registry tests.test_observability_project_status

Pruebas nuevas con backend sintético en memoria: estados sanos solo con
cuatro fuentes, UNKNOWN con ausencias y stale, detalles de incidente,
rechazo de ID y fuentes alteradas y ausencia de notificaciones. Las
pruebas existentes del router autenticado cubren OAuth/TOTP, 403, CSRF
y no-store; no se modifica el núcleo protegido por esta reserva.
No se usa estado live, Hostinger, despliegue, cuentas, datos reales,
gasto, secretos ni endpoints mutantes. Un squash-revert elimina estos
tres archivos sin migración ni cambios productivos.
