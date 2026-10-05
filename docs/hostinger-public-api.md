# Hostinger Public API adapter

ControlBot usa la Public API oficial de Hostinger mediante un adapter tipado; no crea una consola paralela ni almacena tokens.

## Contrato

- Base oficial: `https://developers.hostinger.com`.
- Solo HTTPS y paths allowlisted de hosting.
- `hostinger.read` y `cron.snapshot` son lecturas automáticas según `CapabilityPolicy`.
- El bearer llega ya resuelto por el boundary de secretos y nunca forma parte de evidencia, errores o UI.
- Errores HTTP, rate limit, JSON inválido o payload >1 MB fallan cerrado.
- La API oficial documenta 90 requests/min; este adapter no implementa loops ni polling.
- `cron.write` sigue protegido por backup/grant. En este slice solo genera un plan `execution=false`; no ejecuta POST/DELETE.
- Tests usan transporte fake: cero red y cero credenciales reales.

## Uso futuro en #625

Con un token API server-side autorizado, Production Authority podrá listar websites/cron sin hPanel interactivo. Crear el cron live, habilitar `DOMAIN`/`DEPLOY_ENABLED` y go-live siguen siendo pasos separados y owner-gated.
