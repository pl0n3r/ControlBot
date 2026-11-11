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
 * final front-controller integration be a small, separately authorized edit.
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
let selectionEpoch = 0;
let previewEpoch = 0;
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
  const epoch = ++selectionEpoch;
  ++previewEpoch;
  selectedId = null;
  matchedRef = null;
  decisionSection.hidden = true;
  const data = await request("/api/v1/intake/" + encodeURIComponent(id) + "/decision");
  if (epoch !== selectionEpoch) return;
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
mode.addEventListener("change", () => {
  ++previewEpoch;
  document.getElementById("result").textContent = "";
});
document.getElementById("idea-form").addEventListener("submit", async (event) => {
  event.preventDefault();
  notice.textContent = "";
  try {
    const content = document.getElementById("idea").value;
    if (new TextEncoder().encode(content).length > 8000) {
      notice.textContent = "La idea supera el límite de 8000 bytes UTF-8.";
      return;
    }
    const data = await request("/api/v1/intake", {content});
    if (!/^idea-[a-f0-9]{40}$/.test(data.intake_id)) throw new Error("ID inválido");
    await refresh();
    await loadDecision(data.intake_id);
  } catch (_) {report();}
});
document.getElementById("preview").addEventListener("click", async () => {
  if (!selectedId) return;
  const id = selectedId;
  const epoch = ++previewEpoch;
  const projectMode = mode.value;
  const projectRef = projectMode === "existing" ? matchedRef : null;
  notice.textContent = "";
  try {
    if (projectMode !== "new" && (projectMode !== "existing" || !projectRef))
      throw new Error("Selección inválida");
    const data = await request("/api/v1/intake/" + encodeURIComponent(id) + "/decision",
      {project_mode:projectMode, project_ref:projectRef});
    // A previous request must never repaint the current idea or destination.
    if (epoch !== previewEpoch || selectedId !== id || mode.value !== projectMode
        || (projectMode === "existing" && matchedRef !== projectRef)) return;
    if (data.intake_id !== id || data.status !== "preview_only" || data.committed !== false
        || data.repositories_created !== 0 || data.issues_created !== 0)
      throw new Error("Ejecución no permitida");
    document.getElementById("result").textContent =
      "Vista previa sin cambios: " + (projectMode === "new"
        ? "se propondría un proyecto nuevo." : "se propondría vincular al proyecto identificado.");
  } catch (_) {if (epoch === previewEpoch && selectedId === id) report();}
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
