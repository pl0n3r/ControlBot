# GitHub intent authority requirements V1 (offline)

This pure PHP contract describes required authority, not issued authority.

GitHubIntentAuthorityRequirements::describe(rawEnvelope, expectedProjectRef, expectedRepositoryRef) validates the 11 known GitHubIntentEnvelope schemas. The expected scope references MUST come from trusted server configuration, not from request input. Each kind maps to an exact github.* capability and a repository scope, except project.freeze and project.unfreeze, which use the project scope.

approval_class=owner_required is a **requirement**, not approval. authorized, approval_issued, approval_verified, grant_issued and execution are always false. Unknown types, malformed or augmented envelopes, caller-controlled authority, mismatched repository/project and ambiguous dot path segments fail closed with state=unknown and no reflected payload.

The current CapabilityPolicy catalog deliberately has no github.* permissions. Its classification is forbidden / unknown_capability. This change cannot grant a capability, call a GitHub adapter, read secrets, contact the network or execute an intent. TrustedEvidence from #821 proves an observation, never a grant. #824 will integrate policy V2 separately, within its own paths and reservation.

Regression: python3 -m unittest tests.test_github_intent_authority_requirements -v; php -l src/GitHubIntentAuthorityRequirements.php; php -l tests/github_intent_authority_requirements_scenarios.php.

Reversal: revert the single offline contract PR. No production, release, customer data or go-live action.
