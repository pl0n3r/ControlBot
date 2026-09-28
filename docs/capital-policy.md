# CAPITAL policy v1

CAPITAL is a policy layer for financial proposals. It does not move money, hold bank credentials, or create a second authorization system.

## Inputs

A request is evaluated for one exact `scope` and `currency` and must contain:

- a budget envelope with integer minor-unit limits, spent and committed amounts;
- a reserve/runway snapshot with integer minor-unit cash and minimum reserve;
- one capital allocation proposal with amount, authority level and reversibility;
- the raw server-side inputs needed by `BudgetGuard`, which CAPITAL evaluates itself;
- the raw server-side context/grant/action needed by `DecisionRights`, which CAPITAL evaluates itself;
- optional shared-cost rows with explicit allocation rule and provenance.

All nested budget, reserve and proposal scopes/currencies must match the request exactly. Unknown financial state fails closed.

## Authority composition

CAPITAL never raises authority and never trusts a caller-supplied decision string. It invokes both authority engines server-side before composing the final result.

1. `DecisionRights=deny` stays `deny`.
2. A restrictive `BudgetGuard` result stays `deny`.
3. `owner_decision_required` from Decision Rights remains an owner gate.
4. Exceeding the budget envelope, breaching the reserve floor, requiring L4, or marking a proposal irreversible also produces `owner_decision_required`.
5. Only a reversible, in-budget proposal with sufficient reserve and both upstream gates permissive can return `allow`.

Even `allow` means **proposal allowed by policy**, not payment execution. Every result contains `execution: false`, and the payment boundary is unsupported.

## Shared costs

A shared cost is attributed only when all of these are present and valid:

- `target_scope`;
- `rule_ref`;
- `provenance_ref`.

If any is missing, the row remains `unallocated`; CAPITAL does not infer a target from names, ratios, history, or neighboring ventures.

## Security and privacy

The schema is closed and rejects extra fields. Financial credentials, bank tokens, account secrets, transaction payloads and customer-level data are outside this contract. References are bounded opaque ControlBot references and are never URLs containing credentials or email addresses.

## Determinism and recovery

Normalization is deterministic: the same valid input produces the same decision and ordered shared-cost output. Invalid, mismatched or unknown state returns a fail-closed decision without side effects. Reversal is a source-code revert because this slice has no persistence, migrations, endpoints or external writes.
