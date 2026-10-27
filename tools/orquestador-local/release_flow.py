"""Flujo guiado de release de Factory dentro del orquestador local.

El dueño solo hace lo que es suyo: pulsar «Preparar release», responder la decisión (A/B) y confirmar «Mover v1».
Todo lo demás (pausar la fusión automática, esperar el CI de main, abrir la puerta, lanzar el release en el instante
de la decisión, relanzar para el self-test y quitar la pausa) lo hace este módulo.

Estados: idle, ci, gate, decision, release, mover_v1, selftest, done, error.
El estado se guarda en release_state.json para sobrevivir a reinicios.
"""
import fcntl
import json
import os
import re
import subprocess
import time
from pathlib import Path

HERE = Path(__file__).resolve().parent
STATE_FILE = HERE / "release_state.json"
PAUSE_FILE = HERE / "automerge_pausa.txt"
OWNER = "pl0n3r"
REPO = "Factory"
FULL = f"{OWNER}/{REPO}"
WORKFLOWS = {  # nombre mostrado -> archivo; la puerta exige el último run de cada uno en verde sobre el HEAD exacto
    "CI factory": "factory-ci.yml",
    "Sonar CI-based": "sonar.yml",
    "Evidencia CodeQL": "codeql-evidence.yml",
    "Vigilar startup_failure de reusables publicados": "reusable-release-watchdog.yml",
}
INTERVAL = {"ci": 120, "gate": 45, "decision": 45, "release": 45, "selftest": 45, "mover_v1": 60}
ACTIVE = ("ci", "gate", "decision", "release", "mover_v1", "selftest")
V1_JOB = "Verificar canal v1 antes del self-test"

LOCK_FILE = HERE / "release_flow.lock"
_lock_fd = None
LEADER = False  # solo el proceso que tiene el candado puede escribir el estado y avanzar el flujo
FLOW = {"state": "idle"}
_log = lambda msg: None  # noqa: E731


def set_logger(fn):
    global _log
    _log = fn


def gh(args, timeout=60):
    out = subprocess.run(["gh"] + args, capture_output=True, text=True, timeout=timeout)
    if out.returncode != 0:
        raise RuntimeError((out.stderr or out.stdout).strip()[:300])
    return out.stdout


