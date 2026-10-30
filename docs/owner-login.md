# Login local del propietario — ControlBot #1366

**Estado:** implementación offline dividida. Este documento no certifica despliegue ni activación de autenticación pública.

## Dependencias y alcance
- #1379: almacén privado cifrado, versión revocable y TOTP, con pruebas sintéticas.
- #1380: adaptador de login HTTP (CSRF, contraseña, sesión, logout, segundo factor), dependiente de #1379.
- #1366 / PR #1375: pruebas integrales sintéticas de ambos módulos.
- #1349 / PR #1350: fuente privada de credenciales para Hostinger y rutas compartidas; **no se editan desde #1366**.
- Integración `public/login.php`, `public/index.php` y `.htaccess`: requiere una nueva V3 después de #1349. Basic Auth debe permanecer desactivado de forma predeterminada cuando se habilite el login local.

## Estado y controles de seguridad
- Identidad única del propietario; contraseña en hash Argon2id, nunca contraseña, secreto TOTP ni recovery en el repositorio.
- `OwnerLocalAuthStore` utiliza un directorio absoluto privado fuera del árbol web y una clave de cifrado con tamaño libsodium. Si la clave o el estado faltan o son corruptos, denegar acceso sin exponer información.
- Cada sesión conserva una revisión entera y un tiempo de última actividad; revocación global incrementa la revisión. La validez se comprueba en rutas protegidas y antes de emitir nuevas sesiones.
- Todas las acciones sensibles requieren CSRF y autenticación; iniciar configuración MFA y revocar globalmente exigen reautenticación con contraseña.
- TOTP es opcional salvo política explícita; códigos de recuperación se muestran una sola vez y solo se guardan hashes. Los OTP de enrolamiento no se reutilizan.
- Auditoría local: inicio, salida, revocación y operaciones MFA, sin valores secretos ni tokens.

## Verificación offline antes de cualquier integración
1. Fusionar #1379, después #1380 y finalmente probar el contrato de #1366 con usuario, IP y relojes falsos.
2. Acreditar `CI Factory v1 / Validar` para el HEAD exacto; verificar suite completa, CodeQL y Sonar. CodeRabbit únicamente en la revisión FINAL, después del CI verde.
3. Probar rechazo por CSRF, clave ausente, sesión caducada, revocación concurrente, TOTP inválido/caducado, recuperación repetida y estado corrupto.
4. Confirmar que el diff de cada PR sigue dentro del presupuesto de 400 líneas, sin deshabilitar pruebas.

## Integración y reversión pendientes
- **No activar en producción por inferencia.** Requiere cerrar #1349, renovar claims V3 de las rutas públicas y cumplir D-059 para el despliegue o modificación del entorno.
- Propietario prepara credenciales y claves **fuera** del repositorio, en archivos privados autorizados; ningún agente las crea o registra aquí.
- Para revertir una activación autorizada, volver al flujo anterior según el runbook de despliegue y preservar el estado privado para investigación segura. El rollback de código no demuestra rollback de credenciales ni del servidor.
