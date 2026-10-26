# Control owner: throttle por IP, revisión de seguridad offline (#922)

## Comportamiento, identidad y límites

- `Retry-After` en HTTP 429 devuelve la espera **real** del bloqueo por IP. Si se agotan cinco intentos de la ventana de 900 segundos, informa el tiempo hasta el vencimiento de la ventana, no un valor constante.
- El contador persistente se registra únicamente por IP. El nombre de archivo deriva de un HMAC y SHA-256 de la IP con clave vinculada al hash solo-servidor, no de una identidad de usuario: no se guarda la IP ni el username en claro, y el directorio privado debe quedar fuera del webroot, con permisos restrictivos y `flock`.
- Se elimina el bloqueo global basado en `owner_login`: los intentos desde una IP ajena no deben impedir iniciar sesión al propietario desde otra IP. Se conserva el límite por IP, verificación de contraseña Argon2id, comparación constante del username, CSRF y respuestas genéricas.
- **Limitación:** el límite por IP no elimina ataques distribuidos entre IP diferentes; varias personas detrás del mismo NAT pueden compartir el bloqueo. No es autorización para quitar Basic Auth, habilitar `public/index.php` o declarar el sitio seguro para live. Antes de activación real hay que exigir protección de borde, 2FA y decisión explícita D-059/go-live.

## Plan de limpieza segura y recuperación

Este documento solo describe la **limpieza** de archivos caducados; no activa cron ni cambios en el servidor.

1. Realizar limpieza únicamente en una ventana comprobada sin solicitudes al login ni procesos que retengan `flock`; borrar bajo tráfico puede romper la exclusión porque el archivo abierto y la ruta recreada tendrían locks distintos.
2. El operador autorizado verifica que el directorio esté fuera del webroot, y filtra exclusivamente archivos normales cuyo basename sea `cb-owner-[0-9a-f]{64}.json`; rechaza enlaces simbólicos, rutas inesperadas o JSON inválido. Nunca registrar los nombres, direcciones IP o contenidos de estos archivos en logs.
3. Inspeccionar `start`, `count`, `blocked_until` y antigüedad. Solo son candidatos los contadores cuya ventana de 900 s y bloqueo hayan expirado, con margen conservador adicional. Se requiere un mantenimiento exclusivo verificado antes de eliminar, no simplemente `unlink` mientras otro proceso pueda abrir la ruta.
4. Para recuperación del dueño, permitir caducar la ventana o, previa autorización y mantenimiento exclusivo, eliminar **solo** el registro local de la IP afectada con respaldo/rollback acreditados. No desactivar el rate limit ni publicar secretos.

La implementación de una operación de purga, cron, respaldo o restauración productiva exige hoja, reserva V3 y permisos de infraestructura propios. Este PR no ejecuta esas acciones.

## Inventario personal y documentación

Se revisó `datos.yml`. El campo persistente declarado en `owner_login_credentials` sigue siendo únicamente `password_hash` (Argon2id, solo servidor). Usuario y contraseña de formulario son transitorios y no se almacenan en el sistema; el contador pseudonimizado por IP ya estaba presente en la implementación anterior y conserva la finalidad de seguridad. Este refactor elimina el estado por username, sin añadir finalidad, proveedor ni campo persistente nuevo que obligue a regenerar los documentos derivados. El inventario y la base legal necesitan revisión jurídica antes de un go-live real.

**Estado:** trabajo OFFLINE fase `construccion`, sin nuevos datos de clientes. Ni los tests ni el merge acreditan HEALTH, producción validada o autorización de acceso real.

**Reversión:** revertir el squash de #922 recupera el throttle anterior. La hoja de activación futura debe comprobar esta corrección y ejecutar gates independientes de privacidad, D-059 y seguridad.
