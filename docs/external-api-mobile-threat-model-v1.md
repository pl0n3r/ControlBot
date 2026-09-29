# External API Mobile Threat Model v1

## Assets

- HumanIdentity and authority context of the Owner.
- Active policy and grant references.
- Device, session and step-up references.
- Owner Decision and Factory handoff references.
- Request and correlation IDs plus audit evidence.
- Business snapshots reachable through authenticated API operations.

## Trust boundaries

1. iOS to ControlBot API: all client state is untrusted. UI visibility never grants access.
2. API to DecisionRuntime: identity, scope, capability, authority and policy are server-resolved.
3. API to session source: device, session and step-up state comes from ExternalApiSessionSource, not request payloads.
4. Request Gate to audit builder: audit recomputes the gate from verified contexts; callers cannot overwrite security context.
5. ControlBot to Factory: a work item reference is evidence of handoff only, never evidence of execution.

## Abuse cases and mitigations

| Threat | Control |
| --- | --- |
| Horizontal authorization across Ventures | Expected scope must match verified access plus verified session; mismatch fails closed in Request Gate. |
| Vertical authorization with insufficient authority | DecisionRights and Owner requirements are evaluated server-side; audit outcomes require an allowed request. |
| Stolen or revoked session | Session source plus Request Gate revalidate device, session and step-up and fail closed. |
| Replay of a mobile mutation | Public API requires idempotency for mutation contracts; request and correlation IDs remain auditable. |
| Client forges audit actor, authority or policy | ExternalApiMobileAudit accepts VerifiedAccessContext, not raw actor fields. |
| Client forges device or session | Audit accepts VerifiedExternalSessionContext, not raw session arrays. |
| Stale/offline state drives mutation | ExternalApiMobileState fails the freshness gate outside fresh state. |
| Push leakage | Push contract carries minimal opaque metadata; detail is fetched through authenticated API. |
| Sensitive request or response logging | Audit schema excludes payloads, IP, raw user-agent, fingerprints, tokens and secrets. |

## Test mapping

- AC-01: test_event_derives_security_context_only_from_verified_inputs
- AC-02: test_request_ids_operation_authorization_timestamp_and_outcome_are_traced
- AC-03, horizontal authorization and vertical authorization: test_horizontal_and_vertical_authorization_fail_closed
- AC-04: test_outcome_refs_are_required_without_executing_or_inventing_success
- AC-05: test_event_has_no_secrets_payload_ip_user_agent_fingerprint_or_extra_fields
- AC-06: test_threat_model_is_mapped_and_contract_has_no_storage_network_provider_or_execution

## Residual risk

This slice creates a normalized evidence record but does not persist it. Retention, append-only integrity, AEGIS or SIEM export and operational alerting remain separate adapters. factory_handoff records a reference only; it does not prove dispatch, execution or verification. A future adapter must preserve this distinction and must not log sensitive mobile or network metadata merely because it is available.
