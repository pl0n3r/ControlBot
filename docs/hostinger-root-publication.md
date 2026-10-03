# Publicación de la raíz en Hostinger

ControlBot sigue en **construcción**. Este contrato solo endurece el caso actual en el que Hostinger publica la raíz del repositorio como `public_html`; no activa deploy, dominio, secretos ni go-live.

## Mapeo

`public_html` → raíz del repositorio → `index.php` → `public/index.php`.

El `index.php` raíz es un bootstrap mínimo. Si `public/index.php` no existe, no es un archivo regular o resuelve fuera de `public/`, responde `503 Service unavailable` y no carga código alternativo. Esto mantiene el repositorio fail-closed mientras el entrypoint público siga pendiente del tramo de deploy/readiness #624.

`.htaccess` desactiva índices de directorio, bloquea rutas internas (`src/`, `config/`, `docs/`, `scripts/`, `tests/`, `vendor/`, `lecciones/`, `openapi/`, `readme/`), metadata/dotfiles, documentación/configuración serializada y PHP arbitrario. Solo `index.php`, el futuro `public/index.php`, assets existentes bajo `public/` y rutas virtuales atendidas por el front controller forman parte de la superficie prevista.

## Verificación offline

1. Sin `public/index.php`, ejecutar `php index.php` devuelve únicamente `Service unavailable.`.
2. Con un `public/index.php` sintético dentro de una copia temporal, el bootstrap lo delega sin tocar rutas internas.
3. Los tests estáticos confirman que fuentes, dotfiles, Markdown/YAML/JSON y PHP fuera de los dos entrypoints quedan denegados.

## Límite operativo

No se prueba Hostinger real, no se cambia `DEPLOY_ENABLED`, no se ejecuta `Observar producción` y no se declara el sistema live. La verificación real de dominio/artefacto/SHA y rollback pertenece al tramo #624 y continúa sujeta a sus gates.
