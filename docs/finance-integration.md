# Finance Runtime integration v1

Finance Runtime is the server-side integration layer between Finance Telemetry, Financial Planning, CAPITAL, Owner Decisions and the canonical Factory queue.

It does not introduce a financial queue, a second RBAC system, a payment executor or a parallel approval mechanism.

## Read path

Finance reads are authorized before financial data is exposed.

The runtime defines the read action server-side as:

- capability: `finance.read`;
- authority: `L1_OPERATOR`;
- no budget amount.

`DecisionRights` evaluates the verified identity, grant and exact Venture scope. If the decision is not `allow`, snapshot and planning data are not returned.

After authorization:

1. `VentureFinancialSnapshot` normalizes the aggregate snapshot.
2. `FinancialPlanning` normalizes actual/budget/target/forecast.
3. Venture, period and currency must remain consistent across the read.
4. freshness is composed conservatively.

A read becomes `green` only when the snapshot/planning evidence is compatible and fresh. `stale`, `unknown` and `unavailable` remain visible as such.

## Financial action path

Financial action proposals are delegated to `CapitalPolicy`.

`CapitalPolicy` remains responsible for invoking the existing `BudgetGuard` and `DecisionRights` engines. Finance Runtime never replaces those decisions and always returns `execution: false`.

When CAPITAL returns `owner_decision_required`, Finance Runtime emits a valid existing `factory-human-gate` with category `money`:

- option A authorizes only a separately validated follow-up;
- option B preserves the current financial state;
- recommendation and safe default remain B.

The marker is validated with the existing `HumanGate` parser. No payment is executed by this layer.

## Factory handoff

Derived technical work is emitted for the existing Factory Queue v1 contract. The handoff uses:

- `queue: factory`;
- `origin_system: capital`;
- the canonical WorkItem fields from Factory #269;
- provenance in `evidence_refs`;
- snapshot `observed_at`;
- explicit handoff freshness;
- deterministic `idempotency_key`.

The WorkItem does not contain revenue amounts, cash values, customer counts, transaction counts, revenue streams, credentials or raw financial payloads.

The runtime provides a conservative `ready_hint` only. Factory remains the authority for canonical WorkItem validation, readiness, ranking, claims and dispatch.

If finance evidence is stale or unknown, the WorkItem may still be represented for traceability but `ready_hint` is false. Stale/unknown is never promoted to green.

## Authority boundaries

Authoritative components remain:

- `DecisionRights` for identity/scope/capability authority;
- `BudgetGuard` for budget capacity restrictions;
- `CapitalPolicy` for finance proposal policy;
- `HumanGate` / Owner Decisions for human escalation;
- Factory Queue for WorkItem validation/readiness/dispatch.

Finance Runtime only composes their results.

## Privacy and security

Schemas are closed. Handoff text rejects email-shaped values, credential-like fields and common secret markers.

Only aggregate source/provenance references cross into the Factory handoff. Customer-level data, transaction payloads, payment credentials, bank data and provider secrets are outside the contract.

## Boundaries

This slice has no final UI, payments, accounting ERP, bank sync, database, provider credentials, go-live logic or alternate scheduler.
