# Venture access source server-side

## Frontera

El request de infraestructura solo puede expresar `identity_id`, `scope` y `capability`. No puede suministrar grant, authority level, policy refs ni una decisión.

`DecisionRuntime` mantiene `VentureAccessSource` como dependencia privada instalada por el composition root. La fuente resuelve el snapshot canónico y el runtime lo valida contra la consulta antes de entregarlo a la siguiente capa.

```text
request refs ──> DecisionRuntime ──> injected VentureAccessSource
                                  │
                                  └─> normalized identity/grant/policies
```

## Fail-closed

Sin source configurado, identidad/grant ausente, scope/capability distinto o policy inactiva, no existe contexto resoluble. Resolver es read-only y no ejecuta lifecycle, providers ni auditoría.

El fake usado en tests se inyecta al construir el runtime; nunca forma parte del request. En producción, el adapter persistente se instala en el composition root cuando exista almacenamiento real. Hasta entonces la capacidad permanece no configurada y falla cerrado.

## Encadenamiento

#218 establece la fuente confiable. #210 debe consumir esta salida para acuñar el objeto nominal; #208 consume después ese objeto action-bound. Ninguna de esas capas debe volver a aceptar grant/policies desde caller/UI.
