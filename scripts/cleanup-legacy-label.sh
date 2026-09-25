#!/usr/bin/env bash
set -euo pipefail

: "${REPOSITORIO:?REPOSITORIO es obligatorio}"
GH_BIN="${GH_BIN:-gh}"

ALIASES=$'prioridad: normal\tprioridad: media\ncalidad\ttipo: calidad\nseguridad\ttipo: seguridad\ndeuda técnica\ttipo: deuda técnica\naccesibilidad\ttipo: accesibilidad'

error_file="$(mktemp)"
trap 'rm -f "$error_file"' EXIT

encode_label() {
  python3 -c 'import sys, urllib.parse; print(urllib.parse.quote(sys.argv[1], safe=""))' "$1"
}

label_exists() {
  local label="$1"
  local encoded
  encoded="$(encode_label "$label")"
  : >"$error_file"
  if "$GH_BIN" api "repos/$REPOSITORIO/labels/$encoded" >/dev/null 2>"$error_file"; then
    return 0
  fi
  if grep -q 'HTTP 404' "$error_file"; then
    return 1
  fi
  cat "$error_file" >&2
  return 2
}

count_uses() {
  local encoded="$1"
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
}

while IFS=$'\t' read -r legacy canonical; do
  [[ -n "$legacy" && -n "$canonical" ]] || {
    echo "::error::Par legacy/canónico inválido." >&2
    exit 1
  }

  if label_exists "$legacy"; then
    :
  else
    status=$?
    if (( status == 1 )); then
      echo "Alias legacy '$legacy' ausente; no-op."
      continue
    fi
    exit "$status"
  fi

  if label_exists "$canonical"; then
    :
  else
    status=$?
    if (( status == 1 )); then
      echo "Destino canónico '$canonical' ausente; se conserva '$legacy' para que Factory pueda renombrarlo."
      continue
    fi
    exit "$status"
  fi

  encoded_legacy="$(encode_label "$legacy")"
  uses="$(count_uses "$encoded_legacy")"
  if [[ ! "$uses" =~ ^[0-9]+$ ]]; then
    echo "::error::No fue posible verificar usos de '$legacy'." >&2
    exit 1
  fi

  if (( uses > 0 )); then
    echo "::error::No se elimina '$legacy': todavía tiene $uses uso(s)." >&2
    exit 2
  fi

  "$GH_BIN" api --method DELETE "repos/$REPOSITORIO/labels/$encoded_legacy" >/dev/null
  echo "Alias legacy '$legacy' eliminado; '$canonical' permanece intacta."
done <<<"$ALIASES"
