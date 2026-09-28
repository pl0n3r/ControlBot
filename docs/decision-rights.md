# Decision Rights v1

Issue: ControlBot #132 · Parent: #122 · Depends on #131.

## Boundary

This slice evaluates **authorization decisions only**. It consumes the identity and `AccessGrant` contract from #131 and returns one of:

- `allow`;
- `deny`;
- `owner_decision_required`.

It does not persist grants, execute an action, create an Owner Decision, move money, expose an HTTP endpoint or mutate product state.

## Trust boundary

`DecisionRights::evaluate()` receives three distinct inputs:

1. `verifiedContext`: server-derived identity, exact scope and the active policy references;
2. one `AccessGrant` validated by `VentureIdentity`;
3. `serverAction`: capability, required authority and optional budget amount, derived from the server-side action catalog.

Client-provided `identity_id`, `scope`, tokens or secret fields are not part of `serverAction`. Extra fields fail closed. A UI button, mobile claim or request payload never becomes authorization evidence by itself.

## Evaluation order

The contract is intentionally exact and fail-closed:

1. validate the trusted context and server action;
2. normalize the identity and grant;
3. require active identity and exact identity/scope/capability match;
4. require the grant policy to be currently active;
5. compare required authority with the explicit grant;
6. apply budget limits;
7. force every L4 action through `owner_decision_required`.

There is no implicit cross-Venture inheritance. A grant on `venture:grindflow` does not authorize `venture:condor`, even for the same human.

## Authority levels

The rank is the canonical Business OS order:

`L0_AI_AUTONOMOUS < L1_OPERATOR < L2_VENTURE_ADMIN < L3_GROUP_INSTITUTION < L4_OWNER`

An authority shortfall never becomes `allow`. When the identity already has an explicit matching grant for the capability and scope, a higher required authority becomes `owner_decision_required`; the caller may materialize that exception through the existing Owner Decision flow. This contract does not create or approve that decision.

L4 is special: even a matching L4 grant does not auto-execute an L4 action. The result is always `owner_decision_required`.

## Policy and budget

Policy and budget are restrictions, never authority amplifiers.

- an inactive policy causes `deny`;
- a requested budget above the grant limit, or spending under a grant with no budget authority, causes `owner_decision_required`;
- a budget within the explicit limit does not increase the grant's authority.

## Determinism and evidence

Results contain only `decision` and ordered fixed `reasons`. Raw exceptions, tokens, payloads and secrets are never returned. The same normalized inputs produce the same result.

Validation:

```bash
php -l src/DecisionRights.php
php -l tests/decision_rights_scenarios.php
python3 -m unittest -v tests/test_decision_rights.py
```

## Next slices

- #133 consumes these rights for typed Identity Center lifecycle operations.
- #134 integrates lifecycle and rights with staff administration and the existing Owner Decision flow.
