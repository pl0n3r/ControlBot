# MOMENTUM Paid Media Governance v2

#434 cierra el trust boundary detectado tras #432. `MomentumPaidMedia` sigue siendo planificación pura: no ejecuta anuncios, pagos, colas ni adapters.

## Authority nominal
La entrada recibe un `VerifiedAccessContext` emitido por `DecisionRuntime`; copias estructurales no autorizan. El caller no suministra capability ni authority: `launch|pause|reallocate` usan server-side `momentum.paid_media.plan` con mínimo `L2_VENTURE_ADMIN` porque todas portan spend/CAPITAL. La policy canónica debe estar activa y `grant.policy_ref` debe ser exactamente `controlbot:policy/business-os-v1`.

## Evidencia y freshness
`current` se valida contra `$now`: timestamps futuros → `evidence_from_future`; edad >300s → `evidence_expired`; `stale|unknown` fallan cerrado. `spend_ref`, `evidence_refs` y `blast_radius_ref` son referencias opacas y deterministas. Blast radius es evidencia descriptiva **no autoritativa**: no rebaja ni eleva authority por sí solo.

## CAPITAL y límites
Campaign/Venture, budget, moneda y proposal amount deben coincidir con CAPITAL. `reallocate` exige reallocation; expansión del total mantiene Owner Decision. Toda salida conserva `execution=false`. No hay provider APIs, pagos, scheduler, persistencia, FactoryRunner, credenciales, PII ni RBAC paralelo.
