# GitHub read-only public entrypoint

`GitHubPublicEntrypoint` exposes only `GET /github` behind the existing owner identity supplied by the web server. It reads a bounded local projection file and never calls GitHub itself.

## Local projection contract

The default path is `var/github-readonly.json`. The file is a versioned object:

```json
{"version":1,"projects":[{"version":1,"project_id":"...","observed_at":0,"repositories":[]}]}
```

Each item in `projects` is the canonical snapshot consumed by `GitHubProjectView`. That projector owns freshness/truncation semantics; `GitHubGlobalUi` only renders its output. Missing, malformed, oversized or unreadable local evidence is rendered as `UNKNOWN`, never as healthy/current.

## Trust boundary

- owner mismatch: `403` without projection data;
- invalid/missing owner configuration: `503`;
- non-GET: `405` with `Allow: GET`;
- paths other than `/github`: `404`;
- all responses are `no-store`, `nosniff`, frame-denied and no-referrer.

This component does not contain GitHub credentials, networking, workflow dispatch, PR merge, Issue mutation, deploy or live activation. `public/index.php` wiring is intentionally deferred to the dependent slice.
