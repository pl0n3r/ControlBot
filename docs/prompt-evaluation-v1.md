# PromptEvaluation v1

PromptEvaluation es el boundary puro entre resultados reproducibles de evaluación y una futura decisión de promoción. No cambia estados del PromptRegistry ni ejecuta prompts.

## Contrato

Un EvaluationSet fija `evaluation_set_id`, `version`, `task_class`, `sample_size` y el conjunto ordenado de métricas. Cada EvaluationResult debe corresponder exactamente con ese contrato.

Métricas soportadas:
- `acceptance_rate`: mayor es mejor.
- `rework_rate`, `review_findings_rate`, `handoff_rate`, `latency_ms`, `cost_units`: menor es mejor.

La comparación es estricta y fail-closed. Solo produce `candidate_better` cuando el candidato mejora al menos una métrica, no empeora ninguna, tiene muestra suficiente y pasa safety + policy. Empate, evidencia insuficiente o trade-off mixto mantienen `keep_current`. La salida conserva `template_id` dentro del fingerprint para que la evidencia no pueda reutilizarse entre templates que compartan números de versión.

## Provenance de resultados

`resultFingerprint()` normaliza un EvaluationResult con las mismas reglas de `result()` y calcula un SHA-256 sobre el resultado completo: identidad, versión, evaluation set, sample size, métricas ordenadas y `safety_result`/`policy_result`.

`compare()` publica `current_result_fingerprint` y `candidate_result_fingerprint`, y ambos forman parte del fingerprint canónico de la evaluación. Cambiar una métrica o un resultado safety/policy cambia el fingerprint del resultado afectado y también invalida el fingerprint previo de la evaluación.

Esto permite que consumidores read-only verifiquen que los resultados mostrados son exactamente los que originaron la evidencia sin volver a ejecutar la comparación.

## Seguridad y autoridad

Un `safety_result=fail` o `policy_result=fail` descalifica al candidato sin importar sus métricas. La salida declara `authority=advisory_only`: este módulo no aprueba, promueve ni muta versiones.

Se rechazan campos extra, datasets incompatibles, métricas desconocidas, valores no finitos, muestras inválidas y metadata con señales de secretos.

## Determinismo

El EvaluationSet y las métricas se ordenan canónicamente antes del fingerprint. Permutar el orden de métricas no cambia la decisión ni los fingerprints SHA-256 resultantes.

## Fuera de alcance

Sin DB, red, shell, filesystem write, provider/model execution, Scheduler mutation, canary, UI o promoción automática. El siguiente slice puede consumir esta decisión como evidencia, pero debe conservar las puertas de autoridad vigentes.
