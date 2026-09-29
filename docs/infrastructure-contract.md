# Infrastructure Center · contrato de inventario v1

Este contrato es la frontera de identidad del Infrastructure & Cloud Control Center (#125). Define **qué recurso existe, a qué provider/account pertenece, cómo se relaciona con Project/Venture/Environment/Service y qué evidencia verificable lo describe**. No observa salud, no ejecuta acciones y no amplía authority.

## Límites

- `InfrastructureProvider` modela providers y accounts mediante `kind`, `vendor`, `adapter_ref`, capabilities y scopes.
- `vendor` es metadata de implementación; **Hostinger, AWS, Cloudflare u otro proveedor no se convierten en tipos del dominio**.
- `InfrastructureResource` usa tipos cerrados: `environment`, `service`, `database`, `storage`, `dns`, `certificate`, `backup`, `network`.
- Relaciones se expresan por refs canónicas a Project, Venture, Environment, Service y recursos padre.
- Release evidence conserva SHA Git de 40 hex + source_ref + observed_at. No infiere release/health desde nombres.
- Cost y backup se representan únicamente por refs; sus contratos autoritativos permanecen fuera de este módulo.

## Provider adapters

Los adapters se descubren por **capability + scope**, no por condicionales vendor-specific. Capabilities v1:

`inventory.read`, `health.read`, `deploy.read`, `backup.read`, `backup.write`, `database.read`, `storage.read`, `dns.read`, `dns.write`, `certificate.read`, `network.read`, `cost.read`.

Scopes: `provider`, `project`, `environment`, `resource`.

Capability, scope, provider kind o resource kind desconocidos fallan cerrado. Añadir uno requiere evolución explícita del contrato; nunca se autoriza por similitud textual.

## Seguridad

Este inventario **no contiene credenciales**. Passwords, private keys, tokens, DSN, cookies o valores de secretos permanecen en Secrets Broker/SecretReference y nunca se copian aquí. Entradas con campos desconocidos, duplicados, refs inválidas o material sensible son rechazadas.

El contrato no concede permisos por el hecho de que un recurso exista. CapabilityPolicy/Production Authority siguen siendo la fuente de autoridad para cualquier acción.

## Determinismo

Inventarios se normalizan y ordenan por IDs canónicos. La misma información produce el mismo resultado independientemente del orden de entrada. Esto permite derived views, drift detection y futuras capas de health sin mantener una base paralela.

## Trade-offs

Se elige un catálogo cerrado pequeño en v1 para evitar un modelo “todo string” que no pueda fallar cerrado. La contrapartida es que providers nuevos pueden requerir ampliar tipos/capabilities. Esa evolución es preferible a aceptar inventario ambiguo o convertir un vendor en semántica del dominio.

Fuera de alcance de #187: red real, discovery de Hostinger/AWS, health, blast radius, budgets, backups reales, secrets, UI, scheduler y acciones productivas.
