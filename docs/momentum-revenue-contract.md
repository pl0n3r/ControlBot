# MOMENTUM Revenue/CRM Core v1

## Purpose

This contract keeps acquisition, CRM and revenue operations scoped by Venture without turning ControlBot into a transactional CRM or copying customer PII. It extends MOMENTUM after Campaign and Creative core, while Customer Success (#185) owns post-sale adoption/support and Product Intelligence (#184) owns product outcomes/cohorts.

## Domain boundary

`MomentumRevenue` is pure and provider-neutral. It exposes four projections:

- **pipeline**: lead/qualification/opportunity stage, owner alias, next-action reference and freshness;
- **forecast**: amount, currency, confidence, source and freshness, always classified as forecast rather than demonstrated revenue;
- **attribution**: observed/inferred/unknown revenue evidence, where only observed evidence yields a demonstrated amount;
- **lifecycle signal**: renewal/upsell/churn signal with explicit references toward Product Intelligence and/or Customer Success.

All entity references use `namespace:<32 lowercase hex>` identifiers. Human names, email addresses, phone numbers, messages, IPs, tokens and provider credentials are out of contract.

## Funnel

Closed stages:

`lead → qualified → opportunity → proposal → won | lost`

`disqualified` is an explicit terminal alternative. Qualified and later stages require `qualification=qualified`; `disqualified` requires `qualification=disqualified`. Opportunity/proposal/won/lost require an opaque opportunity reference.

A `won` pipeline row must carry a `customer-success:<32hex>` handoff reference. Other stages may not carry that handoff. This keeps acquisition/CRM ownership in MOMENTUM and post-sale ownership in Customer Success.

## Forecast

A forecast is scoped to the Venture and opportunity of a pipeline row and records:

- `amount_minor` as a bounded non-negative integer;
- ISO-like three-letter uppercase currency code;
- integer confidence from 0 to 100;
- `source_ref`, `observed_at` and explicit `freshness=current|stale|unknown`.

The normalized result adds `classification=forecast` and `demonstrated_revenue=false`. Confidence never upgrades a forecast into observed revenue.

## Attribution

Classification is closed to:

- `observed`: amount required and at least one evidence reference required;
- `inferred`: amount may be represented but is never demonstrated revenue;
- `unknown`: amount must be null.

Only `observed` yields `demonstrated_amount_minor`. This prevents unknown or inferred values from silently entering demonstrated revenue totals.

## Renewal / upsell / churn signals

Lifecycle signals are `renewal|upsell|churn` and separately classified `observed|inferred|unknown`. They must point to Product Intelligence and/or Customer Success through opaque references. A churn signal is therefore a signal with provenance, not a factual customer outcome by default.

## Privacy and governance

- No PII inline. CRM/customer identity remains behind opaque references in authoritative product/CRM systems.
- No persistence, SQL, provider SDK, payment, email, paid-media execution, spend, scheduler or parallel queue.
- No forecast ML or opaque scoring.
- Operational work continues through Factory Queue #269 in later slices.
- Database/migration concerns do not apply to this pure contract; no schema or runtime storage is changed.

## Downstream contracts

- Customer Success #185 receives explicit post-sale handoff references.
- Product Intelligence #184 may consume lifecycle signal references with source/freshness/confidence semantics.
- Campaign #228 and Creative #233 remain upstream attribution references and are not reimplemented here.
