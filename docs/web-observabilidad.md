# Observabilidad e incidentes — ControlBot #1451

Solo el dueño con OAuth+TOTP accede a HTML GET /observabilidad,
GET /incidentes/{incident_id}, y APIs /api/v1 equivalentes.
Sesión local, REMOTE_USER o headers no conceden autoridad.
HTML escapado, CSP y Cache-Control: no-store.

CONTROLBOT_OBSERVABILITY_SNAPSHOT_PATH es un JSON privado de
servidor: ruta absoluta, 0600, no symlink, fuera de DOCUMENT_ROOT,
máximo 256 KiB, observed_at <= 300 segundos de antigüedad.
Esquema v1: observed_at, project_ids[], events[], ttl_by_source{},
incidents{incident:<32 hex>: timeline|null}. Sin fuente válida: 503.
Una fuente ausente/stale es UNKNOWN; nunca se inventa HEALTHY.
Alertas pasivas, notifications_sent=0; sin deploy, datos reales ni gasto.
Tests: python3 -m unittest tests.test_web_observability.
Reversión: revert del squash, sin migraciones.
