# Vendor Registry Core

Este contrato es el primer slice ejecutable de #186. ControlBot mantiene el inventario ejecutivo y lifecycle de dependencias externas; CAPITAL conserva autoridad económica y AEGIS/LEX conservan seguridad, privacidad y compliance.

## Scope y autoridad

Cada VendorRecord pertenece a un único Venture. Los bloques de costo, revisión AEGIS y revisión LEX repiten venture_id únicamente para detectar mezcla cross-Venture y fallan cerrado si no coincide.

- cost.capital_ref apunta a CAPITAL;
- security_review.aegis_review_ref apunta a AEGIS;
- legal_review.lex_review_ref apunta a LEX.

El registry no copia authority level, decisiones, budgets ni approvals.

## Referencias opacas

Vendor, service, owner, contract, data categories, subprocessors, credential locator, exit/export plans y evidence usan refs namespace:<32hex>. Son identificadores, no material sensible. La opacidad y ausencia de PII siguen siendo obligación del productor; nombres, contactos o valores semánticos no deben codificarse en esas refs.

## Lifecycle, salud y freshness

Lifecycle: evaluating, approved, active, changing, offboarding, exited.

Health/SLA: healthy, degraded, unknown. Freshness: fresh, stale, unknown. Un registro stale o unknown no puede afirmar health=healthy. Freshness unknown no inventa observed_at ni source_ref.

Renewal y expiry son Unix epoch seconds o null. Si ambos existen, renewal_at no puede ocurrir después de expiry_at.

## Offboarding

El bloque offboarding contiene únicamente requisitos/ref para export, revocation, retention, continuity y evidence. No ejecuta borrado, rotación, sustitución ni compras.

## Límite operativo

Este core no compra, paga, provisiona, renueva, llama providers, persiste datos ni crea scheduler/queue/WorkItem. Acciones futuras se materializarán por los contratos de CAPITAL/AEGIS/LEX/Factory correspondientes.