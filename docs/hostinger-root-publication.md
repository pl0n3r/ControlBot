# Publicación de la raíz en Hostinger

ControlBot sigue en **construcción**. Este contrato endurece el caso actual en el que Hostinger publica la raíz del repositorio como `public_html`; no activa deploy, `DOMAIN`, `DEPLOY_ENABLED`, DNS, gasto ni go-live.

## Mapeo

`public_html` → raíz del repositorio → `index.php` → `public/index.php`.

El `index.php` raíz es un bootstrap mínimo. Si `public/index.php` no existe, no es un archivo regular o resuelve fuera de `public/`, responde `503 Service unavailable` y no carga código alternativo. Esto mantiene el repositorio fail-closed y evita publicar por accidente archivos aunque exista físicamente en la raíz una ruta futura fuera de `public/`.

`.htaccess` desactiva índices de directorio, bloquea rutas internas (`src/`, `config/`, `docs/`, `scripts/`, `tests/`, `vendor/`, `lecciones/`, `openapi/`, `readme/`), metadata/dotfiles, documentación/configuración serializada y PHP arbitrario. Solo `index.php`, `public/index.php`, assets existentes bajo `public/` y rutas virtuales atendidas por el front controller forman parte de la superficie prevista.

## Acceso owner-only

La autenticación del sitio usa Basic Auth de Apache/LiteSpeed y un archivo de contraseñas **fuera del repositorio y fuera de `public_html`**:

`/home/u151692719/.htpasswds/controlbot`

El repo solo contiene el identificador público `USUARIO`. La contraseña y su hash permanecen únicamente en el servidor. No deben copiarse a GitHub, comentarios, logs, tickets ni comandos que queden en el historial del shell.

`.htaccess` configura:

- `AuthType Basic`;
- `AuthName "ControlBot"`;
- `AuthUserFile /home/u151692719/.htpasswds/controlbot`;
- `Require valid-user`;
- `SetEnv CONTROLBOT_OWNER_LOGIN USUARIO`.

La aplicación sigue verificando identidad por dos señales server-side: `$_SERVER['REMOTE_USER']` y `getenv('CONTROLBOT_OWNER_LOGIN')`. Si cualquiera falta, la petición falla cerrada. No se acepta `Authorization`, `X-Remote-User` ni otro header controlable por el cliente como sustituto.

### Compatibilidad LiteSpeed

Después del merge se debe comprobar que LiteSpeed entrega `REMOTE_USER` a PHP y que `SetEnv` aparece vía `getenv('CONTROLBOT_OWNER_LOGIN')`. Si una de esas señales no llega, no se debe relajar la comparación de propietario.

La alternativa mínima debe seguir usando únicamente identidad generada por el servidor. Por ejemplo, si LiteSpeed expone `REDIRECT_REMOTE_USER`, se implementa un cambio separado y probado que lo lea desde `$_SERVER` solo después de confirmar que lo genera el servidor. Si `SetEnv` no llega a PHP, puede evaluarse una regla de entorno equivalente del servidor. No se deben leer headers arbitrarios ni incrustar secretos en `.htaccess`, PHP o documentación.

## Verificación offline

1. Sin `REMOTE_USER`, el entrypoint devuelve `503` aunque `CONTROLBOT_OWNER_LOGIN` esté configurado.
2. Los tests estáticos confirman que `AuthUserFile` apunta fuera de `public_html`, que no existe hash/contraseña en los archivos reclamados y que las reglas deny-by-default previas siguen presentes.
3. Los tests confirman que el login configurado por `SetEnv` coincide con el valor que `public/index.php` entrega al entrypoint.

## Verificación posterior al merge

Ejecutar desde un terminal del dueño, sin publicar la contraseña:

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://control.condorapp.com.co/
curl -u USUARIO -s -o /dev/null -w '%{http_code}\n' https://control.condorapp.com.co/
curl -s -o /dev/null -w '%{http_code}\n' https://control.condorapp.com.co/src/FactoryLiveUi.php
curl -s -o /dev/null -w '%{http_code}\n' https://control.condorapp.com.co/config/
curl -u USUARIO -s -o /dev/null -w '%{http_code}\n' https://control.condorapp.com.co/src/FactoryLiveUi.php
curl -u USUARIO -s -o /dev/null -w '%{http_code}\n' https://control.condorapp.com.co/config/
```

Al usar `curl -u USUARIO` sin `:contraseña`, curl solicita la contraseña de forma interactiva; no escribirla en la línea de comandos.

Resultado esperado:

- raíz sin credenciales: `401`;
- raíz autenticada: `200` con la vista, incluso si el snapshot se muestra `UNKNOWN`;
- `/src/` y `/config/`: `403` tanto sin credenciales como con credenciales. Si Hostinger/LiteSpeed devuelve otro código, #673 no está verificado y debe corregirse sin debilitar el deny-by-default.

Registrar en ControlBot #625 únicamente códigos HTTP, SHA y URL de evidencia; nunca usuario+contraseña, hash ni contenido sensible.

## Reversión

Si el cambio produce `500` o rompe el acceso, revertir el commit de `.htaccess`. El estado seguro es cerrado: una ausencia de Basic Auth funcional no autoriza acceso porque la aplicación exige `REMOTE_USER` y `CONTROLBOT_OWNER_LOGIN`.

## Límite operativo

No se prueba Hostinger real desde las regresiones y no se declara el sistema live. La verificación real de dominio/artefacto/SHA y rollback continúa perteneciendo al tramo #624. Este cambio no habilita `DOMAIN`, `DEPLOY_ENABLED`, cron, productor de snapshot ni go-live. La evidencia productiva de #625 sigue siendo autoridad para desbloquear la integración posterior.
