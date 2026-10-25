# ControlBot #875 — Harness de prueba HMAC y efecto falso (offline)

**Autoridad:** decisión OWNER #878/A con journal v2, aplicable **solo si** #763 quedó fusionado y el preflight exact-main confirmó los cuatro AC propios pendientes. Este archivo describe una prueba de composición, no un executor GitHub. Dependencias ya fusionadas: #869, #874 y #763.

## Alcance y contrato

`src/GitHubIntentOfflineGrantHarness.php` recibe únicamente arrays y una clave **sintética del test** inyectada por el runner local. Primero verifica la coincidencia exacta de tipo, scope_ref y `controlbot:fixture/...`; luego llama a `GitHubIntentTestGrantAuthority::evaluate()`, que compone el emisor HMAC y el verificador existente. Cualquier inconsistencia, revocación, nonce visto, TTL o aprobación inválida retorna DENY.

En el caso válido **únicamente** devuelve `fixture_status=accepted_offline` y `fake_effect.status=simulated` con digest estable de la prueba firmada. Nunca ejecuta `workflow.dispatch`, `project.freeze`, un Issue o un PR; no cambia estado, ledger o audit de #763, no invoca transportes, red, GitHub API, filesystem, callbacks, Hostinger, ni emite grants.

En **todos** los caminos, incluido el caso "simulated", la salida conserva `decision=deny`, `execution=false`, `runtime_authorized=false`, `authorized=false`, `grant_issued=false`, `approval_issued=false`, `approval_verified=false`. La clave sintética y el proof HMAC completo no aparecen en la salida. Un digest no es un recibo de aprobación o permiso. Datos incompletos, campos inesperados o entradas de cliente devuelven `fixture_status=rejected` / `fake_effect.status=denied`.

El nuevo harness es distinto del ejecutor fake #763: no recibe intent envelopes, ni almacena idempotencia, ni modifica modelos de estado. Es una regresión de frontera *aprobación → firma → aceptación del fixture* únicamente.

## Comprobación reproducible

```bash
python3 -m unittest discover -s tests -p 'test_github_intent_offline_grant_harness.py' -v
php -l src/GitHubIntentOfflineGrantHarness.php
```

Las cuatro pruebas nominales AC-01..04 usan `php -r` para invocar la clase real sin añadir un cuarto archivo a los claims: owner, scope, aprobación, firma/determinismo, replay, revocación, TTL y prohibición de ejecución productiva. CI completo, aceptación ejecutable, Sonar/CodeQL y CodeRabbit FINAL sobre HEAD definitivo se validan en el PR.

**Limitación:** toda la evidencia es sintética en fase `construccion`; ninguna prueba o merge acredita un entorno desplegado, backups/restore, Hostinger, permisos productivos o go-live. Reversión: revert del squash del PR #875, sin migración ni datos que restaurar.
