# Orquestador en vivo: readiness de deploy y observación

Este contrato prepara el despliegue y la observación del Orquestador en vivo sin activar Hostinger, dominio, secretos ni go-live. El repositorio permanece en fase `construccion`.

## Estado seguro por defecto

- `DEPLOY_ENABLED` ausente o distinto de `true` mantiene el deploy cerrado.
- `DOMAIN` ausente impide que el observador se considere listo.
- `public/index.php` es el entrypoint esperado. Mientras no exista, los preflights fallan cerrado.
- El observador envía `expected_sha: ${{ github.sha }}` al reusable de Factory para exigir identidad exacta del artefacto.
- Ningún secreto se documenta con valor real. Los workflows solo referencian secretos de GitHub.

La existencia de este contrato no autoriza a crear el entrypoint público ni a activar el sitio. Esas acciones pertenecen a la autorización y materialización separadas del hardening raíz.

## Configuración requerida

Variables:

- `DEPLOY_ENABLED`: debe ser exactamente `true` para habilitar los jobs de deploy/observación.
- `DOMAIN`: origen que observará Factory; sin valor no hay observación.
- `HEALTH_PATH`: opcional; por defecto `/health.php`.
- `PRODUCTION_STAGE`: opcional; por defecto `construccion`.
- `MIGRATION_MODE`: opcional; por defecto `none`.
- `LIVE_MIGRATION_APPROVED`: opcional; por defecto falso.

Secretos referenciados, nunca documentados con valores:

- `DEPLOY_TOKEN`
- `DATABASE_URL`
- `DEPLOY_SSH_KEY`

El contrato no crea ni rota estos secretos.

## Flujo fail-closed

### Deploy

1. Solo `workflow_dispatch`.
2. El job de readiness se ejecuta únicamente cuando `DEPLOY_ENABLED == 'true'`.
3. Antes de llamar a Factory v1 exige `DOMAIN` no vacío y `public/index.php` presente.
4. El reusable conserva `phase=construccion` por defecto y `migration_mode=none`.

### Observación

1. Puede iniciarse manualmente o por el schedule existente.
2. Si `DEPLOY_ENABLED` no es `true` o `DOMAIN` está vacío, no se declara una observación verde.
3. El preflight exige `public/index.php` y una identidad `GITHUB_SHA`.
4. El reusable recibe el SHA exacto esperado; una identidad distinta debe fallar cerrado.

## D-059 y activación posterior

Este cambio no modifica Hostinger. Cualquier acción futura que cambie despliegue, DNS, base de datos o cron en Hostinger exige **backup previo** y que la evidencia del backup quede registrada en el Issue correspondiente antes de la mutación. Comprar, renovar o cambiar planes no está autorizado para agentes.

La decisión OWNER que permitió materializar el hardening no elimina estas precondiciones ni convierte un merge en producción validada.

## Rollback

El rollback de este contrato es no destructivo:

1. mantener o devolver `DEPLOY_ENABLED` a un valor distinto de `true`;
2. revertir el commit/PR que cambie los workflows con `git revert`;
3. no borrar bases de datos, archivos remotos, DNS ni secretos como parte del rollback;
4. si una activación futura ya hubiera tocado Hostinger, usar el backup registrado y el procedimiento del cambio productivo correspondiente;
5. volver a observar únicamente después de restaurar identidad exacta y configuración conocida.

Un deploy exitoso no equivale a producción validada. La validación posterior debe demostrar el SHA/versión esperados y mantener autenticación owner-only.
