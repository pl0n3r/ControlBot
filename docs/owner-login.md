# Login del propietario · contrato de seguridad (ControlBot #1366)

> Estado: preparación **offline**. Este documento no activa `/login`, no cambia Hostinger ni declara completado #1366.

## Flujo aprobado y límites

El acceso al panel será **solo para el propietario**. Contraseña con hash Argon2id y secreto entregado exclusivamente por archivo privado fuera del árbol web; la sesión HTTP debe usar `Secure`, `HttpOnly`, `SameSite=Strict`, `use_strict_mode`, rotación del identificador tras login, CSRF en POST, rate limiting y un tiempo máximo de inactividad de 15 minutos. El logout invalida la sesión. Ni Basic Auth ni headers de usuario externo conceden privilegios por sí mismos. Basic Auth permanece apagado por defecto, y su uso excepcional requiere decisión/configuración explícita.

Para la revocación centralizada se necesita **persistencia server-side** de identificadores de sesión hasheados, con expiración y estado revocado: cambiar la contraseña, revocar desde el panel y cerrar sesión invalidan el token almacenado, no solo la cookie actual. Todos los accesos, cierres y revocaciones deben generar eventos de bitácora sin contraseñas, tokens, semillas TOTP, códigos de recuperación ni identificadores en claro. El panel debe ofrecer la revocación de las otras sesiones con protección CSRF y comprobación de identidad.

El segundo factor empieza **desactivado** y requiere consentimiento/activación del dueño. Activar TOTP genera un secreto aleatorio almacenado cifrado fuera del código, exige confirmar un código antes de activar, y entrega códigos de recuperación de **uso único** para guardar fuera del sistema. Guardar solo hashes de esos códigos, registrar su consumo y revocar todos al regenerarlos. Con TOTP activo, la contraseña sola no crea una sesión autorizada. La política `totp_required` impide desactivarlo sin verificación y una modificación administrativa legítima. Los códigos TOTP se verifican con ventana acotada y reloj de servidor; rechazar formato incorrecto y códigos vencidos. La configuración y el registro de revocaciones requieren protección contra CSRF y cambios de estado concurrentes.

## Evidencia exacta del código existente en `main` al iniciar el trabajo

- `src/OwnerLoginGate.php` y `public/login.php` ya implementan contraseña Argon2id, sesión local de 15 min idle, logout, CSRF, rotación de ID y limitación por IP. El endpoint `public/login.php` permanece **offline**: no implica HTTPS ni despliegue verificados.
- `src/OwnerSession.php` expone `Totp::verify` y reautenticación de sesiones **OAuth de GitHub**, no conecta el TOTP al formulario de login local.
- **No acreditado en esas fuentes**: revocación de otras sesiones desde panel, bitácora para login/logout/revocación local, alta/baja de TOTP local, política obligatoria o códigos de recuperación. No se deben representar como implementados.

## Verificación y puertas

`python -m unittest tests.test_owner_login_session -v` prueba el baseline con un propietario y reloj **falsos**. También deben aprobar `tests/test_owner_login_gate.py` y `tests/test_owner_session.py`. Los tests de baseline no acreditan las funciones pendientes. Para completar #1366 se requieren cambios de runtime y sus regresiones, en una **reserva V3 autorizada que reclame expresamente esas rutas**. La reserva actual de #1366 reclama exclusivamente `docs/owner-login.md` y `tests/test_owner_login_session.py`; no autoriza editar `src/`, `public/` ni migraciones.

Nunca activar o fusionar como feature listo hasta demostrar revocación centralizada, eventos de bitácora, flujo TOTP local opcional/obligatorio y recuperación de uso único, además de `CI Factory v1 / Validar` terminal sobre HEAD exacto, SonarCloud/CodeQL y gates de seguridad. D-059 exige respaldo verificable y autorización para cualquier operación de Hostinger. La fase sigue `construccion`, sin secretos reales y sin cambio de disponibilidad.

## Reversión

Revertir el squash de documentación/pruebas restaura el estado anterior; no cambia cuentas, sesiones existentes, hosting ni secretos.
