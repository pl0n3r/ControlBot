# Prompt UI v1
Prompt UI v1 es la superficie read-only de #14 sobre PromptRegistry, PromptEvaluation y PromptPromotionDecision.

## Contrato
PromptRegistry determina la versión approved activa; PromptEvaluation valida set/resultados; PromptPromotionDecision aporta hold|eligible_for_human_approval con human_gate_required=true. La UI solo valida bindings entre template, versiones, evaluation set y fingerprints; no recalcula evaluación ni elegibilidad.

## Fail-closed
eligible_for_human_approval solo habilita puerta humana; hold conserva la versión actual. Evidencia insuficiente, safety/policy failure, fingerprints alterados o bindings incompatibles fallan cerrado. Rollback se muestra solo como historial approved previo y nunca se ejecuta.

## Seguridad y UX
Toda cadena renderizada se escapa y metadata sensible (passwords, secretos, tokens, cookies, credenciales, private/API keys, OTP/recovery codes) se rechaza. Sin DB, red, shell, filesystem write, modelos, Scheduler ni Production Authority. Server-side, mobile-first, viewport, focus-visible, reduced-motion y sin forms/inputs/buttons.

## Reversión
Slice aditivo de cuatro rutas; revertirlo elimina UI, fixtures, tests y documentación sin migraciones ni estado persistente.
