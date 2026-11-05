# Inbox web y decisiones del dueño (ControlBot #1446)

## Objetivo y límites

- UI privada **/inbox** y **/decisiones/{decision_id}** para el dueño autenticado con sesión OAuth+TOTP validada por `OwnerSessionService`. El login local de contraseña sola **no** concede permiso para usar decisiones.
- Reutiliza `OwnerAuthenticatedRouter` y sus contratos `GET /api/v1/owner-inbox`, `GET /api/v1/owner-decisions/{decision_id}`, `POST /api/v1/owner-decisions/{decision_id}/decision`. La fuente es exclusivamente un adaptador de servidor confiable.
- `OwnerInbox::collection` y `ExternalApiOwnerDecisionRead::detail` validan y filtran datos; `DecisionRuntime` conserva autoridad para registrar la decisión y `AppendOnlyAuditLog` debe reflejarla antes de afirmar éxito.
- `ControlCenterShell` ofrece navegación responsiva y semántica; se mantienen estados vacío, falta de fuente (503), sin permiso (403) y error (400).
- Filtros declarados sin inferir readiness: todas, decisiones y críticas, siempre sobre la proyección ya validada. El historial solo se muestra con un origen autenticado; una fuente ausente **no** equivale a historial vacío.
- Los formularios POST usan token CSRF vinculado a la sesión, clave de idempotencia y validación del backend. Una respuesta no confirmada o sin bitácora **no** es aprobación exitosa.
- La UI escapa HTML de origen externo, no imprime credenciales ni PII y fija `Cache-Control: no-store`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff` y `Referrer-Policy: no-referrer`.

## Integración HTTP

El router existente solo admite `/api/v1/*`. Las rutas HTML requieren un bootstrap privado en `public/inbox.php` y un rewrite cerrado en `.htaccess` **bajo ampliación de claims V3 antes de editar esos archivos**. No tocar `src/ControlBotWebEntrypoint.php`, compartido con otros trabajos activos. Nunca publicar rutas sin su guardia de autenticación.

El bootstrap HTTP no crea ni carga un adapter de datos reales: mientras no exista configuración confiable, responde `503` y no inventa decisiones. No se habilita conexión live, proveedores, migraciones, secretos, gasto ni deploy (D-059).

## Aceptación y reversión

- AC-01: `tests/test_web_inbox_decisions.py::InboxDecisionsTests::test_criterio_principal`.
- AC-02: `tests/test_web_inbox_decisions.py::InboxDecisionsTests::test_criterio_seguridad_y_bordes`.
- AC-03: CI Factory v1 / Validar success sobre HEAD exacto; Sonar/CodeQL/CodeRabbit FINAL y reserva V3 verificadas antes de squash.
- Revertir el squash restaura rutas y página anteriores. No hay persistencia ni datos nuevos por este cambio.
