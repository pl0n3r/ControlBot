<?php
declare(strict_types=1);

use ControlBot\Requirements\RequirementIntake;
use ControlBot\Requirements\RequirementIntakeDecisionUi;

require_once __DIR__ . '/../../RequirementIntake.php';
require_once __DIR__ . '/../../RequirementIntakeDecisionUi.php';


/**
 * Owner-only HTML view. The authenticated web entrypoint must call this
 * ONLY after OAuth+TOTP verification; JSON operations retain their independent
 * owner/CSRF gates. Keeping the renderer inside this claimed module lets the
 * eventual front-controller integration be a small, separately authorized edit.
 *
 * @param array<string,mixed> $server
 * @return array{status:int,headers:array<string,string>,body:string}
 */
if (!function_exists('controlbot_intake_html_page')) {
    function controlbot_intake_html_page(string $csrf, array $server): array
    {
        $headers = [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
        ];
        if (($server['REQUEST_METHOD'] ?? null) !== 'GET') {
            $headers['Content-Type'] = 'text/plain; charset=utf-8';
            return ['status' => 405, 'headers' => $headers, 'body' => "Method not allowed.\n"];
        }
        if ($csrf === '' || strlen($csrf) > 4096 || str_contains($csrf, "\0")) {
            $headers['Content-Type'] = 'text/plain; charset=utf-8';
            return ['status' => 503, 'headers' => $headers, 'body' => "Service unavailable.\n"];
        }

        $nonce = base64_encode(random_bytes(18));
        $csrfJson = json_encode(
            $csrf,
            JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS,
        );
        $html = <<<'INTAKE_HTML'
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Ideas a proyectos · ControlBot</title>
<style>
body{font:1rem system-ui;max-width:54rem;margin:2rem auto;padding:0 1rem;line-height:1.5}
section{border:1px solid #aaa;border-radius:.5rem;padding:1rem;margin:1rem 0}
label,textarea,select{display:block;width:100%;box-sizing:border-box}
textarea{min-height:9rem}button,select,textarea{font:inherit;padding:.65rem;margin:.4rem 0}
button{cursor:pointer}button:focus-visible,textarea:focus-visible,select:focus-visible{outline:3px solid}
[hidden]{display:none!important}#notice{min-height:1.5rem}
</style></head>
<body><main>
<h1>De una idea a un proyecto</h1>
<p>Solo el propietario autenticado puede usar este formulario. Todas las decisiones son <strong>previsualizaciones</strong>: no se crean repositorios, proyectos ni issues.</p>
<section aria-labelledby="new-heading">
<h2 id="new-heading">Registrar una idea</h2>
<form id="idea-form"><label for="idea">Describe el problema, la persona usuaria y el objetivo</label>
<textarea id="idea" name="content" maxlength="8000" required></textarea>
<button type="submit">Registrar idea para decidir</button></form>
</section>
<section aria-labelledby="pending-heading"><h2 id="pending-heading">Ideas pendientes de decisión</h2>
<p id="index-state" role="status">Consultando ideas…</p><div id="ideas"></div></section>
<section id="decide" aria-labelledby="decide-heading" hidden>
<h2 id="decide-heading">Decidir proyecto (solo vista previa)</h2>
<p id="proposal"></p>
<label for="mode">Destino de la idea</label>
<select id="mode"><option value="new">Proponer proyecto nuevo</option><option value="existing">Vincular al proyecto identificado</option></select>
<button id="preview" type="button">Previsualizar decisión</button>
<pre id="result" aria-live="polite"></pre>
</section>
<p id="notice" role="alert" aria-live="assertive"></p>
</main><script nonce="__NONCE__">
"use strict";
const csrf = __CSRF_JSON__;
const notice = document.getElementById("notice");
const ideas = document.getElementById("ideas");
const indexState = document.getElementById("index-state");
const decisionSection = document.getElementById("decide");
const mode = document.getElementById("mode");
let selectedId = null;
let matchedRef = null;
async function request(path, payload) {
  const options = {credentials:"same-origin", cache:"no-store"};
  if (payload !== undefined) {
    options.method = "POST";
    options.headers = {"Content-Type":"application/json", "X-CSRF-Token":csrf};
    options.body = JSON.stringify(payload);
  }
  const response = await fetch(path, options);
  if (!response.ok) throw new Error("Operación no disponible o no autorizada");
  const data = await response.json();
  if (!data || typeof data !== "object" || data.execution !== false) {
    throw new Error("Respuesta de vista previa inválida");
  }
  return data;
}
async function refresh() {
  const data = await request("/api/v1/intake");
  if (!Array.isArray(data.entries) || data.entries.length > 50) {
    throw new Error("Listado de ideas inválido");
  }
  ideas.replaceChildren();
  for (const entry of data.entries) {
    if (!entry || !/^idea-[a-f0-9]{40}$/.test(entry.intake_id)) continue;
    const button = document.createElement("button");
    button.type = "button";
    button.textContent = "Decidir idea " + entry.intake_id;
    button.addEventListener("click", () => loadDecision(entry.intake_id).catch(report));
    ideas.append(button, document.createElement("br"));
  }
  indexState.textContent = data.entries.length ? "Selecciona una idea." : "No hay ideas pendientes.";
}
async function loadDecision(id) {
  if (!/^idea-[a-f0-9]{40}$/.test(id)) throw new Error("Identificador inválido");
  const data = await request("/api/v1/intake/" + encodeURIComponent(id) + "/decision");
  if (data.intake_id !== id) throw new Error("Identidad de idea inválida");
  const match = data.decision?.impacts?.project_match;
  matchedRef = match?.status === "matched" && typeof match.project_ref === "string"
    ? match.project_ref : null;
  mode.querySelector('option[value="existing"]').disabled = matchedRef === null;
  mode.value = "new";
  selectedId = id;
  document.getElementById("proposal").textContent = "Idea " + id
    + (matchedRef ? " · Proyecto existente comprobado" : " · Proyecto nuevo sugerido");
  document.getElementById("result").textContent = "";
  decisionSection.hidden = false;
  notice.textContent = "";
}
function report() {notice.textContent = "No se pudo completar la operación; no se creó ningún recurso.";}
document.getElementById("idea-form").addEventListener("submit", async (event) => {
  event.preventDefault();
  notice.textContent = "";
  try {
    const content = document.getElementById("idea").value;
    const data = await request("/api/v1/intake", {content});
    if (!/^idea-[a-f0-9]{40}$/.test(data.intake_id)) throw new Error("ID inválido");
    await refresh();
    await loadDecision(data.intake_id);
  } catch (_) {report();}
});
document.getElementById("preview").addEventListener("click", async () => {
  if (!selectedId) return;
  notice.textContent = "";
  try {
    const projectMode = mode.value;
    if (projectMode !== "new" && (projectMode !== "existing" || !matchedRef))
      throw new Error("Selección inválida");
    const data = await request("/api/v1/intake/" + encodeURIComponent(selectedId) + "/decision",
      {project_mode:projectMode, project_ref:projectMode === "existing" ? matchedRef : null});
    if (data.status !== "preview_only" || data.committed !== false
        || data.repositories_created !== 0 || data.issues_created !== 0)
      throw new Error("Ejecución no permitida");
    document.getElementById("result").textContent =
      "Vista previa sin cambios: " + (projectMode === "new"
        ? "se propondría un proyecto nuevo." : "se propondría vincular al proyecto identificado.");
  } catch (_) {report();}
});
refresh().catch(report);
</script></body></html>
INTAKE_HTML;
        $html = str_replace(['__NONCE__', '__CSRF_JSON__'], [
            htmlspecialchars($nonce, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $csrfJson,
        ], $html);
        $headers['Content-Security-Policy'] = "default-src 'none'; connect-src 'self'; script-src 'nonce-{$nonce}'; style-src 'unsafe-inline'; form-action 'none'; base-uri 'none'; frame-ancestors 'none'";
        return ['status' => 200, 'headers' => $headers, 'body' => $html];
    }
}

// All state is supplied by the trusted, server-bound adapter. A missing adapter
// fails closed (503); nothing here calls RequirementMaterializer or GitHub.
$exact = static function (mixed $value, array $keys): array {
    if (!is_array($value) || array_is_list($value)) {
        throw new InvalidArgumentException('Invalid intake object.');
    }
    $actual = array_keys($value);
    sort($actual);
    sort($keys);
    if ($actual !== $keys) {
        throw new InvalidArgumentException('Invalid intake fields.');
    }
    return $value;
};
$source = static function (
    callable $backend,
    string $operation,
    array $vars,
    array $request,
    int $now,
): array {
    $result = $backend($operation, $vars, $request, $now);
    if (!is_array($result) || array_is_list($result)) {
        throw new RuntimeException('Trusted intake source unavailable.');
    }
    return $result;
};
$identifier = static function (mixed $value): string {
    if (!is_string($value) || preg_match('/^idea-[a-f0-9]{40}$/D', $value) !== 1) {
        throw new InvalidArgumentException('Invalid intake identifier.');
    }
    return $value;
};
$read = static function (callable $backend, array $vars, int $now) use (
    $exact,
    $source,
    $identifier,
): array {
    $id = $identifier($vars['intake_id'] ?? null);
    $record = $exact($source($backend, 'intake.read', ['intake_id' => $id], [], $now), [
        'draft', 'candidates',
    ]);
    if (!is_array($record['draft'])
        || ($record['draft']['source_ref'] ?? null) !== 'controlbot:requirement-source/web-intake'
        || !is_array($record['candidates'])) {
        throw new RuntimeException('Untrusted intake record.');
    }
    try {
        $proposal = RequirementIntake::analyze($record['draft'], $record['candidates']);
        $decision = RequirementIntakeDecisionUi::project($proposal);
    } catch (InvalidArgumentException $error) {
        throw new RuntimeException('Invalid stored intake record.', 0, $error);
    }
    if ($identifier('idea-' . substr($record['draft']['fingerprint'], 0, 40)) !== $id) {
        throw new RuntimeException('Intake identifier mismatch.');
    }
    return [
        'version' => 1,
        'intake_id' => $id,
        'draft_ref' => $record['draft']['draft_ref'],
        'proposal_ref' => $proposal['proposal_ref'],
        'decision' => $decision,
        'execution' => false,
    ];
};

return [
    [
        'method' => 'GET',
        'path' => '/api/v1/intake',
        'handler' => static function (
            callable $backend, array $vars, array $request, int $now,
        ) use ($source, $exact, $identifier): array {
            $data = $exact($source($backend, 'intake.list', [], [], $now), ['entries']);
            if (!is_array($data['entries']) || !array_is_list($data['entries'])
                || count($data['entries']) > 50) {
                throw new RuntimeException('Invalid intake index.');
            }
            $seen = [];
            $entries = [];
            foreach ($data['entries'] as $entry) {
                if (!is_array($entry) || array_keys($entry) !== ['intake_id']) {
                    throw new RuntimeException('Invalid intake index entry.');
                }
                $id = $identifier($entry['intake_id']);
                if (isset($seen[$id])) {
                    throw new RuntimeException('Duplicate intake.');
                }
                $seen[$id] = true;
                $entries[] = [
                    'intake_id' => $id,
                    'decision_path' => '/api/v1/intake/' . $id . '/decision',
                    'state' => 'pending_owner_decision',
                ];
            }
            return [
                'version' => 1,
                'form' => ['method' => 'POST', 'path' => '/api/v1/intake',
                    'fields' => ['content'], 'max_bytes' => 8000],
                'entries' => $entries,
                'execution' => false,
            ];
        },
    ],
    [
        'method' => 'POST',
        'path' => '/api/v1/intake',
        'handler' => static function (
            callable $backend, array $vars, array $request, int $now,
        ) use ($source, $exact, $identifier): array {
            $data = $exact($request, ['content']);
            $draft = RequirementIntake::normalize([
                'version' => 1,
                'source_kind' => 'text',
                'source_ref' => 'controlbot:requirement-source/web-intake',
                'captured_at' => $now,
                'content' => $data['content'],
            ]);
            $candidates = $exact(
                $source($backend, 'intake.candidates', [], [], $now), ['projects'],
            )['projects'];
            $proposal = RequirementIntake::analyze($draft, $candidates);
            $decision = RequirementIntakeDecisionUi::project($proposal);
            $id = $identifier('idea-' . substr($draft['fingerprint'], 0, 40));
            $receipt = $exact($source($backend, 'intake.record', [], [
                'intake_id' => $id,
                'draft' => $draft,
                'candidates' => $candidates,
            ], $now), ['stored', 'intake_id']);
            if ($receipt['stored'] !== true || $receipt['intake_id'] !== $id) {
                throw new RuntimeException('Intake was not recorded.');
            }
            return [
                'version' => 1,
                'intake_id' => $id,
                'draft_ref' => $draft['draft_ref'],
                'proposal_ref' => $proposal['proposal_ref'],
                'decision' => $decision,
                'decision_path' => '/api/v1/intake/' . $id . '/decision',
                'status' => 'pending_owner_decision',
                'execution' => false,
            ];
        },
    ],
    [
        'method' => 'GET',
        'path' => '/api/v1/intake/{intake_id}/decision',
        'handler' => static function (
            callable $backend, array $vars, array $request, int $now,
        ) use ($read): array {
            return $read($backend, $vars, $now);
        },
    ],
    [
        'method' => 'POST',
        'path' => '/api/v1/intake/{intake_id}/decision',
        'handler' => static function (
            callable $backend, array $vars, array $request, int $now,
        ) use ($read, $exact): array {
            $selection = $exact($request, ['project_mode', 'project_ref']);
            if (!in_array($selection['project_mode'], ['new', 'existing'], true)) {
                throw new InvalidArgumentException('Invalid project choice.');
            }
            $entry = $read($backend, $vars, $now);
            $matched = $entry['decision']['impacts']['project_match'];
            if ($selection['project_mode'] === 'new') {
                if ($selection['project_ref'] !== null) {
                    throw new InvalidArgumentException('New project cannot link existing.');
                }
                $project = [
                    'operation' => 'propose_create',
                    'project_ref' => null,
                ];
            } else {
                if ($matched['status'] !== 'matched'
                    || !is_string($selection['project_ref'])
                    || !hash_equals($matched['project_ref'], $selection['project_ref'])) {
                    throw new InvalidArgumentException('Unverified existing project.');
                }
                $project = [
                    'operation' => 'link_proposal',
                    'project_ref' => $matched['project_ref'],
                ];
            }
            // Never invoke RequirementMaterializer: a decision here is a
            // preview, not authorization to create a repository or Issue.
            return [
                'version' => 1,
                'intake_id' => $entry['intake_id'],
                'project' => $project,
                'epic' => $entry['decision']['materialization_diff']['epic'],
                'issues' => $entry['decision']['materialization_diff']['issues'],
                'status' => 'preview_only',
                'execution' => false,
                'committed' => false,
                'repositories_created' => 0,
                'issues_created' => 0,
            ];
        },
    ],
];
