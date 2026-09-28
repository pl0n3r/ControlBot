# Identity Center lifecycle v1

`IdentityCenter` extends `VentureIdentity` (#131) and `DecisionRights` (#132) without becoming an IdP or storing credentials.

It exposes closed, idempotent commands for invite/create, suspend/reactivate, scoped grant/revoke, role/capability changes, reauth/reset requests and MFA-required metadata. The verified actor and scope must exactly match the command before authorization.

Every valid mutation passes through `DecisionRights`. Grant creation uses the proposed authority as the required authority; role/capability changes preserve `authority_level`. Denied or owner-gated decisions do not mutate identity or grants.

Sensitive credential/session fields are recursively rejected. Reauth/reset are expiring requests only; MFA stores `{required,status}` only. Audit evidence contains fixed IDs, actor/target, scope, outcome, reason code and timestamps, never raw payloads.

Scoped revoke removes only the selected grant and preserves identity, prior audit history and unrelated grants. No DB, email delivery, SSO, UI, product adapter or production action exists in this slice.
