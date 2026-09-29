# Momentum Campaign v1

`MomentumCampaign` es el primer boundary de MOMENTUM (#126). Normaliza una campaña de marketing sin ejecutar canales ni crear una fuente paralela de autoridad, presupuesto o datos de clientes.

## Contrato

La entrada v1 contiene únicamente identificadores y estado estructural: `campaign_id`, `venture_ref`, refs opacas de objective/audience/offer/CTA, canales cerrados, creative refs, status, budget/authority refs, provenance/freshness y result refs con attribution state.

Los canales v1 son provider-neutral: `organic_social`, `paid_social`, `search_ads`, `email` y `landing`. Los estados son `draft`, `ready`, `active`, `paused`, `completed` y `cancelled`.

Todos los refs salvo `venture_ref` y `campaign_id` son opacos y venture-scoped: `controlbot:venture/<venture>/<kind>/<32 hex>`. Esto impide reutilizar audiencia, creative, budget, authority, provenance o resultados entre Ventures por accidente. Las listas se deduplican y ordenan para producir una representación determinista.

## Autoridad y presupuesto

`budget_ref` referencia presupuesto; no contiene monto y no autoriza gasto. `authority_ref` es opcional y tampoco concede permiso. El contrato no calcula `can_execute`, `spend_allowed` ni authority level. Esos límites siguen perteneciendo a CAPITAL/Decision Rights/Factory según el Business OS.

## Attribution y freshness

`attribution_state` conserva `observed | inferred | unknown`. `observed` e `inferred` exigen evidencia en `result_refs`; `unknown` exige lista vacía. `freshness=unknown` exige `source_ref=null` y `observed_at=null`, por lo que el contrato nunca fabrica provenance.

## Privacidad y seguridad

No hay copy libre, nombres, emails, teléfonos, URLs externas, tokens, secretos ni credenciales. El schema exacto rechaza campos extra. Los refs se validan por namespace, Venture y formato opaco; referencias cross-venture fallan cerrado.

## Límites

Este slice no integra Meta/Instagram/TikTok/Google, no publica, no envía email, no sincroniza CRM, no agenda tareas, no persiste, no ejecuta spend, no crea WorkItems y no llama FactoryRunner. Adapters, consentimiento persistente, paid media, email delivery y CRM se materializarán en slices separados cuando exista autoridad y evidencia para hacerlo.

## Reversión

El cambio añade cuatro archivos sin migraciones ni estado. Revertirlos elimina completamente el contrato v1.
