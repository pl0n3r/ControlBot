# External API Authenticated Read v1

## Objetivo

`ExternalApiAuthenticatedRead` une la autorización server-side ya existente con la proyección pública read-only de Cockpit y Owner Inbox.

No crea un segundo modelo de datos ni una capa de autorización paralela.

## Flujo

```text
VerifiedAccessContext
+ VerifiedExternalSessionContext
+ expected scope
→ ExternalApiRequestGate::authorize()
→ decision=allow + operation_id exacto + mutation=false
→ ExternalApiReadProjection
→ DTO público
```

## Operaciones

- `GET /api/v1/cockpit` → `cockpit.read`
- `GET /api/v1/owner-inbox` → `owner_inbox.read`

El adaptador rechaza cualquier combinación en la que el request gate no devuelva `allow`, el operation ID no sea el esperado, la operación sea mutante o el scope no coincida.

## Fronteras de seguridad

La respuesta pública se obtiene exclusivamente desde `ExternalApiReadProjection`. No se propagan:

- identity ni authority;
- policy/grant;
- session/device/step-up refs;
- tokens o credenciales;
- resultados internos del gate.

Sesiones revocadas, contextos incompatibles o scopes cruzados fallan antes de construir el payload público.

## Pureza

El adaptador no realiza DB, red, storage, cache, push, UI, Factory, dispatch ni ejecución. No persiste estado y no reinterpreta freshness/provenance.

## Fuera de alcance

HTTP routing real, collectors, persistencia, iOS, mutaciones Owner Decision, push y WorkItems.
