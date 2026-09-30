# Prompt UI v1
Prompt UI v1 es la superficie read-only de #14 sobre PromptRegistry, PromptEvaluation y PromptPromotionDecision.

## Contrato
PromptRegistry determina la versión approved activa; PromptEvaluation valida set/resultados y produce fingerprints de los resultados normalizados; PromptPromotionDecision propaga esos fingerprints junto con `hold|eligible_for_human_approval` y `human_gate_required=true`. La UI valida bindings entre template, versiones, evaluation set, decisión y fingerprints; no recalcula evaluación ni elegibilidad.

## Provenance
Los fingerprints `current_result_fingerprint` y `candidate_result_fingerprint` enlazan la decisión con las métricas y resultados safety/policy exactos que la UI renderiza. PromptUi vuelve a normalizar los resultados y exige equivalencia con `PromptEvaluation::resultFingerprint()`. Sustituir métricas, safety/policy o la identidad del evaluation set falla cerrado aunque template y versiones sigan iguales.

## Fail-closed
`eligible_for_human_approval` solo habilita puerta humana; `hold` conserva la versión actual. Evidencia insuficiente, safety/policy failure, fingerprints alterados o bindings incompatibles fallan cerrado. Rollback se muestra solo como historial approved previo y nunca se ejecuta.

## Seguridad y UX
Toda cadena renderizada se escapa y metadata sensible (passwords, secretos, tokens, cookies, credenciales, private/API keys, OTP/recovery codes) se rechaza. Sin DB, red, shell, filesystem write, modelos, Scheduler ni Production Authority. Server-side, mobile-first, viewport, focus-visible, reduced-motion y sin forms/inputs/buttons.

## Reversión
El binding es aditivo y no persiste estado. Revertir las rutas de Prompt UI elimina la proyección y sus pruebas sin migraciones ni side effects.