def _acquire_leader():
    global _lock_fd, LEADER
    try:
        _lock_fd = os.open(LOCK_FILE, os.O_CREAT | os.O_RDWR)
        fcntl.flock(_lock_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        LEADER = True
    except OSError:
        LEADER = False


def _disk_rev():
    try:
        return json.loads(STATE_FILE.read_text(encoding="utf-8")).get("rev", 0)
    except (OSError, ValueError):
        return 0


def save():
    # Nunca pisa un estado más nuevo escrito por otro proceso (p. ej. el viejo durante un reinicio).
    if not LEADER:
        return
    if _disk_rev() > FLOW.get("rev", 0):
        load()
        return
    FLOW["rev"] = FLOW.get("rev", 0) + 1
    try:
        tmp = STATE_FILE.with_suffix(".tmp")
        tmp.write_text(json.dumps(FLOW, ensure_ascii=False), encoding="utf-8")
        tmp.replace(STATE_FILE)
    except OSError:
        pass


def load():
    try:
        data = json.loads(STATE_FILE.read_text(encoding="utf-8"))
        if isinstance(data, dict) and data.get("state"):
            FLOW.clear()
            FLOW.update(data)
    except (OSError, ValueError):
        pass
    if LEADER and FLOW.get("state") in ACTIVE:
        pause(True)


def pause(on):
    try:
        if on:
            PAUSE_FILE.write_text(REPO + "\n", encoding="utf-8")
        elif PAUSE_FILE.exists():
            PAUSE_FILE.unlink()
    except OSError:
        pass


def _set(state, **kw):
    if state in ("ci", "gate"):
        FLOW.pop("decision", None)  # una decisión vale solo para la puerta en la que se dio
    FLOW.update(kw)
    FLOW["state"] = state
    FLOW["since"] = time.time()
    FLOW["polled"] = 0
    save()
    _log(f"OK   release Factory {FLOW.get('version', '?')}: estado -> {state}")


def public_state():
    keys = ("state", "version", "sha", "gate", "since", "missing", "mensaje", "attempts", "target_sha", "decision")
    return {k: FLOW.get(k) for k in keys if k in FLOW}


# ----------------------------------------------------------------------------- consultas
def main_sha():
    q = 'query{repository(owner:"%s",name:"%s"){defaultBranchRef{target{oid}}}}' % (OWNER, REPO)
    return gh(["api", "graphql", "-f", f"query={q}", "--jq", ".data.repository.defaultBranchRef.target.oid"]).strip()


def evidence_missing(sha):
    """Nombres de las comprobaciones que aún no están en `completed success` para ese HEAD exacto."""
    missing = []
    for name, wf in WORKFLOWS.items():
        try:
            rows = json.loads(gh(["api", f"repos/{FULL}/actions/workflows/{wf}/runs?per_page=30"]) or "{}").get("workflow_runs", [])
        except (RuntimeError, ValueError):
            missing.append(name)
            continue
        mine = sorted((r for r in rows if r.get("head_sha") == sha), key=lambda r: r["id"], reverse=True)
        if not mine or mine[0].get("status") != "completed" or mine[0].get("conclusion") != "success":
            missing.append(name)
    return missing


def find_gate(version, sha):
    q = f"decisión en espera: Factory v{version} sobre HEAD exacto {sha[:8]}"
    out = gh(["issue", "list", "-R", FULL, "--state", "open", "--search", q, "--json", "number", "-q", ".[0].number"])
    out = out.strip()
    return int(out) if out.isdigit() else None


def gate_decision(gate):
    """('open'|'closed', 'A'|'B'|None) leyendo el journal de decisión humana del bot."""
    data = json.loads(gh(["issue", "view", str(gate), "-R", FULL, "--json", "state,comments"]))
    letter = None
    for c in data.get("comments", []):
        if (c.get("author") or {}).get("login") == "github-actions":
            m = re.search(r"Decisión humana registrada: opción \*\*([A-Z])\*\*", c.get("body") or "")
            if m:
                letter = m.group(1)
    return data.get("state", "").lower(), letter


def v1_sha():
    return gh(["api", f"repos/{FULL}/git/ref/tags/v1", "--jq", ".object.sha"]).strip()


def dispatch_release(gate):
    gh(["workflow", "run", "Release Factory v1.x", "-R", FULL, "--ref", "main", "-f", "expected_sha=latest", "-f", f"gate_issue={gate}"])


def latest_release_run(since_ts):
    rows = json.loads(gh(["run", "list", "-R", FULL, "-w", "Release Factory v1.x", "--limit", "3",
                          "--json", "databaseId,status,conclusion,createdAt"]) or "[]")
    for r in rows:
        created = time.mktime(time.strptime(r["createdAt"], "%Y-%m-%dT%H:%M:%SZ")) - time.timezone
        if created >= since_ts - 45:
            return r
    return None


def failed_jobs(run_id):
    data = json.loads(gh(["run", "view", str(run_id), "-R", FULL, "--json", "jobs"]))
    return [j["name"] for j in data.get("jobs", []) if j.get("conclusion") not in (None, "", "success", "skipped")]


# ----------------------------------------------------------------------------- acciones del dueño
def _valid_release_version(version):
    """Validación lineal y acotada de X.Y.Z, sin regex sobre entrada externa."""
    if not isinstance(version, str) or not 1 <= len(version) <= 32:
        return False
    parts = version.split(".")
    return (len(parts) == 3 and all(
        part and part.isascii() and part.isdecimal() for part in parts
    ))

def current_version():
    import base64
    raw = gh(["api", f"repos/{FULL}/contents/config/version.json", "-q", ".content"])
    return json.loads(base64.b64decode(raw).decode())["version"]


def start(version=None, adopt_gate=None, sha=None):
    version = version or current_version()
    published = gh(["release", "list", "-R", FULL, "--limit", "5", "--json", "tagName", "-q", ".[].tagName"]).split()
    if f"v{version}" in published:
        raise ValueError(f"la versión {version} ya está publicada; sube la versión en main antes de preparar otra release")
    if FLOW.get("state") in ACTIVE:
        raise ValueError("ya hay un release en curso")
    if not _valid_release_version(version):
        raise ValueError("versión inválida")
    rev = max(FLOW.get("rev", 0), _disk_rev())
    FLOW.clear()
    FLOW["rev"] = rev
    FLOW["version"] = version
    pause(True)
    if adopt_gate:
        _set("decision", gate=int(adopt_gate), sha=sha or main_sha())
    else:
        _set("ci", missing=[])
    return public_state()


def cancel():
    pause(False)
    rev = max(FLOW.get("rev", 0), _disk_rev())
    FLOW.clear()
    FLOW["rev"] = rev
    FLOW["state"] = "idle"
    save()
    _log("OK   release Factory: cancelado por el dueño")
    return public_state()


def decide(option):
    if FLOW.get("state") != "decision":
        raise ValueError("no hay una decisión de release pendiente")
    if option not in ("A", "B"):
        raise ValueError("opción inválida")
    gate = FLOW["gate"]
    state, _ = gate_decision(gate)
    if state != "open":
        raise ValueError("la puerta ya no está abierta")
    gh(["issue", "comment", str(gate), "-R", FULL, "--body", f"/decidir {option}"])
    FLOW["decision"] = option
    save()
    _log(f"OK   release Factory: decisión del dueño desde el panel -> /decidir {option} en #{gate}")
    return public_state()


def move_v1():
    if FLOW.get("state") != "mover_v1":
        raise ValueError("todavía no toca mover v1")
    target = FLOW.get("sha")
    if not target or main_sha() != target:
        raise ValueError("main cambió: hay que repetir la puerta antes de mover v1")
    gh(["api", "-X", "PATCH", f"repos/{FULL}/git/refs/tags/v1", "-f", f"sha={target}", "-F", "force=true"])
    _log(f"OK   release Factory: v1 movido a {target[:8]} desde el panel")
    _set("selftest", target_sha=target)
    dispatch_release(FLOW["gate"])
    FLOW["selftest_since"] = time.time()
    save()
    return public_state()


# ----------------------------------------------------------------------------- motor
def tick():
    if not LEADER:
        return
    st = FLOW.get("state")
    if st not in ACTIVE:
        return
    now = time.time()
    if now - FLOW.get("polled", 0) < INTERVAL.get(st, 30):
        return
    FLOW["polled"] = now
    try:
        _step(st, now)
    except Exception as exc:  # noqa: BLE001 — un fallo de red no rompe el flujo; se reintenta en el siguiente ciclo
        FLOW["mensaje"] = f"reintentando: {str(exc)[:120]}"
        if "rate limit" in str(exc).lower():
            FLOW["polled"] = now + 240  # espera 5 min completos antes de volver a preguntar
        _log(f"FALLO release Factory ({st}) -> {str(exc)[:160]}")
    save()


def _fail(msg):
    pause(False)
    _set("error", mensaje=msg)


def _step(st, now):
    version = FLOW["version"]
    if st == "ci":
        sha = main_sha()
        miss = evidence_missing(sha)
        FLOW["missing"], FLOW["sha"] = miss, sha
        if not miss:
            gh(["workflow", "run", "Factory Release Window", "-R", FULL, "--ref", "main", "-f", f"version={version}"])
            _set("gate", sha=sha, attempts=0, missing=[])
    elif st == "gate":
        sha = FLOW["sha"]
        gate = find_gate(version, sha)
        if gate:
            _set("decision", gate=gate)
        elif now - FLOW.get("since", now) > 150:
            FLOW["attempts"] = FLOW.get("attempts", 0) + 1
            if FLOW["attempts"] > 4:
                return _fail("no se pudo abrir la puerta de release tras 4 intentos")
            if main_sha() != sha:
                return _set("ci", missing=[])
            gh(["workflow", "run", "Factory Release Window", "-R", FULL, "--ref", "main", "-f", f"version={version}"])
            FLOW["since"] = now
    elif st == "decision":
        gate, sha = FLOW["gate"], FLOW["sha"]
        state, letter = gate_decision(gate)
        if state == "closed":
            if letter == "A":
                dispatch_release(gate)
                return _set("release", started=now)
            if letter:
                pause(False)
                rev = max(FLOW.get("rev", 0), _disk_rev())
                FLOW.clear()
                FLOW["rev"] = rev
                FLOW["state"] = "idle"
                _log("OK   release Factory: el dueño decidió NO publicar")
                return
        if now - FLOW.get("sha_checked", 0) > 180:
            FLOW["sha_checked"] = now
            if main_sha() != sha:
                _log("OK   release Factory: main se movió; se reabre la puerta")
                _set("ci", missing=[])
    elif st in ("release", "selftest"):
        since = FLOW.get("started") if st == "release" else FLOW.get("selftest_since", FLOW.get("since", now))
        run = latest_release_run(since or now)
        if not run or run.get("status") != "completed":
            return
        if run.get("conclusion") == "success":
            pause(False)
            return _set("done", mensaje="Release publicado y canal v1 verificado")
        bad = failed_jobs(run["databaseId"])
        if st == "release" and bad == [V1_JOB]:
            return _set("mover_v1")
        _fail(f"el release falló en: {', '.join(bad) or 'desconocido'} (run {run['databaseId']})")
    elif st == "mover_v1":
        if v1_sha() == FLOW.get("sha"):  # el dueño ya lo movió a mano
            dispatch_release(FLOW["gate"])
            FLOW["selftest_since"] = now
            _set("selftest", target_sha=FLOW["sha"])
