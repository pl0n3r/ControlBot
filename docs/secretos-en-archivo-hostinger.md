# Secretos en archivo privado (planes de Hostinger sin variables de entorno)

ControlBot lee `CONTROLBOT_VAULT_KEY` y `CONTROLBOT_OWNER_PASSWORD_HASH` de una variable de entorno o, si no existe, de un archivo cuyo camino indica `<NOMBRE>_FILE` en `.htaccess`. El repositorio solo contiene los caminos, nunca los valores.

## Qué crear en el servidor (lo hace el dueño, nunca un chat)
Carpeta `/home/u151692719/domains/control.condorapp.com.co/private/`, fuera del sitio publicado:
- `vault.key`: una línea con 32 bytes aleatorios en base64 (`openssl rand -base64 32`).
- `owner-password.hash`: una línea con `password_hash(...)` de la clave del dueño.
Ambos con permisos 600. Antes de modificar el servidor: copia previa según D-059.

## Reglas que aplica el código
- La variable de entorno gana sobre el archivo.
- El camino debe ser absoluto; el archivo, regular, de 1 a 4096 bytes, y **fuera del árbol desplegado**; si no, se trata como ausente y la app queda cerrada (503/403).
- Nunca pegar estos valores en issues, PRs, chats ni en `.htaccess`.
