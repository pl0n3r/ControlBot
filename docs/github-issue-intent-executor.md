# ControlBot #761: ejecutor fake de operaciones issue.*
**Solo pruebas offline:** [#880/A y journal v2](https://github.com/pl0n3r/ControlBot/issues/880#issuecomment-6393932306); sin permisos GitHub reales.
`GitHubIssueIntentExecutor::simulate` soporta `issue.create/update/close/reserve/release` exclusivamente sobre estado falso en memoria.
El envelope se valida con `GitHubIntentEnvelope`; los requisitos con `GitHubIntentAuthorityRequirements` y el HMAC owner-only con `GitHubIntentTestGrantAuthority`.
Incluso si el grant de prueba es válido, toda respuesta conserva `decision=deny`, `execution=false`, `runtime_authorized=false`.
`fake_effect.status=simulated` equivale solo a mutación de la fixture; no hay requests reales ni ramas cambiadas.
La reserva vive solo en `fake_state.issues[].reservation_ref`. Cierre de Issue con reserva activa se rechaza.
El ledger ata `idempotency_key` al digest del scope/tipo/parametros/numero fake, rechaza colisiones y reenvíos sin repetir el efecto.
Error o ambigüedad de transporte fake conserva estado, auditoría y ledger. Los recibos no contienen proof HMAC, llaves ni secretos.
Correr AC-01..04: `python3 -m unittest tests.test_github_issue_intent_executor -v`; suite: `python3 scripts/generate_coverage.py`.
Revertir el squash revierte las cuatro rutas. Sin PHP runtime nuevo, transporte HTTP, deploy, Hostinger, datos reales o go-live.
