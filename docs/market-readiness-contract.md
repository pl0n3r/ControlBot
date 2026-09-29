# Market Readiness por país

Tercer slice de #162. Esta proyección responde qué falta para lanzar un Venture en un país usando evidencia concreta; no reemplaza a los motores fuente ni crea un score agregado.

## Contrato

`MarketReadiness::forCountry()` recibe un `Market` canónico de tipo `country`, el Venture esperado, el país y exactamente trece gates: product, lex, privacy, localization, pricing, payments, support, momentum, capital, infrastructure, aegis, ownership y observability.

Cada gate declara `status`, `evidence_refs`, `observed_at` y `freshness`. Las referencias son opacas y no contienen payloads ni PII directa.

## Freshness y estado efectivo

`status` conserva la observación upstream; `effective_status` es la señal que puede usar readiness.

- `fresh` conserva `ready|blocked|unknown|not_applicable`;
- `stale|unknown` siempre degrada a `effective_status=unknown`;
- una observación fresh o stale requiere evidencia;
- `ready` y `not_applicable` nunca son efectivos sin evidencia fresh;
- `freshness=unknown` usa `observed_at=null`.

Así, una conclusión históricamente `ready` puede seguir visible como dato stale, pero jamás se presenta como readiness vigente.

## Resultado

No hay score numérico. `launch_state` se deriva de gates concretos:

- `blocked` si existe al menos un gate efectivo blocked;
- `unknown` si no hay blocked pero existe algún unknown;
- `ready` solo si todos los gates efectivos son ready o not_applicable.

`missing_domains` enumera únicamente dominios blocked/unknown. El `Market.status` se conserva como contexto, pero `launch_ready` o `live` no fabrican readiness.

## Límite

La proyección exige Market country-scoped y contexto Market fresh. No ejecuta LEX, CAPITAL, AEGIS, MOMENTUM, providers o infraestructura; no persiste, no crea WorkItems y no introduce scheduler/queue paralelo. La materialización de gaps hacia Factory #269 pertenece a un slice posterior.
