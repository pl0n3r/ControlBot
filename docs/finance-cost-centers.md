# Finance cost centers v1

Finance cost centers distinguish commercial ventures from shared institutional cost centers used for group reporting and explicit cost attribution.

## Entity kinds

Two kinds are supported and they are not interchangeable:

- `venture`
- `institution_cost_center`

A commercial venture must use an exact `venture:<id>` scope.

An institutional cost center must use an exact `institution:<id>` scope and must match the canonical registry.

## Canonical institutional cost centers

The current shared cost centers are:

- Factory → `institution:factory`
- ControlBot → `institution:controlbot`
- Runner → `institution:runner`
- AEGIS → `institution:aegis`
- MOMENTUM → `institution:momentum`
- CAPITAL → `institution:capital`

Those identifiers are reserved. A venture cannot reuse them to masquerade as a commercial venture.

## Evidence

Every normalized financial entity carries:

- `source_ref`
- `observed_at`
- `freshness`

The record is read-only and deterministic.

## Attribution

A financial attribution may target either a commercial venture or an institutional cost center, but the target must first pass the same kind/id/scope normalization.

Attribution also requires explicit:

- `provenance_ref`
- `source_ref`
- `observed_at`
- `freshness`

No target is inferred from names, amounts, neighboring ventures, prior periods or organizational proximity.

## Fail-closed rules

Normalization rejects:

- unknown entity kinds;
- unknown institutional cost-center ids;
- a canonical institutional id presented as a venture;
- `venture:<id>` on an institutional cost center;
- `institution:<id>` on a venture;
- mismatched canonical institution title/scope;
- malformed or credential-like evidence references.

## Boundaries

This model does not create a ledger, accounting system, payment capability, budget authority, database, provider sync, UI or new queue. It only provides a canonical financial classification that existing CAPITAL/Planning/Factory flows can reference.
