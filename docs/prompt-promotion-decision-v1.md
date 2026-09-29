# PromptPromotionDecision v1

`PromptPromotionDecision` convierte evidencia de `PromptEvaluation v1` en una decisión **advisory** de elegibilidad. No cambia estados del registro ni aprueba nada por sí mismo.

## Trust boundary

La entrada incluye el historial completo de `PromptRegistry`, el `template_id`, la versión candidate y una decisión emitida por `PromptEvaluation`.

`PromptRegistry::active()` valida todo el historial antes de que este módulo seleccione el candidate. La evaluación se vuelve a validar por shape, `template_id`, versiones, authority y fingerprint SHA-256.

## Reglas

Solo puede resultar `eligible_for_human_approval` cuando:

- current está `approved`;
- candidate está `candidate`;
- candidate es sucesor directo de current mediante `supersedes`;
- template, task_class, provider_scope y variables_schema coinciden;
- la evaluación liga exactamente `template_id` y ambas versiones;
- la evaluación conserva `authority=advisory_only`;
- la decisión de evaluación es `candidate_better` y contiene evidencia `candidate_dominates_current`.

Una evaluación válida con `keep_current` produce `hold`. Drift, fingerprint alterado, authority distinta o contrato incoherente fallan cerrado.

## Puerta humana

Toda salida incluye:

- `human_gate_required=true`;
- `authority=human_approval_required`.

Esto significa que el resultado solo puede alimentar una decisión humana posterior. Este slice nunca transforma `candidate → approved`, nunca edita historial y nunca amplía Production Authority.

## Determinismo

La salida usa razones allowlisted y fingerprint SHA-256 canónico. Los mismos inputs producen la misma decisión, razones y fingerprint.

## Fuera de alcance

Sin DB, red, filesystem write, shell, providers/modelos, Scheduler, canary, rollout, UI o mutaciones de PromptRegistry.
