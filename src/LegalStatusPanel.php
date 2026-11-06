<?php
declare(strict_types=1);
namespace ControlBot\Legal;
use InvalidArgumentException;
use Throwable;
/**
 * Proyección de evidencia jurídica ya obtenida por un productor confiable.
 * No redacta, aprueba, consulta APIs ni muestra datos personales del responsable.
 */
final class LegalStatusPanel { private const REPOS = [ 'Condor' => 'Condor',
'GrindFlow' => 'GrindFlow', 'BRVTAL' => 'brvtal', 'ControlBot' => 'ControlBot', 'AutoFactory' => 'AutoFactory',
]; private const CONTROLLER = [ 'name' => 'Responsable', 'identifier' => 'NIT / identificación',
'address' => 'Dirección', 'rights_email' => 'Correo de derechos', ]; private const PLACEHOLDER = '[COMPLETAR POR EL DUEÑO]';
public static function unavailable(): array { return ['version' => 1, 'products' => []]; }
    /** Lee únicamente un snapshot JSON configurado por el servidor, nunca por la petición. */
public static function readConfigured(): array { $configured = getenv('CONTROLBOT_LEGAL_SNAPSHOT_PATH'); if (!is_string($configured) || $configured === ''
|| $configured[0] !== '/' || strlen($configured) > 512 || str_contains($configured, "\0") || str_contains($configured, '..')) { return self::unavailable(); }
$path = realpath($configured); $public = realpath(__DIR__ . '/../public'); if ($path === false || !is_file($path) || !is_readable($path) || ($public !== false && str_starts_with($path, $public . '/'))) {
return self::unavailable(); } $size = filesize($path); if (!is_int($size) || $size < 2 || $size > 262144) return self::unavailable();
try { $raw = file_get_contents($path); if (!is_string($raw)) return self::unavailable(); $parsed = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
self::project($parsed); return $parsed; } catch (Throwable) { return self::unavailable();
} }
    /** Solo admite los cinco productos; ausencia de evidencia es UNKNOWN, jamás aprobado. */
public static function project(mixed $snapshot): array { if (!is_array($snapshot) || ($snapshot['version'] ?? null) !== 1 || !isset($snapshot['products']) || !is_array($snapshot['products'])
|| !array_is_list($snapshot['products']) || count($snapshot['products']) > 5) { throw new InvalidArgumentException('Legal snapshot invalid.'); } $indexed = [];
foreach ($snapshot['products'] as $item) { if (!is_array($item) || array_is_list($item) || !is_string($item['product'] ?? null) || !isset(self::REPOS[$item['product']])
|| isset($indexed[$item['product']])) { throw new InvalidArgumentException('Legal product invalid or duplicate.'); } $indexed[$item['product']] = $item;
} $out = []; foreach (self::REPOS as $product => $repo) { $raw = $indexed[$product] ?? null;
if ($raw === null) { $out[] = ['product' => $product, 'source_state' => 'UNKNOWN', 'documents' => [], 'pending_fields' => [], 'controller_state' => 'UNKNOWN', 'gates' => []];
continue; } $documents = $raw['documents'] ?? null; $controller = $raw['controller'] ?? null;
$gates = $raw['gates'] ?? null; if (!is_array($documents) || !array_is_list($documents) || count($documents) > 60 || !is_array($gates) || !array_is_list($gates) || count($gates) > 40 || !is_array($controller)) {
throw new InvalidArgumentException('Legal product source invalid.'); } $seen = []; $docs = []; foreach ($documents as $document) {
if (!is_array($document) || !is_string($document['path'] ?? null) || !is_string($document['review_state'] ?? null) || !in_array($document['review_state'], ['draft', 'in_review', 'approved'], true)) { throw new InvalidArgumentException('Legal document invalid.');
} $path = $document['path']; if (strlen($path) > 180 || preg_match('#^(?:docs/privacidad|revision-juridica)/[a-zA-Z0-9_./-]+\.md$#D', $path) !== 1
|| str_contains($path, '..') || isset($seen[$path])) { throw new InvalidArgumentException('Legal document path invalid.'); } $seen[$path] = true;
$url = $document['source_ref'] ?? null; $prefix = 'https://github.com/pl0n3r/' . $repo . '/blob/'; if (!is_string($url) || !str_starts_with($url, $prefix) || !str_ends_with($url, '/' . $path)
|| strlen($url) > 350 || preg_match('/["<>\\x00-\\x20]/', $url)) { throw new InvalidArgumentException('Legal evidence reference invalid.'); } $approval = self::approval($document['approval'] ?? null, $repo);
$status = $document['review_state'] === 'approved' && $approval !== null ? 'APROBADO' : ($document['review_state'] === 'in_review' ? 'EN REVISIÓN' : 'BORRADOR'); $docs[] = ['path' => $path, 'source_ref' => $url,
'status' => $status, 'approval' => $status === 'APROBADO' ? $approval : null]; } $pending = []; $unknown = false; foreach (self::CONTROLLER as $key => $label) {
$value = $controller[$key] ?? null; if ($value === null) { $unknown = true; continue; } if (!is_string($value)) throw new InvalidArgumentException('Controller shape invalid.'); if (trim($value) === '' || trim($value) === self::PLACEHOLDER) $pending[] = $label;
                // Los valores reales se descartan deliberadamente.
} $open = []; $gateSeen = []; foreach ($gates as $gate) { if (!is_array($gate) || !is_bool($gate['open'] ?? null)
|| !is_string($gate['issue_ref'] ?? null)) { throw new InvalidArgumentException('Legal gate invalid.'); } $ref = $gate['issue_ref'];
$pattern = '#^https://github\.com/pl0n3r/' . preg_quote($repo, '#') . '/issues/([1-9][0-9]*)$#D'; if (preg_match($pattern, $ref, $match) !== 1 || isset($gateSeen[$ref])) { throw new InvalidArgumentException('Legal gate reference invalid.');
} $gateSeen[$ref] = true; if ($gate['open']) $open[] = ['issue_ref' => $ref, 'number' => $match[1]]; }
$out[] = ['product' => $product, 'source_state' => 'OBSERVADO', 'documents' => $docs, 'pending_fields' => $pending, 'controller_state' => $unknown ? 'PARCIAL / UNKNOWN' : 'VERIFICADO', 'gates' => $open];
} return ['version' => 1, 'mode' => 'read_only', 'products' => $out]; }
private static function approval(mixed $input, string $repo): ?array { if (!is_array($input)) return null; $by = $input['by'] ?? null;
$at = $input['at'] ?? null; $evidence = $input['evidence_ref'] ?? null; if (!is_string($by) || trim($by) === '' || strlen($by) > 80 || !is_string($at)
|| preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D', $at) !== 1 || !is_string($evidence) || preg_match('#^https://github\.com/pl0n3r/' . preg_quote($repo, '#') . '/issues/[1-9][0-9]*\#issuecomment-[1-9][0-9]*$#D', $evidence) !== 1) {
return null; } return ['by' => $by, 'at' => $at, 'evidence_ref' => $evidence]; }
private static function e(string $text): string { return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
public static function render(array $source): string { $model = self::project($source); $html = '<style>.legal-panel{padding:22px;max-width:1180px;margin:auto;color:var(--text)}'
. '.legal-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:16px}' . '.legal-card{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:18px}' . '.legal-panel h1{font-size:clamp(1.4rem,3vw,2rem)}.legal-card h2{margin-top:0}' . '.legal-panel a{color:var(--cyan);overflow-wrap:anywhere}'
. '.legal-note{border-left:4px solid var(--amber);padding:12px;background:var(--accent-surface)}' . '.legal-panel li{margin-block:8px}.legal-state{font-weight:750}' . '.legal-panel :focus-visible{outline:3px solid var(--amber);outline-offset:3px}' . '</style><main class="legal-panel"><h1>Legal y privacidad</h1>'
. '<p class="legal-note">Vista privada de solo lectura. Los documentos sin aprobación ' . 'verificable son BORRADORES pendientes de aprobación; no son textos legales vigentes.</p>' . '<div class="legal-grid">'; foreach ($model['products'] as $item) {
$html .= '<section class="legal-card" aria-label="' . self::e($item['product']) . '">' . '<h2>' . self::e($item['product']) . '</h2>'; if ($item['source_state'] === 'UNKNOWN') { $html .= '<p class="legal-state">UNKNOWN · fuente no disponible</p>'
. '<p>Sin inventario legal autenticado. No se presume aprobación ni ausencia de obligaciones.</p>'; } else { $html .= '<h3>Documentos</h3>'; if ($item['documents'] === []) $html .= '<p>Sin documentos verificados en el snapshot.</p>';
else { $html .= '<ul>'; foreach ($item['documents'] as $d) { $html .= '<li><a rel="noopener noreferrer" href="' . self::e($d['source_ref']) . '">'
. self::e($d['path']) . '</a> · <strong class="legal-state">' . self::e($d['status']) . '</strong>'; if ($d['status'] !== 'APROBADO') { $html .= ' · BORRADOR pendiente de aprobación';
} else { $a = $d['approval']; $html .= ' · por ' . self::e($a['by']) . ' · ' . self::e($a['at']) . ' · <a rel="noopener noreferrer" href="' . self::e($a['evidence_ref'])
. '">Acta de aprobación</a>'; } $html .= '</li>'; }
$html .= '</ul>'; } $html .= '<h3>Datos del responsable</h3>'; if ($item['pending_fields'] === []) {
$html .= '<p>' . ($item['controller_state'] === 'VERIFICADO' ? 'Sin campos de responsable pendientes en la fuente.' : 'UNKNOWN · faltan datos verificables del responsable.') . '</p>'; } else {
$html .= '<p>Pendientes (sin mostrar valores personales): ' . self::e(implode(', ', $item['pending_fields'])) . '.</p>'; if ($item['controller_state'] !== 'VERIFICADO') { $html .= '<p>Otros campos: UNKNOWN.</p>';
} } $html .= '<h3>Puertas jurídicas abiertas</h3>'; if ($item['gates'] === []) $html .= '<p>Sin puertas abiertas reportadas por la fuente.</p>';
else { $html .= '<ul>'; foreach ($item['gates'] as $gate) { $html .= '<li><a rel="noopener noreferrer" href="' . self::e($gate['issue_ref'])
. '">Issue #' . self::e($gate['number']) . '</a></li>'; } $html .= '</ul>'; }
} $html .= '</section>'; } return $html . '</div></main>';
} }
