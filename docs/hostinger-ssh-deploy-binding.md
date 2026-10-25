# Enlace de Hostinger SSH a Factory@v1 — ControlBot #883 / #625

Esta hoja corrige **solo el binding de configuración** entre el workflow
`.github/workflows/deploy.yml` y el reusable
`pl0n3r/factory/.github/workflows/deploy.yml@v1`, que ya reconoce
`hostinger-ssh`. **NO despliega** y **no autoriza** cambios de infraestructura.

## Condición de activación y autoridad

- Continúa `workflow_dispatch` manual, sin trigger push.
- Los jobs siguen sujetos a `vars.DEPLOY_ENABLED == 'true'`.
- `transport` recibe `vars.DEPLOY_TRANSPORT || 'none'`. El default
  fail-closed es `none`; por tanto este merge no habilita despliegues.
- Solo se aceptará `hostinger-ssh` después de acreditar el operador
  Hostinger, el alcance, la capacidad efectiva de `Factory@v1`,
  las variables permitidas y el restore-point **D-059** con procedencia actual.
- `HOSTINGER_SSH_HOST`, `HOSTINGER_SSH_USER`, `HOSTINGER_SSH_PORT` y
  `HOSTINGER_RELEASE_ROOT` se transmiten como inputs desde variables
  protegidas, sin valores reales fijados en este repositorio.
- `HOSTINGER_KNOWN_HOSTS` y `DEPLOY_SSH_KEY` son **secrets** diferentes,
  nunca inputs públicos; fijar la huella por canal autorizado, sin
  desactivar host-key verification ni insertar secretos en logs/chat.

## Puertas reales que continúan cerradas

1. Los adapters `ops/factory/{build,backup,deploy,rollback}` y el
   rollback/backup funcional todavía no existen en ControlBot. No configurar
   el transporte como operativo sin implementar y probar esos contratos.
2. Revalidar backup o revert Git-first D-059 dentro de su alcance limitado,
   con SHA y último artefacto realmente sano, y el mecanismo real de rollback.
   El recibo histórico no sustituye una verificación actual.
3. El endpoint HTTP de identidad requiere HTTPS **owner-only** y Basic Auth.
   El `curl` del smoke actual y el observador no tienen un mecanismo
   **autenticado** de acceso; el GET 403/skipped no es `HEALTHY`.
   No abrir el panel ni `/health.php` sin autenticación para forzar 200.
4. Dominio, credenciales, DNS, SSH real, conexión a Hostinger y
   autorización de despliegue/live permanecen fuera de esta hoja.
5. La coincidencia exacta de `version` y `release_sha` debe verificarse
   contra evidencia de hosting. CI verde o merge no prueban publicación.

Prueba offline sin red ni credenciales:
`python3 -m unittest discover -s tests -p 'test_hostinger_ssh_deploy_binding.py' -v`.

Reversión: squash revert de este enlace; sigue el default `none`
en el reusable mientras no haya una configuración legítima.
