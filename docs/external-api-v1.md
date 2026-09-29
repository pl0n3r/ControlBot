# External Owner API v1 — contract first

Fase 0 de #128: contrato público machine-readable para clientes del Owner. No implementa servidor HTTP, login, sesión real ni cliente iOS.

## Trust boundary
`cliente -> sesión validada/composition root -> DecisionRuntime -> VerifiedAccessContext -> DecisionRights -> operación`

La autoridad nunca proviene del payload. `identity`, `role`, `authority_level`, `policy_ref`, grants y credenciales de proveedores son server-side y cualquier aparición en la mutación falla por schema cerrado.

## Superficie Fase 0
- `GET /api/v1/cockpit` → `owner.cockpit.read`
- `GET /api/v1/owner-inbox` → `owner.inbox.read`
- `GET /api/v1/owner-decisions/{decision_id}` → `owner.decision.read`
- `POST /api/v1/owner-decisions/{decision_id}/decision` → `owner.decision.write`

Los scopes son requisitos server-side. Un slice posterior los mapeará a #122/VerifiedAccessContext; el cliente no los declara. Cada operación devuelve un DTO cerrado propio: ventures con health resumido, entradas de Owner Inbox, detalle/opciones de Owner Decision o resultado auditado de la mutación; no requiere rutas ocultas para resolver referencias.

## Correlation, freshness y errores
Toda operación exige `X-Request-ID` y `X-Correlation-ID` opacos. `generated_at`, `observed_at` y deadlines son Unix epoch en segundos (UTC). Snapshots usan freshness `current|stale|unknown`; `unknown` conserva `observed_at/source_ref=null` y nunca inventa provenance.

Mapping público: `400=invalid_request`, `401=unauthenticated`, `403=forbidden`, `404=not_found`, `409=conflict|stale_state`, `429=rate_limited`, `500=internal_error`; `default` queda solo para estados no declarados. No se exponen stack traces ni modelos internos.

## Mutaciones
La única mutación acepta exactamente `version=1`, `outcome=approve|reject` e `idempotency_key` opaca. GET no tiene request body.

## Límite operativo
No hay SSH, SQL, secretos, FactoryRunner, acceso directo a providers, scheduler, queue ni execution path paralelo. Futuras órdenes ejecutables continúan por ControlBot/Factory y sus gates canónicos.
