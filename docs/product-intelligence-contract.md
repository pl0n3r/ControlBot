# Product Intelligence Core v1

## Límite

Este contrato representa señales **agregadas** de uso, adopción y outcomes. No instrumenta usuarios, no persiste eventos, no llama proveedores y no decide qué construir. ControlBot conserva referencias y agregados suficientes para razonar sobre producto sin convertirse en un data warehouse transaccional.

## Scope y provenance

Toda señal declara `venture_id`, `product_id`, `surface`, periodo cerrado, `source_ref`, `evidence_ref`, `freshness`, `confidence` y `nature` (`observed|inferred`). Copiar un agregado entre Ventures/Products no es válido: el caller debe aportar el scope esperado y el contrato falla cerrado ante mismatch.

`observed` significa que existe evidencia agregada directa. `inferred` conserva explícitamente que el resultado es una inferencia. Correlación no implica causalidad.

## Ausencia de dato

`unknown` e `insufficient_data` son estados de evidencia, no resultados de negocio. Ambos llevan `value=null`; `insufficient_data` exige que exista una muestra observada mayor a cero. Nunca se convierten en `healthy`, éxito o fracaso por defecto.

## Privacidad por diseño

Funnels y cohortes contienen únicamente nombres de etapa/clave agregada y conteos. No admiten listas de miembros. Referencias con identificadores sensibles (`email`, `user_id`, `customer_id`, `member_id`, `ip_address`) o secretos fallan cerrado.

El contrato no agrega categorías nuevas de datos personales: modela agregados y referencias. Si un slice futuro introduce tracking real, cookies, identificadores, un proveedor de analytics o cambia finalidades, deberá actualizar `datos.yml` y aplicar la puerta legal correspondiente según Factory.

## Revenue outcomes

`revenue_outcome` solo acepta `source_ref` bajo `aggregate:finance/`. La señal puede expresar un agregado financiero, pero no copia transacciones, clientes ni datos de pago.

## Superficies públicas

- `metric()` normaliza una métrica agregada.
- `funnel()` normaliza etapas con conteos no crecientes.
- `cohort()` normaliza tamaño y retención agregados.

No existen métodos de write, provider, tracking, queue o ejecución.
