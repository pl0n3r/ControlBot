# Factory deploy: preflight sintético sin efectos externos

Parent: ControlBot #625. Hoja técnica #909. Estado: **preparación offline únicamente**.

El adaptador ops/factory/deploy evalúa fixtures sintéticos y no puede publicar archivos.
No habilita DEPLOY_ENABLED, no configura transporte ni Hostinger, no usa credenciales,
no abre el panel, no ejecuta backups o rollbacks y no acredita producción GREEN,
D-043, D-059 ni un recibo de publicación. Los flags enviados son simples afirmaciones
del fixture, nunca evidencia productiva autenticada.

## Ejecución reproducible

Ejecutar la suite: python3 -m unittest discover -s tests -p test_factory_deploy_preflight.py -v

Modo CLI permitido: python3 ops/factory/deploy --offline-dry-run, con JSON UTF-8
sintético por stdin. Entrada máxima 8192 bytes, sin campos duplicados ni claves
extra; exactamente estas 12 claves: kind, phase, transport, candidate_sha,
expected_candidate_sha, artifact_sha256, baseline_sha, expected_baseline_sha,
baseline_verified, artifact_verified, backup_verified y rollback_verified.
Los SHA son 40 hex minúsculos y el digest SHA-256 de 64. El baseline
debe ser distinto del candidato y coincidir con su identidad esperada.
Solo se acepta kind=synthetic, phase=construccion y transport=none.

Se devuelve JSON sanitizado y status=SYNTHETIC_ONLY con código 0 cuando pasa
el fixture o status=REJECTED con código 2 para todo rechazo. Incluso si pasa,
deploy_executed=false, production_ready=false y readiness=UNKNOWN.
No existe un recibo ni marcador de despliegue.

## Límite de autoridad y reversión

Toda invocación real (incluido el modo por defecto sin --offline-dry-run) se
rechaza con código 2. El programa no lee entorno, secretos, archivos externos ni
ejecutables de red; no altera DNS, Hostinger, cron, bases ni usuarios.
Convertirlo en un adaptador productivo requerirá nueva hoja con aprobación
operativa explícita, V3 propia, respaldo/restauración demostrados, rollback,
transporte/secretos con mínimo privilegio, pruebas HTTPS exact-SHA y gates
D-059/D-043/go-live. Revertir el squash elimina el preflight sin efectos externos.
