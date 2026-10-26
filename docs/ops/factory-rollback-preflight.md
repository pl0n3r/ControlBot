# ControlBot — preflight sintético de rollback (#905)

Esta hoja prepara un contrato de pruebas offline para el épico #625. El kit Factory invoca `ops/factory/rollback` cuando falla un despliegue; **este ejecutable NO realiza un rollback verdadero ni es seguro usarlo como rollback de producción**. No altera la base de datos, archivos del hosting, credenciales ni releases.

## Entradas permitidas
Solo `--offline-dry-run` con objeto JSON inventado de como máximo 8192 bytes por stdin, sin rutas ni secretos. Claves exactas: `kind=synthetic`, `phase=construccion`, `plan_kind=git_revert`, `candidate_sha`, `previous_healthy_sha`, `expected_healthy_sha` (40 caracteres hex minúsculos), `baseline_verified`, `previous_artifact_verified`, `rollback_plan_verified` (booleanos estrictos).

Los SHA del fixture NO se contrastan contra GitHub/Hostinger; las tres declaraciones `*_verified` son afirmaciones de prueba y no acreditan backups ni despliegues. La validación requiere baseline/artifact/plan verdaderos, identidad previa y esperada iguales y diferentes del candidato.

## Salida y límites
Solo una línea JSON `mode=offline_preflight`, `status=SYNTHETIC_ONLY|REJECTED`, `reason` de una allowlist y siempre `rollback_executed=false`, `production_ready=false`, `readiness=UNKNOWN`. Modo real, entrada malformada, duplicada, demasiado larga, extras y fase live devuelven estado 2 sin mostrar contenido de la entrada.

No llama a procesos externos, red, SSH, Hostinger, git ni toca archivos. No emite `ROLLBACK_VERIFIED`, recibos, restore, cambios de configuración o evidencias D-059. **Mantener DEPLOY_ENABLED apagado**: se requiere un adaptador de rollback auténtico separado bajo contrato V3 propio, autoridad D-059, backup restaurable, sonda owner-only exact-SHA y decisión humana de publicación antes de usar el deploy de Factory.

## Pruebas y reversión
Ejecutar `python3 -m unittest tests/test_factory_rollback_preflight.py -v`. Los cuatro casos reproducen negativos y un positivo sintético, incluyendo secretos señuelo y detección de comandos externos. Un revert del squash de #905 mediante PR protegido elimina las tres rutas sin afectar producción. Los issues #625 y #760 siguen pendientes de evidencia operativa real.
