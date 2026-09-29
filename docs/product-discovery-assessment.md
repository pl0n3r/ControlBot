# Product Discovery Assessment v1

`ProductDiscoveryAssessment` is the explicit boundary between an experiment result and a Discovery classification. It composes `ProductDiscoveryExperiment` with `ProductExperimentOutcome` and never infers desirability from the sign of a delta.

## Contract

The assessment must match `experiment_ref`, `initiative_id`, `hypothesis_ref`, `primary_metric_ref`, `outcome_id`, and Venture/Product scope. Mismatches fail closed.

Classification is limited to `VALIDATED | INVALIDATED | INCONCLUSIVE`. The caller supplies an opaque `assessment_rule_ref` and one or more opaque, unique evidence refs.

An outcome that is inconclusive, stale, unknown, or inferred can only yield `INCONCLUSIVE`. A measured, fresh, observed result may be classified explicitly under the declared rule; `higher|lower|equal` remain numerical comparisons only.

The output preserves evaluation window, delta, comparison, confidence, nature, freshness, source and evidence refs. These are evidence, not causal claims.

Schema and refs are closed, deterministic and checked for sensitive material.

## Limits

This slice only classifies Discovery evidence. It does not execute work, persist state, call external systems, or grant permission to implement. Reverting the four additive files removes the contract without migrations.
