# External API Owner Decision Read v1

## Objetivo

`ExternalApiOwnerDecisionRead` conecta el deep-link autenticado de las notificaciones Owner con el detalle público ya definido por `OwnerDecisionEnvelope`.

No crea transporte HTTP, autenticación, persistencia ni otra fuente de autoridad.

## Flujo

```text
VerifiedAccessContext
+ VerifiedExternalSessionContext
+ expected scope
+ requested decision_id
+ server-side decision detail
→ ExternalApiRequestGate::authorize()
→ owner_decision.read / owner.decision.read / mutation=false
→ decision_id exact-match
→ OwnerDecisionEnvelope
```

## Fronteras de seguridad

- Solo se autoriza la operación GET pública ya declarada.
- El `decision_id` solicitado debe coincidir con el detalle server-side.
- Sesión revocada, scope/capability incorrectos o una decisión distinta fallan cerrado.
- El cliente no aporta identity, authority, policy, grants, session/device/step-up ni campos de ejecución.
- La salida contiene únicamente `meta` y los campos públicos del `OwnerDecisionData` definido en OpenAPI.
- Copy que parezca secreto/credencial o PII directa se rechaza antes de construir la respuesta pública.
- `freshness=unknown` conserva `observed_at=null` y `source_ref=null`; no se inventa provenance.

## Contrato público

`data` contiene exactamente:

- `decision_id` y `category` como slugs cerrados;
- `title` y `question` dentro de los límites del OpenAPI;
- `options` de 2 a 4 elementos con keys únicas `A`–`D`;
- `state` en `pending|resolved|expired|blocked`;
- `deadline_at` positivo o `null`.

`meta` delega en `ExternalApiContract::responseMeta()` para conservar `request_id`, `correlation_id`, `generated_at` y `freshness`.

## Pureza y reversión

El adapter no realiza DB, red, storage, cache, push, UI, iOS, Factory ni side effects. Recibe el detalle ya resuelto por el caller y únicamente autoriza + proyecta.

La reversión consiste en retirar este adapter, su documentación y sus regresiones. No hay migraciones ni estado persistente.
