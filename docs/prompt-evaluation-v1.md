# PromptEvaluation v1

PromptEvaluation es el boundary puro entre resultados reproducibles de evaluación y una futura decisión de promoción. No cambia estados del PromptRegistry ni ejecuta prompts.

## Contrato

Un EvaluationSet fija `evaluation_set_id`, `version`, `task_class`, `sample_size` y el conjunto ordenado de métricas. Cada EvaluationResult debe corresponder exactamente con ese contrato.

Métricas soportadas:
- `acceptance_rate`: mayor es mejor.
- `rework_rate`, `review_findings_rate`, `handoff_rate`, `latency_ms`, `cost_units`: menor es mejor.

La comparación es estricta y fail-closed. Solo produce `candidate_better` cuando el candidato mejora al menos una métrica, no empeora ninguna, tiene muestra suficiente y pasa safety + policy. Empate, evidencia insuficiente o trade-off mixto mantienen `keep_current`. La salida conserva `template_id` dentro del fingerprint para que la evidencia no pueda reutilizarse entre templates que compartan números de versión.

## Seguridad y autoridad

Un `safety_result=fail` o `policy_result=fail` descalifica al candidato sin importar sus métricas. La salida declara `authority=advisory_only`: este módulo no aprueba, promueve ni muta versiones.

Se rechazan campos extra, datasets incompatibles, métricas desconocidas, valores no finitos, muestras inválidas y metadata con señales de secretos.

## Determinismo

El EvaluationSet y las métricas se ordenan canónicamente antes del fingerprint. Permutar el orden de métricas no cambia la decisión ni el SHA-256 resultante.

## Fuera de alcance

Sin DB, red, shell, filesystem write, provider/model execution, Scheduler mutation, canary, UI o promoción automática. El siguiente slice puede consumir esta decisión como evidencia, pero debe conservar las puertas de autoridad vigentes.
