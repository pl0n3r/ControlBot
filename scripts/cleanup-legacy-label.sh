#!/usr/bin/env bash
set -euo pipefail

: "${REPOSITORIO:?REPOSITORIO es obligatorio}"
GH_BIN="${GH_BIN:-gh}"
LEGACY_LABEL="prioridad: normal"

encoded="$(python3 -c 'import sys, urllib.parse; print(urllib.parse.quote(sys.argv[1], safe=""))' "$LEGACY_LABEL")"
error_file="$(mktemp)"
trap 'rm -f "$error_file"' EXIT

if ! "$GH_BIN" api "repos/$REPOSITORIO/labels/$encoded" >/dev/null 2>"$error_file"; then
  if grep -q 'HTTP 404' "$error_file"; then
    echo "Etiqueta legacy ausente; no hay nada que limpiar."
    exit 0
  fi
  cat "$error_file" >&2
  exit 1
fi

uses="$(
  "$GH_BIN" api --paginate "repos/$REPOSITORIO/issues?state=all&labels=$encoded&per_page=100" --slurp |
    python3 -c '
import json
import sys

pages = json.load(sys.stdin)
if not isinstance(pages, list):
    raise SystemExit("respuesta paginada inválida")
count = 0
for page in pages:
    if not isinstance(page, list):
        raise SystemExit("página de Issues inválida")
    count += len(page)
print(count)
'
)"

if [[ ! "$uses" =~ ^[0-9]+$ ]]; then
  echo "::error::No fue posible verificar usos de la etiqueta legacy." >&2
  exit 1
fi

if (( uses > 0 )); then
  echo "::error::No se elimina '$LEGACY_LABEL': todavía tiene $uses uso(s)." >&2
  exit 2
fi

"$GH_BIN" api --method DELETE "repos/$REPOSITORIO/labels/$encoded" >/dev/null
echo "Etiqueta legacy '$LEGACY_LABEL' eliminada; la canónica permanece intacta."
