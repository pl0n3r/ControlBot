# Decision Rights v1

Issue: ControlBot #132 · Parent: #122 · Depends on #131.

## Boundary

This slice only evaluates authorization. It consumes #131 identity/grants and returns `allow`, `deny` or `owner_decision_required`. It does not persist, execute, create Owner Decisions, move money or expose HTTP endpoints.

## Trust boundary

`DecisionRights::evaluate()` receives:
1. `verifiedContext`: server-derived identity, exact scope and active policies;
2. one `AccessGrant` normalized by `VentureIdentity`;
3. `serverAction`: server-defined capability, required authority and optional budget.

Client `identity_id`, `scope`, tokens or secret fields are not accepted. Extra fields fail closed; UI state is never authorization evidence.

## Rules

- identity must be active and match the grant;
- scope and capability match exactly, so no implicit cross-Venture access exists;
- inactive policy denies;
- authority shortfall or budget above the explicit limit requires an Owner Decision, never `allow`;
- every L4 action requires an Owner Decision even with an L4 grant;
- output contains only a decision plus fixed ordered reasons.

Authority order:

`L0_AI_AUTONOMOUS < L1_OPERATOR < L2_VENTURE_ADMIN < L3_GROUP_INSTITUTION < L4_OWNER`

Policy/budget only restrict. They never grant capability, scope or authority.

## Verification

```bash
php -l src/DecisionRights.php
php -l tests/decision_rights_scenarios.php
python3 -m unittest -v tests/test_decision_rights.py
```

Next: #133 consumes this contract for typed Identity Center lifecycle operations; #134 integrates it with staff and Owner Decisions.
