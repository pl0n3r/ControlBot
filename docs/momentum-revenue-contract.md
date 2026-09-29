# MOMENTUM Revenue/CRM Core v1

Pure, provider-neutral revenue/CRM contract for #126. ControlBot stores only opaque references; customer PII remains in authoritative CRM/product systems.

## Domain
`MomentumRevenue` exposes:
- **pipeline**: Venture-scoped lead/qualification/opportunity state, owner alias, next action and freshness.
- **forecast**: bounded amount, currency, confidence, source and freshness; always forecast, never demonstrated revenue.
- **attribution**: `observed|inferred|unknown`; only observed yields demonstrated amount.
- **lifecycle signal**: post-sale `renewal|upsell|churn` toward Product Intelligence #184 and/or Customer Success #185.

Refs use `namespace:<32 lowercase hex>`. Names, emails, phones, messages, IPs, tokens and provider credentials are out of contract.

## Rules
Funnel: `lead → qualified → opportunity → proposal → won | lost`, plus terminal `disqualified`. Qualified+ stages require matching qualification; opportunity/proposal/won/lost require an opportunity ref. A `won` row requires a Customer Success handoff and non-won rows reject that handoff.

Forecast keeps confidence explicit and never upgrades to observed revenue. Attribution carrying an amount (`observed` or `inferred`) requires a `won` pipeline; campaign/creative refs must match the pipeline. `unknown` cannot claim amount and `observed` requires evidence.

Lifecycle signals are post-sale only: they require a `won` pipeline and at least one opaque handoff toward #184/#185. Churn remains a classified signal, not an automatic fact.

## Boundaries
No persistence/SQL, provider SDK, payment, email, paid-media execution, spend, scheduler, queue or WorkItem materialization. Campaign #228 and Creative #233 stay upstream; Customer Success owns post-sale operations; Product Intelligence owns outcomes/cohorts. Future executable work continues through Factory Queue #269.
