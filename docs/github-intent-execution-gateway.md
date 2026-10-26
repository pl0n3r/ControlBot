# GitHub intent execution gateway — offline-only (ControlBot #907)

## Scope and trust boundaries

The **only** authority for this leaf is the owner choice **A** in [#904](https://github.com/pl0n3r/ControlBot/issues/904), with its authenticated factory-human-decision v2 journal. The choice permits a **synthetic, reversible integration slice only**; it never enables a live GitHub operation or authorizes credentials, grants, deploy, release, or go-live.

GitHubIntentExecutionGateway::simulate accepts fixtures supplied by an *offline test harness*, not HTTP, users, sessions, agents or webhook input. The reusable GitHubIntentOfflineGrantHarness verifies a deterministic owner approval and HMAC fixture using the existing issuer and grant verifier. Verification checks exact action, capability, project/repository scope, owner, trusted origin, nonce/replay, revoked list and bounded TTL. No caller-provided ref or timestamp becomes authorization. The runtime CapabilityPolicy continues to classify GitHub mutation as **forbidden**.

Only when the test fixture has been independently verified does the gateway call the **injected local fake sender** with an opaque SHA-256 digest and the literal mode 'offline_test_only'. No token, URL, request body, ref, approval, or HMAC key reaches the fake sender. A fake sender is part of the isolated test only; do not inject a real HTTP adapter. The class imports no network, database, filesystem, process or GitHub SDK transport and has no live execution method.

Regardless of the fake's output, **all receipts** keep decision=deny, execution=false, runtime_authorized=false, authorized=false, grant_issued=false, approval_issued=false, approval_verified=false. A valid stub returns only fixture_status=simulated, not an executed mutation. Exceptions, unexpected fields, wrong digest or ambiguous response return fixture_status=ambiguous and never fabricate success. The in-memory audit contains only outcome, reason, SHA-256 idempotency-key digest and fixture digest; no raw token/payload. Exact-key+digest retries reuse the prior result without another fake call, but each retry **re-verifies** the fixture and revocation first. Reusing a key for a different digest is denied.

## Verification

From the repository root, run the commands:

- python3 -m unittest -v tests.test_github_intent_execution_gateway
- php -l src/GitHubIntentExecutionGateway.php

AC-01 covers missing/untrusted approval without sender calls. AC-02 covers scope/ref, expiry and revocation. AC-03 tests malformed, uncertain and exception cases without leaks. AC-04 tests fake idempotency and sanitized audit, including runtime DENY on all outcomes. These tests execute real PHP code with a local synthetic fixture key, never a remote GitHub token.

## Reversal and future work

Revert the single PR squash to remove this offline-only surface; no migrations or external state exist. The parent epic #55 **remains open** until separately authorized and proven real end-to-end behavior and all six parent criteria are met. A new explicit owner decision, runtime grant and approval infrastructure, GitHub App permissions, revocation/audit, release/backup and production validation gates would be needed before proposing any real mutation. Never infer such authority from this PR.
