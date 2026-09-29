# VerifiedAccessContext

## Propósito

`VerifiedAccessContext` marca la frontera nominal entre una consulta mínima de acceso y datos canónicos resueltos por el servidor. Un array con `identity/scope/grant/policies` nunca prueba provenance ni puede acuñar el contexto.

## Trust boundary

```text
query mínima: identity_id + scope + capability
        ↓
VentureAccessSource (inyectada por composition root)
        ↓
VentureAccessSourceContract::resolved()
        ↓
VerifiedAccessContext
        ↓
DecisionRights::evaluate()
```

La UI, requests y agentes solo pueden aportar la query mínima. La implementación de `VentureAccessSource` pertenece al TCB server-side; #218 ya instaló esa dependencia en el composition root. No existe una factory pública que acepte el envelope completo de acceso.

## Propiedades

- el constructor de `VerifiedAccessContext` es privado;
- source + query se revalidan con el contrato canónico de #218;
- identity, grant, scope, policy activa, expiración y material sensible fallan cerrado en ese contrato;
- clone y serialización no reproducen provenance;
- la emisión no muta lifecycle, grants ni audit;
- no se usan HMAC, tokens, cookies, passwords ni credenciales para simular confianza;
- `DecisionRights` conserva la autoridad de allow/deny/owner_decision_required.

## Límite de amenaza

La nominalidad evita autocertificación estructural desde payloads. No intenta defender el mismo proceso contra ejecución arbitraria de PHP capaz de reemplazar dependencias del TCB.

## Integración posterior

#208 debe consumir `VerifiedAccessContext` al rebasar su AuthorityProjection. Este slice no cambia InfrastructureIntent ni ejecuta acciones de infraestructura.
