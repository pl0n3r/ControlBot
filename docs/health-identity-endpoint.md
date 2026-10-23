# Owner-gated release health identity (offline V1)

Decision and authority: ControlBot #816, option A, approves a reversible offline endpoint only. Implemented under #817, parent #625 and epic #1. This does not authorize deploy, go-live, credentials or real data.

## Request and response contract

GET /health.php requires HTTPS (server variable `HTTPS=on`) and existing server Basic Auth; a client `X-Forwarded-Proto` header is not trusted. The PHP endpoint requires REQUEST_METHOD=GET, AUTH_TYPE=Basic and a server-provided REMOTE_USER that exactly equals the configured CONTROLBOT_OWNER_LOGIN. A header supplied by a client is never treated as owner proof.

The endpoint additionally needs CONTROLBOT_RELEASE_MANIFEST_PATH, configured by an authorized deployment process and pointing to an existing regular non-symlink file outside DOCUMENT_ROOT. There is intentionally no repository default or .htaccess value. It must be a small private JSON object containing exactly the ordered keys:

    {"version":"0.1.24","release_sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","signature":"<hex-hmac-sha256>"}

Version and SHA are validated by HealthIdentityPayload. The version MUST also match the deployed app's `config/version.json`; the receipt's canonical byte sequence prevents duplicate JSON keys and whitespace ambiguity. Invalid/missing HMAC or HTTPS fails closed. The manifest MUST be produced and tied to the actually deployed artifact by a future authorized deploy pipeline after its receipt was verified. In addition to version and release_sha, the canonical compact JSON includes a 64-character lowercase signature over UTF-8 bytes `controlbot-release-v1\n<version>\n<release_sha>`. Each `\n` above denotes exactly one LF byte (0x0A), not a backslash followed by the letter n. The manifest must be PHP-canonical compact JSON with no extra spaces, no trailing newline, and PHP's default escaping of non-ASCII characters (without `JSON_UNESCAPED_UNICODE`). The signature is HMAC-SHA256 with a 32-byte key delivered by secure server-side configuration as base64 `CONTROLBOT_RELEASE_ATTESTATION_KEY`. No key, path or signer is configured in Git today. The signer and receipt publication are separate, owner-authorized work, not part of this leaf. A checked-in fixture, a workflow HEAD SHA, and an unsigned or fabricated manifest are NOT deployment evidence.

Only with both owner authentication and a valid private artifact manifest does the endpoint return HTTP 200 and JSON status, version and release_sha. It always returns Cache-Control: no-store, Pragma: no-cache, X-Content-Type-Options: nosniff, X-Frame-Options: DENY and Referrer-Policy: no-referrer. Missing authorization gives a generic 403. Missing/invalid artifact gives a generic 503. Neither response echoes the artifact or secrets.

## Server routing and denied paths

.htaccess retains Basic Auth and the internal src/config/docs/scripts/tests/vendor and non-public PHP deny rules. Only /health.php maps to public/health.php; all other PHP paths remain denied except the existing index entrypoint. Without mod_rewrite the new root route is absent and therefore fails closed. Without server-authenticated REMOTE_USER, PHP denies the endpoint even when a webserver module is unavailable.

No modifications to owner login, password files, AuthUserFile, deploy configuration, GitHub Actions variables, network transport or secrets are included. Do not enable DOMAIN/DEPLOY_ENABLED or schedule production observation on the basis of this PR.

## Offline checks and limits

Run: python3 -m unittest -v tests/test_health_http_identity_endpoint.py

## Exact-HEAD acceptance gates

Issue #817 AC-05 requires the existing GitHub Actions check `Pruebas de contrato e integración`, defined by the `contrato` job in `.github/workflows/ci.yml`. Verify its terminal success on the **same PR HEAD** as the separate, independently required `Validar` check; neither a renamed assertion nor a fixture substitutes for those actual check runs. AC-01 through AC-04 exercise the offline endpoint tests and deny-by-default configuration.

These checks are code-validation evidence only. The SRE role checklist for real smoke, deployed version/SHA, rollback and alerting remains unverified; do not mark those production operations as completed to satisfy role or policy CI. CodeRabbit must be requested only once on the final HEAD after the complete CI is green, and D-069 prohibits squash without real branch protection.

The tests exercise the real PHP class via CLI, invalid owner/auth cases, malformed and forged manifests, under-webroot and symlink denial, and the Apache route contract. PHP CLI cannot by itself prove live Apache/LiteSpeed Basic Auth or rewrite behavior.

A merge, green CI or an HTTP fixture does NOT prove deployed release_sha, authenticated observation, backup/rollback, collector/cron/UI freshness or Production GREEN. Parent issues #625 and #760 remain open until independent operational evidence meets their requirements. D-069 also requires active branch protection and exact-HEAD CI/security before any squash. Roll back by reverting the focused five-file PR.
