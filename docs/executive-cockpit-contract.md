# Executive Cockpit v1
## Purpose
Executive Cockpit is a pure, read-only group projection. It composes existing ControlBot contracts into one deterministic list of Ventures without creating a second source of truth.
## Venture projection
Each Venture keeps these dimensions separate:
- business health;
- technical health;
- finance;
- product/customer health;
- infrastructure;
- runtime capacity/work state;
- Owner Inbox counts.
There is no global score, hidden ranking or synthetic traffic light. Ventures in one aggregate must belong to the same Group and are ordered deterministically by `venture_id`.
## Freshness
Freshness is preserved per dimension. A stale or unknown dimension remains stale/unknown. It cannot be converted into healthy/current merely to simplify the cockpit.
## Canonical sources
- Venture and responsible identity: `VentureIdentity`.
- Finance: `VentureFinancialSnapshot`.
- Product health: raw canonical metrics are normalized again through `ProductHealthSnapshot`; callers cannot self-certify a snapshot-shaped array.
- Infrastructure: `InfrastructureResource` binds the resource to `venture_ref`, then `InfrastructureObservation` supplies health/freshness.
- Runtime: `RuntimeCapacitySignal`; because that contract is runtime-scoped rather than Venture-scoped, the Cockpit boundary requires an explicit `venture_id` binding and never accepts manual state/capacity fields.
- Exceptions/decisions: `OwnerInbox`.
## Safety
Cross-Venture data, cross-Group rows, duplicate Ventures, malformed fields and incompatible Owner Inbox scope fail closed. Infrastructure scope is proven by the canonical resource relation rather than by a free Venture string. Product health is recomputed through its canonical validator.
The projection exposes only allowlisted summary fields and opaque identity/source refs. It rejects obvious secret material or direct contact PII in projected text.
## Out of scope
Persistence, collectors, HTTP/UI, provider calls, scheduling, push, Factory dispatch, WorkItem creation, Owner Decision execution, hidden scoring and ranking.
