# Ejecutor fake de workflow y freeze/unfreeze (#763)

**Autoridad:** #877/A y journal v2 autentican sólo simulación con offline_test_only. El resultado siempre preserva DENY (decision=deny, execution=false, runtime_authorized=false); no concede GitHub runtime ni acciones live.

**Contrato puro:** GitHubWorkflowIntentExecutor::simulate(rawEnvelope, fixture, fakeState, audit, ledger) acepta sólo arrays. Valida GitHubIntentEnvelope, scope, owner, aprobación y HMAC de fixture mediante GitHubIntentTestGrantAuthority. No acepta HTTP, claves de servicio, callbacks, archivos ni estado duradero.

**workflow.dispatch:** requiere workflow y git ref en allowlist exacta, inputs_ref local controlbot:fixture/ y entradas de texto pequeñas (máximo ocho); bloquea valores/identificadores sensibles. Éxito fake añade sólo workflow/ref a fake_state.dispatches.
**Freeze/unfreeze:** cambia exclusivamente fake_state.frozen. Cada resultado simulated añade auditoría saneada con hash de intent, tipo, ámbito y reversibilidad. La clave de idempotencia evita repetir efectos y una colisión diferente se rechaza. Failed/ambiguous no alteran estado ni auditoría.

**Pruebas:** python3 -m unittest tests.test_github_workflow_intent_executor -v ejecuta los cuatro AC de #763. El runner PHP acepta fixture sintética base64 de hasta 32 KiB, devuelve exclusivamente JSON saneado y no imprime entradas o trazas.

**Límites:** ningún GitHub workflow_dispatch real, token, permiso runtime, servicio de producción, deploy, cambio Hostinger, dinero, PII ni go-live. Simulated describe solo un cambio fake en memoria.