# ControlBot — ejecutor sintético de workflows y freeze/unfreeze (#763)

## Autoridad y alcance

La decisión específica del propietario #877/A y su journal factory-human-decision v2 autorizan exclusivamente un harness offline para workflow.dispatch, project.freeze y project.unfreeze. Los grants de prueba de #874 con modo offline_test_only NO autorizan operaciones reales. GitHubIntentPolicy y CapabilityPolicy conservan DENY. Simulated únicamente significa que se actualizó una estructura local de prueba, nunca que se ejecutó GitHub o un freeze productivo.

## Contrato puro

GitHubWorkflowIntentExecutor::simulate(rawEnvelope, fixture, fakeState, audit, ledger) recibe arrays y devuelve arrays. No recibe tokens, cliente HTTP, callbacks, persistencia, archivos ni transportes externos.

1. Normaliza GitHubIntentEnvelope y compara estrictamente los requisitos derivados con GitHubIntentAuthorityRequirements::describe.
2. Verifica owner, scope, TTL, nonce, no replay ni revocación con GitHubIntentTestGrantAuthority::evaluate; incluso una prueba válida devuelve decision=deny, execution=false, runtime_authorized=false.
3. Para workflow.dispatch exige allowlists explícitas de workflow y ref, inputs_ref apuntando exclusivamente a controlbot:fixture/, hasta ocho strings cortos y sin indicadores de secretos. Un resultado fake success sólo añade workflow/ref a fake_state.dispatches.
4. Freeze y unfreeze mutan sólo fake_state.frozen y registran un evento reversible. Auditoría saneada con hash del intent, tipo, estado, ámbito y bandera reversible; nunca se devuelven entradas, firmas, keys ni nonces.
5. Repetir idempotency_key con fingerprint idéntico usa replay sin duplicar efecto/auditoría; una colisión divergente devuelve denied. Outcomes fake failure y ambiguous nunca cambian estado, ledger ni auditoría.

## AC y pruebas

Ejecutar: python3 -m unittest tests.test_github_workflow_intent_executor -v.
Contiene cuatro AC de #763 en PHP sin conexión a proveedor. El runner tests/github_workflow_intent_executor_scenarios.php acepta exclusivamente una fixture JSON en base64, máximo 32 KiB, y devuelve errores saneados sin imprimir entrada o backtrace.

El estado fake y la bitácora no son persistentes, no acreditan ejecución productiva y siempre tienen decision=deny, execution=false. Estados posibles: simulated, denied, failed, ambiguous.

## Fuera de alcance

Prohibidos tokens reales, GitHub API, workflow_dispatch real, capacidades runtime, efectos live, credenciales, almacenamiento de PII, gastos, despliegue, go-live o cambios de políticas. La fusión técnica no modifica estos límites.