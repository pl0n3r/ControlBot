# Business OS identity contract v1

Issue: ControlBot #131 · Parent: #122.

## Boundary

This slice defines only the deterministic domain contract for **Group, Venture, Identity, AccessGrant and GrantEvent**. It does not authorize requests, persist identities, expose an HTTP API or execute owner decisions.

ControlBot remains the authority for business identity and delegation metadata. Product repositories remain authoritative for their own product users. Factory remains authoritative for software work/readiness, and existing Budget/Production Authority/Owner Decision mechanisms remain separate policy inputs.

## Identity separation

`HumanIdentity`, `AgentIdentity` and `ServiceIdentity` share a closed metadata envelope, but the `kind` is explicit and immutable in the normalized representation. Credentials and session material are not part of this contract. Extra fields fail closed, so password hashes, cookies, tokens, OTP/recovery data, reset tokens and session identifiers cannot be smuggled into the identity snapshot.

## Scope and authority

A grant is explicit and contains:

- `identity_id`;
- role and capability;
- one exact `scope` (`group:*`, `venture:*`, `project:*` or `institution:*`);
- one canonical authority level from L0 through L4;
- `policy_ref`;
- optional non-negative `budget_limit`;
- grant and optional expiry timestamps.

There is no inherited cross-venture access. `venture:grindflow` is not evidence for `venture:condor`. Expired or unknown grants are invalid, never healthy defaults.

Canonical levels are:

1. `L0_AI_AUTONOMOUS`
2. `L1_OPERATOR`
3. `L2_VENTURE_ADMIN`
4. `L3_GROUP_INSTITUTION`
5. `L4_OWNER`

This slice records the level but does not decide whether an operation is allowed. That evaluation belongs to #132.

## Revocation and audit evidence

Revocation is pure and scoped: it removes one matching grant while preserving the identity, independent grants and append-only history. Grant/revoke events carry actor, reason, scope, time and expiry when applicable. The event must match the target grant, preventing a revocation event from being replayed against a different identity or scope.

## Next slices

- #132 evaluates capability + scope + authority server-side.
- #133 defines the Identity Center lifecycle commands without centralizing secrets.
- #134 integrates those contracts with staff and the existing Owner Decision flow.
