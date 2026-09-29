# Infrastructure actions — governed projection

Issue #205 separates action presentation from the read-only cockpit delivered by #190.

## Trust boundary

`InfrastructureActionProjection::project()` accepts the same intent, authority and
optional CAPITAL inputs consumed by `InfrastructureIntent::plan()`. It never accepts
caller-supplied `status`, `authority_decision` or execution permission.

The projection is built only after #189 evaluates capability policy, authority,
blast radius, evidence and budget. The result remains non-executable:

- `planned` may expose that a validated Runner request exists, but returns
  `execution=false`;
- `owner_decision_required` preserves the canonical `approval_ref` and does
  not expose a Runner request;
- `denied` exposes neither WorkItem nor Runner readiness;
- reasons and source refs come from the governed plan, not UI inference.

## Provenance

Every action uses:

`controlbot:infrastructure-action/<intent_id>`

and points back to:

`controlbot:infrastructure-intent/<intent_id>`

The projection creates no provider session, queue, policy, budget engine or
credential store. #191 composes this projection with the read-only cockpit and
the existing Factory/FactoryRunner execution plane.
