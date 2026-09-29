# Product Discovery Assessment v1

## Purpose

`ProductDiscoveryAssessment` is the explicit boundary between an experiment result and a Discovery classification.

It composes the existing `ProductDiscoveryExperiment` plan with `ProductExperimentOutcome`. It does not infer product desirability from the sign of a delta.

## Binding

An assessment must match the normalized plan and outcome exactly by:

- `experiment_ref`;
- `initiative_id`;
- `hypothesis_ref`;
- `primary_metric_ref`;
- `outcome_id`;
- Venture/Product scope.

Mismatches fail closed.

## Classification

The only classifications are:

- `VALIDATED`;
- `INVALIDATED`;
- `INCONCLUSIVE`.

The caller must provide an opaque `assessment_rule_ref` and at least one opaque `assessment_evidence_ref`.

If the normalized outcome is inconclusive, stale, unknown, or inferred, the only valid classification is `INCONCLUSIVE`.

A measured, fresh and observed result may be explicitly classified under the declared rule. `higher`, `lower` and `equal` remain numerical comparisons only.

## Preserved evidence

The output preserves the evaluation window, delta, comparison, confidence, nature, freshness, and source/evidence refs. These fields are evidence, not causal claims.

## Safety

The schema is closed. Assessment refs and evidence refs are opaque, deterministic, duplicate-free and checked for sensitive material.

## Limits

This contract classifies Discovery evidence only. It does not make portfolio decisions, execute work, persist state, call external systems, or grant permission to proceed with implementation.

## Reversal

The slice is additive and limited to four files. Reverting those files removes the contract without migrations or persistent state.
