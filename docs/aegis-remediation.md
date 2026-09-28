# AEGIS Remediation v1

## Objetivo

`AegisRemediation` modela el tramo `POLICY/AUTHORITY → REMEDIATE|ESCALATE → VERIFY → EVIDENCE` sin ejecutar la acción. Es una capa read-only y determinista.

## Boundary de autoridad

La capa reutiliza `DecisionRights::evaluate()`. Policy, grants, budget y authority solo restringen. Nunca elevan una capability, un scope ni un nivel de autoridad.

No hay shell, HTTP, SDK de provider, mutación de producción ni autoaprobación L4.

## Proposal

Cada proposal declara:

- `action` y `capability`;
- `scope` exacto;
- `reversible`;
- `blast_radius: low|medium|high`;
- `risk_categories[]`;
- `preauthorized`;
- `required_authority_level`;
- `policy_ref`;
- `budget_amount` opcional.

El `policy_ref` debe coincidir con el grant y estar activo en el contexto verificado.

## Decisiones

`auto_eligible` exige simultáneamente:

- Decision Rights = `allow`;
- policy binding válido;
- preautorización explícita;
- reversible;
- blast radius `low`;
- authority requerida `L0_AI_AUTONOMOUS`;
- cero categorías de riesgo alto.

`privileged|secrets|deletion|money|privacy|legal`, una acción irreversible o blast radius `high` producen `owner_decision_required`. Un contexto/grant/policy inválido produce `deny`. Una acción autorizada pero no automática queda `manual_required`.

## Idempotencia

La key es SHA-256 estable de `finding_id + action + scope`, con prefijo `aegis-remediation:`. `planBatch()` deduplica entradas idénticas por esa key y falla si la misma key produce planes incompatibles.

## Verification

Estados: `not_attempted|attempted|verified|failed`.

`remediation_state=success` solo puede existir con `verification.status=verified` y al menos una `evidence_ref` válida. `verified` sin evidencia se rechaza.

## Determinismo y seguridad

Risk categories y evidence refs se deduplican y ordenan. IDs, scopes y evidence refs rechazan marcadores sensibles. El mismo input lógico produce el mismo plan y reasons.

## Contrato ejecutable

`tests/test_aegis_remediation.py` cubre AC-01..AC-07 mediante `tests/aegis_remediation_scenarios.php`.

## Fuera de alcance

No provider adapters, shell, HTTP, ejecución de remediation, creación de Owner Decision, DB, UI, Factory WorkItems ni Living feedback. Esos enlaces pertenecen al siguiente slice.
