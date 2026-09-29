# VerifiedAccessContext

## Propósito

`VerifiedAccessContext` marca la frontera nominal entre una consulta mínima de acceso y datos canónicos resueltos por el servidor. Un array con `identity/scope/grant/policies` nunca prueba provenance ni puede acuñar el contexto.

## Trust boundary

```text
query mínima: identity_id + scope + capability
        ↓
DecisionRuntime (composition root / sesión / allowlist)
        ↓
VentureAccessSource privada inyectada por #218
        ↓
VentureAccessSourceContract::resolved()
        ↓
VerifiedAccessContext
        ↓
DecisionRights::evaluate()
```

La UI, requests y agentes solo pueden aportar la query mínima a la frontera server-side. `VerifiedAccessContext` no acepta `VentureAccessSource`: exige un `DecisionRuntime` final, que conserva la fuente como dependencia privada instalada por el composition root de #218. Un source arbitrario implementado por un caller no satisface la API de emisión.

## Propiedades

- el constructor de `VerifiedAccessContext` es privado;
- `DecisionRuntime` autentica la sesión, aplica allowlist y resuelve la query mínima con su source privado;
- el resultado se revalida con el contrato canónico de #218;
- identity, grant, scope, policy activa, expiración y material sensible fallan cerrado en ese contrato;
- clone y serialización no reproducen provenance;
- la emisión no muta lifecycle, grants ni audit;
- no se usan HMAC, tokens, cookies, passwords ni credenciales para simular confianza;
- `DecisionRights` conserva la autoridad de allow/deny/owner_decision_required.

## Límite de amenaza

La nominalidad evita autocertificación estructural desde payloads. No intenta defender el mismo proceso contra ejecución arbitraria de PHP capaz de reemplazar dependencias del TCB.

## Integración posterior

#208 debe consumir `VerifiedAccessContext` al rebasar su AuthorityProjection. Este slice no cambia InfrastructureIntent ni ejecuta acciones de infraestructura.
