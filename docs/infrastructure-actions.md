# Infrastructure actions — governed projection

Issue #205 separates action presentation from the read-only cockpit delivered by #190.

## Trust boundary

`InfrastructureActionProjection::project()` accepts an intent plus the opaque,
single-use authority projection emitted by
`VentureAccessRuntime::projectInfrastructureAuthority()` from a
`VerifiedAccessContext`. It never accepts caller-supplied authority fields,
status, authority decision or execution permission.

The opaque projection stays action-bound to capability and required authority
level. `InfrastructureIntent::plan()` consumes it and remains the only layer
that evaluates authority, capability policy, blast radius, evidence and budget.

- `planned` may expose Runner readiness but always returns `execution=false`;
- `owner_decision_required` preserves the canonical `approval_ref` and no Runner readiness;
- `denied` exposes neither WorkItem nor Runner readiness;
- fabricated arrays/objects and cross-capability replay fail closed.

## Provenance

Every action uses `controlbot:infrastructure-action/<intent_id>` and points back
to `controlbot:infrastructure-intent/<intent_id>`. The projection does not
unwrap or reconstruct authority and creates no provider session, queue, policy,
budget engine or credential store.
