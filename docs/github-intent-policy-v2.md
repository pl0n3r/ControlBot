# GitHub Intent Policy V2: offline trusted observations, no authority

This change adds an opt-in pure function `GitHubIntentPolicy::evaluateTrusted(envelope, grant, serverContext, canonicalSnapshot, trustedNow)`. The existing V1 `evaluate()` contract and its five regression tests are unchanged.

**Trust boundary:** `serverContext` must be sourced from backend configuration, `canonicalSnapshot` from the authenticated snapshot store, and `trustedNow` from a server clock. No caller-provided timestamp, TTL, evidence_ref or serverContext input may be used to authorize requests. The V2 caller must never pass untrusted request values into these privileged arguments. Structural checks alone do not authenticate arbitrary arrays.

The method validates `GitHubIntentEnvelope` first; strips its derived `execution=false` before invoking the actual 3-argument `GitHubIntentAuthorityRequirements::describe(rawEnvelope, expectedProjectRef, expectedRepositoryRef)`, and checks exact type, capability, scope, scope parameters, approval class and all non-authority flags. It independently derives a fixed-TTL observation via `GitHubIntentTrustedEvidence::derive(canonicalSnapshot, repositoryRef, trustedNow)` and cross-checks repository identity and HTTPS source/commit. A `github:`/ `controlbot:` Envelope reference must never be converted to an authenticated HTTPS reference.

Even for current observed evidence, the Github.* catalog is `known:false/forbidden` and no real grant/approval contract has been activated. Every path yields `deny` or `unknown`, `execution:false`. Foreign grants, apparent approval strings, stale/ambiguous snapshots and scope changes never confer authority; no adapters, API calls, tokens, grants, synthetic approvals, deployments or go-live occur.

Verification: `python3 -m unittest tests.test_github_intent_policy -v` (5 V1 + 2 new AC); `php -l src/GitHubIntentPolicy.php`, `php -l tests/github_intent_policy_scenarios.php`. Reversal: revert this four-file-only PR. PHP 8.5 / GitHub CI and final CodeRabbit are the integration gates.
