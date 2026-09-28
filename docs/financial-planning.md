# Financial Planning v1

Financial Planning compares financial facts, budgets, targets and forecasts without collapsing them into one truth. It is a read-only, deterministic comparison layer.

## Scope

Each comparison is bound to one exact:

- `venture_id`;
- `period` in `YYYY-MM`;
- `currency`.

The four supported series are always distinct:

- `actual`;
- `budget`;
- `target`;
- `forecast`.

Each series carries its own amount in integer minor units plus `source_ref`, `observed_at`, `as_of`, `freshness` and `confidence`.

## Variances

Variance is calculated server-side as:

`actual.amount_minor - comparison.amount_minor`

A variance is available only when:

1. actual exists;
2. actual matches the requested venture, period and currency;
3. the comparison series exists;
4. the comparison matches the same venture, period and currency;
5. neither side has `freshness: unknown`.

If any requirement is missing, the variance is `unavailable` and `delta_minor` remains null. No value is invented.

A stale series remains visibly stale and is not silently promoted to fresh or verified.

## Missing actuals

A forecast or target never replaces a missing actual.

When actual is absent:

- top-level planning status is `unavailable`;
- reason is `actual_missing`;
- budget, target and forecast remain visible in their original typed slots;
- every variance is unavailable.

This keeps projections clearly separated from observed facts.

## Attribution

Revenue and shared-cost attribution requires all of:

- `target_scope`;
- `rule_ref`;
- `provenance_ref`.

If any is missing, the row is returned as `unattributed` and target/rule/provenance are neutralized to null. Financial Planning never guesses allocation from labels, amounts, nearby ventures or prior periods.

## Evidence and privacy

Evidence fields are preserved exactly after validation so consumers can display freshness and provenance explicitly.

Schemas are closed. References are bounded opaque ControlBot references and reject credential-like or sensitive material. Customer-level data, transaction payloads, bank data and credentials are outside this contract.

## Determinism and boundaries

The same valid input produces the same normalized comparison and attribution ordering. This slice has no provider calls, ML, persistence, migrations, dashboards, HTTP, Factory queue, campaigns, accounting or payment execution.
