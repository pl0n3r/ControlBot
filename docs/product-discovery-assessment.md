# Product Discovery Assessment v1

`ProductDiscoveryAssessment` is the explicit boundary between an experiment result and a Discovery classification. It composes `ProductDiscoveryExperiment` with `ProductExperimentOutcome` and never infers desirability from the sign of a delta.

## Contract

The assessment must match `experiment_ref`, `initiative_id`, `hypothesis_ref`, `primary_metric_ref`, `outcome_id`, the exact predeclared `evaluation_window`, and Venture/Product scope. Mismatches fail closed.

Classification is limited to `VALIDATED | INVALIDATED | INCONCLUSIVE`. The caller supplies an opaque `assessment_rule_ref` and one or more opaque, unique evidence refs.

Only a measured, fresh, observed outcome with confidence > 0 and a fresh/non-unknown Hypothesis may yield `VALIDATED` or `INVALIDATED`. Otherwise classification is restricted to `INCONCLUSIVE`; `higher|lower|equal` remain numerical comparisons only.

The output preserves evaluation window, delta, comparison, confidence, nature, freshness, source and evidence refs. These are evidence, not causal claims.

Schema and refs are closed and deterministic. Typed opaque refs such as `assessment:<32hex>` are accepted as identifiers even when their hex payload is all digits; free-form strings still pass sensitive/PII checks.

## Limits

This slice only classifies Discovery evidence. It does not execute work, persist state, call external systems, or grant permission to implement. Reverting the four additive files removes the contract without migrations.
