# Secretos en archivos privados de Hostinger

Esta guía corresponde a ControlBot #1349. El cambio permite **leer** secretos desde archivos privados; no los crea ni los despliega. La creación de credenciales, la copia de seguridad, la intervención en Hostinger y el despliegue requieren la acción y autorización del dueño conforme a **D-059**.

## Ubicación y configuración (solo el dueño)

1. Preparar en Hostinger un directorio privado **fuera del repositorio y de `public_html`**, por ejemplo `/home/USUARIO/domains/DOMINIO/private/`. Aplicar permisos restrictivos al directorio (0700) y a cada archivo (0600), conforme a las capacidades del servidor y su propietario.
2. En ese directorio, guardar **por separado** `vault.key` (clave de cifrado del vault, con entropía criptográfica adecuada) y `owner-password.hash` (hash obtenido mediante `password_hash` de una contraseña que solo conozca el dueño). No copiar secretos en el repositorio, chats, logs, PR ni issues. No generar el hash con la contraseña literal en una orden que quede en el historial.
3. Configurar en `.htaccess` **solamente las rutas absolutas**, usando las variables `CONTROLBOT_VAULT_KEY_FILE` y `CONTROLBOT_OWNER_PASSWORD_HASH_FILE`. Ejemplo ilustrativo, **no literal para producción**:

```apache
SetEnv CONTROLBOT_VAULT_KEY_FILE /home/USUARIO/domains/DOMINIO/private/vault.key
SetEnv CONTROLBOT_OWNER_PASSWORD_HASH_FILE /home/USUARIO/domains/DOMINIO/private/owner-password.hash
```

4. Antes de modificar una instalación real, realizar y verificar el respaldo exigido por D-059. La configuración y despliegue siguen siendo operaciones independientes de este PR.

## Garantías de lectura y fallo cerrado

- `SecretSource::read` utiliza primero la variable de entorno directa, cuando es una cadena no vacía; en su defecto consulta la ruta de `NOMBRE_FILE`.
- La ruta debe ser **absoluta** y resolver a un archivo regular y legible **fuera del árbol desplegado**. No se aceptan rutas relativas, inexistentes, directorios ni enlaces simbólicos que resuelvan dentro de ese árbol.
- El archivo debe tener entre **1 y 4096 bytes** (máximo 4 KB). Se recortan espacios y saltos de línea alrededor del contenido; un valor que quede vacío se trata como ausente.
- Si el archivo o la configuración son inválidos, **no** se usan credenciales sustitutas, datos de prueba ni secretos embebidos. Los entrypoints permanecen cerrados (respuesta de servicio no disponible o denegación según su lógica).
- Evitar publicar la ruta en mensajes públicos si revela datos de la instalación. **Jamás** mostrar el contenido del archivo ni su hash en diagnósticos.

## Validación y reversión

Las pruebas offline en `tests/test_secret_source.py` usan directorios temporales y valores sintéticos para cubrir precedencia, recorte, archivos inválidos, árbol desplegado y rutas presentes en `.htaccess`. No prueban ni alteran Hostinger.

La validación de un entorno real requiere una autorización y ventana operativa separadas (D-059); comprobar las respuestas sin imprimir secretos y conservar una vía de reversión hacia el estado anterior. Si falta una condición de seguridad o el servidor no entrega `SetEnv` a PHP, **no** abrir el acceso como solución alternativa.
