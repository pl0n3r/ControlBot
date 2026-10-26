#!/usr/bin/env python3
"""Offline-only, non-authoritative Hostinger launch evidence handoff (#625)."""
from __future__ import annotations

import json
import re
import sys
from typing import Any

GATES = (
    'owner_approval', 'hostinger_operator', 'legal_privacy', 'backup_restore',
    'rollback', 'artifact_provenance', 'owner_only_https', 'production_observation',
)
NEXT_STEPS = {
    'owner_approval': 'Acreditar autorización explícita y específica de producción, distinta de #914/A.',
    'hostinger_operator': 'Verificar identidad y alcance de operador Hostinger mediante canal autorizado.',
    'legal_privacy': 'Verificar condiciones legales, privacidad y tratamiento de datos antes de live.',
    'backup_restore': 'Aportar prueba restaurable real del alcance public_html y restore drill D-059.',
    'rollback': 'Verificar procedimiento y resultado de recuperación productiva sin acciones implícitas.',
    'artifact_provenance': 'Acreditar build e identidad del artefacto exact-SHA y cadena de publicación.',
    'owner_only_https': 'Validar HTTPS y acceso owner-only en el servicio real, sin credenciales en recibos.',
    'production_observation': 'Acreditar smoke y observación reales, identidad exacta y gates D-043.',
}
TARGET = 'control.condorapp.com.co'
SHA = re.compile(r'[a-f0-9]{40}\Z', re.ASCII)
OPAQUE_REF = re.compile(r'controlbot:[a-z0-9][a-z0-9._/-]{3,80}\Z', re.ASCII)
SENSITIVE = re.compile(
    r'(?:token|secret|password|passwd|bearer|cookie|authorization|api[_-]?key|private[_-]?key|session|https?://|@)', re.I
)


class InvalidInput(ValueError):
    pass


def _pairs(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    obj: dict[str, Any] = {}
    for key, value in pairs:
        if key in obj:
            raise InvalidInput('duplicate_json_key')
        obj[key] = value
    return obj


def _keys(value: Any, keys: set[str]) -> bool:
    return isinstance(value, dict) and set(value) == keys


def _fail(reason: str) -> dict[str, Any]:
    return {
        'decision': 'NO_GO', 'reason': reason, 'runtime_authorized': False,
        'deploy_authorized': False, 'go_live_authorized': False, 'execution': False,
        'missing_gates': list(GATES), 'gate_status': {g: 'UNKNOWN' for g in GATES},
        'next_steps': {g: NEXT_STEPS[g] for g in GATES},
    }


def evaluate(payload: Any) -> dict[str, Any]:
    if not _keys(payload, {'version', 'target', 'sha', 'phase', 'evidence'}):
        return _fail('invalid_input')
    if type(payload['version']) is not int or payload['version'] != 1:
        return _fail('invalid_input')
    if payload['target'] != TARGET or payload['phase'] != 'construccion':
        return _fail('invalid_identity')
    sha = payload['sha']
    if not isinstance(sha, str) or SHA.fullmatch(sha) is None:
        return _fail('invalid_identity')
    rows = payload['evidence']
    if not isinstance(rows, list) or len(rows) > len(GATES):
        return _fail('invalid_input')
    seen: set[str] = set()
    refs: set[str] = set()
    states: dict[str, str] = {g: 'MISSING' for g in GATES}
    for row in rows:
        if not _keys(row, {'gate', 'source_ref', 'scope', 'sha', 'verified', 'fresh'}):
            return _fail('invalid_evidence')
        gate, ref = row['gate'], row['source_ref']
        if not isinstance(gate, str) or gate not in GATES or gate in seen:
            return _fail('invalid_evidence')
        if not isinstance(ref, str) or OPAQUE_REF.fullmatch(ref) is None or SENSITIVE.search(ref):
            return _fail('invalid_evidence')
        if ref in refs:
            return _fail('invalid_evidence')
        if row['scope'] != TARGET or row['sha'] != sha:
            return _fail('invalid_evidence')
        if type(row['verified']) is not bool or type(row['fresh']) is not bool:
            return _fail('invalid_evidence')
        seen.add(gate)
        refs.add(ref)
        states[gate] = ('PRESENT_FOR_MANUAL_REVIEW' if row['verified'] and row['fresh']
                        else 'UNVERIFIED_OR_STALE')
    missing = [g for g in GATES if states[g] != 'PRESENT_FOR_MANUAL_REVIEW']
    return {
        'decision': 'NO_GO' if missing else 'REVIEW_ONLY',
        'reason': 'evidence_incomplete' if missing else 'offline_fixtures_require_operator_review',
        'runtime_authorized': False, 'deploy_authorized': False,
        'go_live_authorized': False, 'execution': False,
        'missing_gates': missing,
        'gate_status': states,
        'next_steps': {g: NEXT_STEPS[g] for g in GATES},
    }


def main() -> int:
    try:
        data = sys.stdin.buffer.read(32769)
        if not data or len(data) > 32768:
            output = _fail('invalid_input')
        else:
            payload = json.loads(
                data.decode('utf-8'), object_pairs_hook=_pairs,
                parse_constant=lambda _: (_ for _ in ()).throw(InvalidInput('nonfinite')),
            )
            output = evaluate(payload)
    except (UnicodeError, ValueError, TypeError, OverflowError):
        output = _fail('invalid_input')
    sys.stdout.write(json.dumps(output, sort_keys=True, separators=(',', ':')) + '\n')
    return 0 if output['decision'] == 'REVIEW_ONLY' else 1


if __name__ == '__main__':
    raise SystemExit(main())
