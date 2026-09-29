# External API Owner Decision Read v1

`ExternalApiOwnerDecisionRead` proyecta el detalle autenticado de una decisión Owner ya resuelta server-side; no crea HTTP, persistencia ni autoridad nueva.

## Contrato
- Autoriza solo `GET /api/v1/owner-decisions/{decision_id}` mediante `ExternalApiRequestGate`.
- Exige `owner_decision.read`, capability `owner.decision.read`, `mutation=false`, scope y sesión válidos.
- El `decision_id` solicitado debe coincidir exactamente con el detalle.
- Devuelve solo `OwnerDecisionEnvelope`: `meta` + `data{decision_id,category,title,question,options,state,deadline_at}`.
- `meta` reutiliza `ExternalApiContract::responseMeta()`; `freshness=unknown` conserva provenance nulo.
- Options: 2–4, keys únicas `A`–`D`; state: `pending|resolved|expired|blocked`; deadline positivo o `null`.
- Copy con secretos, credenciales o PII directa falla cerrado.

## Límites
No DB, red, storage, cache, push provider, Factory, UI/iOS ni side effects. Reversión: retirar adapter, documentación y tests; no hay migraciones ni estado persistente.
