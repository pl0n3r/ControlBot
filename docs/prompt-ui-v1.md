# Prompt UI v1

## Propósito

Prompt UI v1 materializa la superficie read-only de #14 sobre los contratos ya integrados de PromptRegistry, PromptEvaluation y PromptPromotionDecision. La vista muestra versión activa, candidata, historial, evaluation set, métricas, decisión y razones sin promover, ejecutar ni revertir prompts.

## Fuente de verdad

La UI no mantiene una policy propia.

- PromptRegistry valida la cadena de versiones y determina la versión `approved` activa.
- PromptEvaluation valida el evaluation set y los resultados ya calculados.
- PromptPromotionDecision aporta la proyección `hold|eligible_for_human_approval`, siempre con `human_gate_required=true`.
- Prompt UI valida bindings entre template/versiones/evaluation set y fingerprints, pero no llama `PromptEvaluation::compare()` ni `PromptPromotionDecision::decide()`.

## Estados y fail-closed

- `eligible_for_human_approval` significa únicamente que la evidencia puede elevarse a una puerta humana; no significa promoted ni ejecución autorizada.
- `hold` conserva la versión aprobada vigente.
- Evidencia insuficiente, safety/policy failure, fingerprints alterados, versiones incompatibles o bindings incompletos fallan cerrado.
- Rollback se muestra solo como historial de versiones previamente `approved`; la UI no ejecuta rollback ni reescribe historial.

## Seguridad y privacidad

Toda metadata se revalida mediante los contratos existentes y toda cadena renderizada pasa por escaping HTML. Metadata sensible —passwords, secretos, tokens, cookies, credenciales, private/API keys, OTP o recovery codes— se rechaza antes de renderizar.

La implementación no usa DB, red, shell, filesystem write, ejecución de modelos, Scheduler ni Production Authority.

## UX

La salida es server-side, mobile-first y accesible:

- `viewport` explícito;
- estructura semántica y labels;
- focus-visible;
- reduced-motion;
- sin formularios, inputs ni botones;
- fingerprints y evidencia legibles sin convertirlos en acciones.

## Reversión

El slice es aditivo y limitado a cuatro rutas. Revertir el commit/PR elimina la UI, escenarios, tests y este documento sin migraciones ni estado persistente.
