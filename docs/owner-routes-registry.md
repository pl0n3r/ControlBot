# Owner route registry — ControlBot #1445

## Contract and authority
The OpenAPI server prefix is `/api/v1`. Four routes are registered by a discoverable PHP module in `src/Routing/Routes/OwnerApiRoutes.php`: `GET /cockpit`, `GET /owner-inbox`, `GET /owner-decisions/{decision_id}` and `POST /owner-decisions/{decision_id}/decision`. All responses, including errors, set `Cache-Control: no-store` and deny framing. The router never trusts `REMOTE_USER`, request flags, cookies or a caller-provided identity. It validates the server-managed OAuth owner session, sealed GitHub token and fresh TOTP through `OwnerSessionService`.

The POST requires `X-CSRF-Token` equal to the server session's secret, with `Content-Type: application/json`, a size limit and strict `ExternalApiContract::decisionMutation` fields. No CSRF in the JSON contract; the client controls no trusted server work intent, identity or capability. Unknown routes/verbs fail closed.

## Modules, security and backend
`OwnerAuthenticatedRouter::dispatch()` scans only local regular PHP files in `src/Routing/Routes`, validates definitions and rejects duplicates. Adding another module needs only that file, not editing the router. `OwnerApiRoutes.php` uses `ExternalApiReadProjection`, `OwnerInbox::collection`, `ExternalApiOwnerDecisionRead::detail`, and server-bound decision execution. The external decision read requires a verified capability grant and external session; no bare owner ID substitutes those objects. A write returns 200 **only after** its trusted adapter has durably executed/recorded a `DecisionRuntime` action and returned matching committed evidence. Missing or malformed adapters produce 503; they do not fabricate entries, live authority, completion or default approvals.

The HTTP entrypoint must call `dispatch()` **after** `handleAuthenticated` validates OAuth+TOTP. That adapter is a separately claimed part of this same issue through V3 contract renewal after the initial PR opens. The local password-login path must not be upgraded to OAuth authority by inferred login; it remains forbidden for these API routes. Server adapters are not activated by this offline change: per D-059, the deployment, provider, credentials, production decisions, database access and real observations remain **UNKNOWN** and out of scope. The acceptance PHP scenarios exercise safe synthetic resolvers, not live systems.

## Tests
Run `python3 -m unittest -v tests.test_owner_routes_registry`. Contract entry points: `RoutesTests.test_criterio_principal` and `RoutesTests.test_criterio_seguridad_y_bordes`. The first uses genuine encrypted owner OAuth/TOTP session fixtures and synthetic authorized backend replies for all four OpenAPI paths. The second checks anonymous/wrong owner, stale TOTP, wrong CSRF, malformed/oversize JSON, path traversal, bogus routes/verbs, absent backend, duplicate registrations, and a standalone extra route file. No credentials, external API or provider state are used.

Reversal is the squash revert; no deployment or production data mutation accompanies the PR.
