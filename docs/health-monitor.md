# ControlBot · monitor periódico del health y avisos de correo simulados (#1370)

## Alcance, autoridad y límite

Implementación **solo offline** sobre el smoke #1365 (HTTPS, `/health`, identidad exacta de versión/SHA, login sintético y rutas protegidas). Cada invocación ejecuta una comprobación; un **cron externo** es responsable de repetirla. La fase actual es `construccion`. No se instala cron en Hostinger, no se habilita dominio real, no se usan correos reales ni se crea evidencia `VALIDATED_IN_PRODUCTION`. La CLI **rechaza explícitamente** `CONTROLBOT_PRODUCTION_STAGE=live` y objetivos distintos de `https://127.0.0.1`.

El monitor está **deshabilitado por defecto**: `CONTROLBOT_MONITOR_ENABLED` distinto de `1` no ejecuta el smoke ni envía correo. En modo sintético, la entrega SMTP solo puede ir a `127.0.0.1`, y todos los destinatarios obligatoriamente terminan en `@example.test`. Una integración de producción con Factory #1947 (configuración de receptores por secreto privado) requiere **otro issue y autorización del dueño**. Este PR no exporta contactos ni activa proveedores.

## Contrato del tick

1. Leer receptor(es) desde `CONTROLBOT_MONITOR_RECIPIENTS_FILE`, archivo **absoluto 0600, privado y no symlink**, JSON `{"version":1,"recipients":["owner@example.test","qa@example.test"]}` (máximo 10, sin duplicados). Es un fixture, no publicar contactos reales. Los identificadores de secretos de Factory #1947 se resuelven por un mecanismo autorizado futuro a un archivo privado; **no** se consultan correos de personas en este PR.
2. Leer estado anterior desde `CONTROLBOT_MONITOR_STATE_FILE` fuera de la raíz web. Crear/actualizar `{"version":1,"state":"HEALTHY|ALERT"}` de forma atómica con modo 0600; symlinks o archivos corruptos son NO-GO. Un estado ausente comienza `UNKNOWN`.
3. Ejecutar `bash scripts/production-smoke.sh` con timeout limitado, `SMOKE_BASE_URL` (HTTPS loopback), `SMOKE_EXPECTED_SHA` (40 hex), `SMOKE_EXPECTED_VERSION`, y credenciales sintéticas desde `SMOKE_USERNAME_FILE` / `SMOKE_PASSWORD_FILE` / `SMOKE_CA_FILE`, todos ficheros privados. Ningún stdout/stderr del smoke se retransmite al correo ni se publica.
4. Un código distinto de cero, timeout o resultado sin verificar produce `ALERT` y salida 2. En el primer cambio a `ALERT` se genera mensaje de alerta. Repetir el mismo estado evita correo duplicado. Volver a `HEALTHY` produce correo `RECUPERADO`, y salida 0; errores de correo, estado o configuración producen NO-GO y código 3.
5. El asunto/recibo sintético incluye fecha y hora `America/Bogota` en formato de 12 h, versión esperada y resultado `smoke_NO_GO_https_health_login_o_release`, `timeout_del_smoke` o `verificacion_no_disponible`. Si el smoke no identifica la causa precisa, **no se inventa** cuál de HTTPS, login, SHA o health falló. El cuerpo no incluye contraseñas, tokens, SHA o URLs privadas.

## Ejemplo de laboratorio (no instalar en producción)

Con un servidor TLS sintético y un servidor SMTP falso en localhost, configura `CONTROLBOT_PRODUCTION_STAGE=construccion`, `CONTROLBOT_MONITOR_ENABLED=1`, `CONTROLBOT_MONITOR_SMTP_PORT=1025`, `SMOKE_BASE_URL=https://127.0.0.1:8443`, `SMOKE_EXPECTED_VERSION=0.1.24` y el SHA esperado de 40 hex. Proporciona los archivos privados `CONTROLBOT_MONITOR_RECIPIENTS_FILE`, `CONTROLBOT_MONITOR_STATE_FILE`, `SMOKE_USERNAME_FILE`, `SMOKE_PASSWORD_FILE`, `SMOKE_CA_FILE`; después ejecuta `python3 scripts/health-monitor.py`.

Un cron externo podría ejecutar **este mismo comando por tick cada 10 minutos** (entrada de referencia `*/10 * * * *`), con un bloqueo de ejecución exclusivo para no solapar ticks. **No programar ni instalar esa entrada en Hostinger ahora**. La CLI no proporciona daemon, ni cambia infraestructura. Sin el archivo de destinatarios, el SMTP local y el estado privado no hay éxito simulado. Para producción habría que definir transporte externo autorizado, autenticación SMTP, retry/backoff, colas y control de concurrencia por issue independiente; la salida de este laboratorio nunca se interpreta como entrega real.

## Pruebas y rollback

`python3 -m unittest -v tests.test_health_monitor_alerts` ejercita la ejecución del script de smoke **real** contra el fixture HTTPS/TLS de #1365, incluyendo fallo 503, timeout inyectado y recuperación, con un correo simulado por callback; verifica privacidad, estado protegido, receptores sintéticos y bloqueo del modo live. `CI Factory v1 / Validar` exact-HEAD es obligatorio. Revertir el squash elimina solo esta CLI, la documentación y las pruebas, sin efecto en dominio, cron, datos reales, secretos ni proveedores.
