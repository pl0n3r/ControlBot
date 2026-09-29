# Market Scope + Market contract

Este slice materializa la parte estructural de #162 sin convertir mercado objetivo en jurisdicción legal, infraestructura ni permiso de lanzamiento.

## Market Scope

`MarketScope` es metadata versionada del Venture/Project. Admite `single_country`, `multi_country` y `global`, usa códigos ISO-3166 alpha-2, currency ISO-4217 y locale BCP-47, y normaliza listas sin duplicados.

- `single_country`: exige un `primary_country` y un único target igual al primary.
- `multi_country`: exige al menos dos targets; el primary, si existe, pertenece a ellos.
- `global`: expresa alcance global, pero no autoriza países implícitamente. `launch_countries` siempre enumera países concretos.
- `excluded_countries` no puede solaparse con target/launch.
- `expansion_candidates` permanece separado de target, launch y excluded.

La validación de Market Scope no invoca LEX ni convierte `global` en cumplimiento o go-live.

## Market

`Market` pertenece exactamente a un Venture y usa una geografía cerrada: `country`, `region` o `global`. Sus estados son `researching`, `validating`, `preparing`, `launch_ready`, `live` y `paused`.

`launch_ready` y `live` son estados del contrato, no evidencia de LEX, readiness, presupuesto, seguridad o autorización. Esos gates pertenecen a slices posteriores.

## Límites

El contrato es puro: no persiste datos, no ejecuta providers, no crea scheduler/queue/WorkItems y no duplica motores de LEX, CAPITAL, MOMENTUM o AEGIS. Colombia se representa como `CO` para Condor, pero el núcleo acepta cualquier país ISO válido sin ramas especiales por país.
