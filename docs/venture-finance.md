# Venture Financial Snapshot v1

ControlBot consumes aggregated venture finance facts, not accounting ledgers or customer-level transactions.

## Money

All money values are integer minor units in the declared ISO-4217 currency. Floats are rejected.

Derived values are server-side:

- net_revenue = revenue - refunds
- gross_profit = net_revenue - direct_costs
- operating_result = gross_profit - operating_costs - infrastructure_costs - ai_costs

Clients cannot submit those derived fields.

## Revenue streams

Streams contain only an opaque stream_id, aggregate money, customer count and transaction count. Customer, order and payment references are rejected. When streams are supplied, revenue/refunds totals reconcile with the snapshot.

## Provenance and freshness

source_ref and observed_at are mandatory. freshness is fresh, stale or unknown. confidence is verified, estimated or unknown. A stale or unknown snapshot cannot become verified.

## Non-goals

This contract does not store transactions, invoices, bank data, customer lists, payment credentials, budgets, forecasts or accounting journals. It does not authorize payments or capital allocation and has no production side effects.
