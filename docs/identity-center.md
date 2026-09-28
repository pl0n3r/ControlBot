# Identity Center lifecycle v1

`IdentityCenter` is a pure server-side contract layered on top of `VentureIdentity` (#131) and `DecisionRights` (#132). It does not act as an IdP and does not store credentials.

## Supported lifecycle commands

Closed commands cover invite/create, suspend/reactivate, scoped grant/revoke, role/capability change, reauth/reset requests, and MFA-required metadata. Every command carries an attributable `command_id`, `idempotency_key`, actor, scope, reason code, timestamp boundary and closed payload.

All valid mutations are evaluated through `DecisionRights`. A denied or owner-gated decision does not mutate identity/grants. Grant creation uses the proposed authority level as the required authority, and role/capability changes never modify `authority_level`.

## Security boundaries

Passwords, password hashes, session/cookie fields, tokens, OTP/recovery/reset values, 2FA secrets and master credentials are rejected recursively. Reauth/reset are represented only as expiring requests. MFA stores only `{required,status}` metadata.

Audit evidence is append-only in the returned state and contains fixed metadata only: command/idempotency IDs, operation, actor, target, scope, outcome, reason code and timestamps. Raw payloads and secrets are never copied into audit evidence.

## State semantics

Scoped revocation removes only the selected grant. Identity, prior audit history and unrelated scopes remain intact. Suspend/reactivate changes only identity state. No persistence, email delivery, SSO, DB schema, UI, product adapter or production action exists in this slice.
