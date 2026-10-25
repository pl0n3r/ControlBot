# ControlBot #761 — ejecutor fake de intenciones issue.*

## Límite de autoridad

Este componente es **exclusivamente un simulador en memoria** para las cinco acciones
\`issue.create/update/close/reserve/release\`. El OWNER aprobó la puerta específica
[ControlBot #880](https://github.com/pl0n3r/ControlBot/issues/880) (opción A,
journal v2 #6393932306). El permiso no habilita acciones GitHub reales.

\`GitHubIssueIntentExecutor::simulate\` acepta un envelope tipado, una fixture
sintética verificada por \`GitHubIntentTestGrantAuthority\` (HMAC owner-only,
scope/TTL/revocación), un estado fake y ledger/audit in-memory.
Exige \`test_grant_valid=true\`, \`decision=deny\`, \`execution=false\` y
\`runtime_authorized=false\`. El resultado **siempre** conserva \`decision=deny\`
para runtime, y \`fake_effect.status=simulated\` solo indica que mutó una
estructura local que entregó la prueba. Nunca usa curl, GitHub API, token,
base de datos, sesiones, secretos, credenciales ni redes.

\`issue.create\` usa un ID sintético suministrado por la fixture; el resto
requiere coincidencia de número y estado abierto. Reserve/release cambia solo
\`fake_state.issues[].reservation_ref\`, jamás ramas ni Issues de GitHub.
\`issue.close\` rechaza reservas activas. Un error/ambigüedad de transporte
de prueba conserva exactamente el estado, audit y ledger de entrada.

El ledger llave \`idempotency_key\` liga el digest de scope, tipo, parámetros
e ID sintético y conserva un recibo saneado. Reintentar con el mismo digest
devuelve el recibo guardado sin efecto adicional; una llave reutilizada para
otro digest se rechaza. Se devuelven únicamente campos del envelope normalizado,
identificadores fake y códigos de error de una allowlist. El proof HMAC y la
llave de fixture nunca se serializan.

## Aceptación reproducible

\`\`\`bash
python3 -m unittest tests.test_github_issue_intent_executor -v
python3 scripts/generate_coverage.py
\`\`\`

Cubre los AC-01..04: deny sin efectos, create/retry idempotente, transporte
fallido/ambiguo fail-closed y contrato de recibo sin secretos. Las pruebas
invocan PHP únicamente sobre datos sintéticos y no hacen llamadas HTTP.

## Reversión y despliegue

Los cuatro archivos de #761 son independientes de #762 y de la política de
producción. Revertir el squash del PR de #761 elimina el simulador y sus tests.
No autoriza deploy, cambios de \`CapabilityPolicy\`, \`GitHubIntentPolicy\`,
permisos reales, publicación, releases, go-live ni procesamiento de datos reales.
