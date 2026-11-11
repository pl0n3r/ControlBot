# Registro de ideas · Intake owner-v1

## Acceso y flujo

- `GET /intake` se sirve únicamente desde `ControlBotWebEntrypoint::handleAuthenticated`, tras validar OAuth + TOTP del propietario. `POST /intake` devuelve 405. Acceso público, anónimo, OAuth parcial y sesión local devuelven 403.
- El renderer `src/Routing/IntakePage.php` es HTML aislado, no un módulo `Routing/Routes/*.php`. Responde con CSP y nonce, `no-store`, bloqueo de iframe y token CSRF de sesión.
- La interfaz consume `/api/v1/intake` y `/api/v1/intake/{id}/decision`. El router owner-v1 revalida la sesión y exige CSRF para escrituras; el backend se inyecta desde el servidor y, si está ausente, la API falla cerrado.
- El flujo permite registrar una idea sintética, listar pendientes y previsualizar proyecto nuevo o existente. Conserva `execution=false`, `committed=false`, cero repositorios y cero issues creados.
- La UI usa `textContent` y rechaza respuestas con ejecución o respuestas asíncronas obsoletas.

## Pruebas y límites

`python3 -m unittest -v tests.test_web_intake` comprueba sesiones, permisos, CSRF, límites, flujo API y vista HTML con backend en memoria y PHP real. No demuestra despliegue, persistencia productiva, proveedores ni go-live.

Reversión: revert del squash asociado a #1511. Ninguna ruta autoriza materialización permanente, secretos o creación de recursos en GitHub.
