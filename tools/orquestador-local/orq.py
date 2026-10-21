#!/usr/bin/env python3
"""Orquestador local de la fábrica (solo lectura).

Usa el CLI `gh` ya autenticado del dueño; no guarda ni muestra tokens.
Uso:  python3 orq.py [puerto]      (por defecto 8777)  ->  http://127.0.0.1:8777
"""
import hashlib
import json
import re
import secrets
import grafo_orq
import subprocess
import sys
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

OWNER = "pl0n3r"
REPOS = ["Factory", "Condor", "GrindFlow", "brvtal", "ControlBot", "AutoFactory", "FactoryRunner"]
CACHE_SECONDS = 300
MIN_RATE_LEFT = 1200  # si queda menos, se sirve la caché en vez de consultar
HERE = Path(__file__).resolve().parent

CMD = {}  # órdenes pendientes por (instance_id, chat_id|"*"): {"want","id","at","acked"}
CMD_SEQ = [int(time.time())]  # ids únicos entre reinicios: la extensión recuerda los ya vistos
AF = {}  # estado local de agentes enviado por AutoFactory (opt-in, sin contenido de chats)
RECOVER_LIMIT = 8  # frentes sin recuperar tolerados antes de alertar (dueño, 2026-10-15)
AF_STATES = {"activo", "inactivo", "limite", "sesion_cerrada", "desconocido"}
AF_MAX_BYTES = 16384
AF_CHAT_STATES = {"generando", "esperando_envio", "detenido", "error", "limite", "sesion_cerrada", "desconocido"}
AF_IDLE = {"activo", "esperando_pacing", "limite_horario", "circuit_breaker", "envio_ambiguo_5min",
           "sesion_cerrada", "pausa_manual", "sin_trabajo", "desconocido"}


def af_clean(p):
    """Valida el latido: solo campos acotados, nunca texto de chats."""
    if not isinstance(p, dict):
        raise ValueError("payload inválido")
    iid = str(p.get("instance_id", ""))[:64]
    if not re.fullmatch(r"[A-Za-z0-9_.:-]{3,64}", iid):
        raise ValueError("instance_id inválido")
    state = p.get("state", "desconocido")
    if state not in AF_STATES:
        raise ValueError("state inválido")
    task = p.get("current_task")
    if task is not None and not re.fullmatch(r"[A-Za-z0-9_.-]{1,40}#\d{1,6}", str(task)):
        raise ValueError("current_task inválida (formato Repo#N)")
    label = re.sub(r"[^\w .:-]", "", str(p.get("label", iid)))[:40]
    chats = p.get("chats_open")
    chats = int(chats) if isinstance(chats, int) and 0 <= chats <= 99 else None
    chats_in = p.get("chats")
    chats_out = []
    if chats_in is not None:
        if not isinstance(chats_in, list) or len(chats_in) > 12:
            raise ValueError("chats inválido (máx 12)")
        for c in chats_in:
            if not isinstance(c, dict):
                raise ValueError("chat inválido")
            cid = str(c.get("chat_id", ""))[:40]
            if not re.fullmatch(r"[A-Za-z0-9_.:-]{2,40}", cid):
                raise ValueError("chat_id inválido")
            cs = c.get("state", "desconocido")
            if cs not in AF_CHAT_STATES:
                raise ValueError("estado de chat inválido")
            ct = c.get("current_task")
            if ct is not None and not re.fullmatch(r"[A-Za-z0-9_.-]{1,40}#\d{1,6}", str(ct)):
                raise ValueError("current_task de chat inválida")
            def num(k, hi):
                v = c.get(k)
                return v if isinstance(v, int) and not isinstance(v, bool) and 0 <= v <= hi else None
            title = c.get("title")
            title = re.sub(r"[^\w .:#/()+-]", "", str(title))[:60] if title else None
            phase = c.get("phase") if c.get("phase") in ("trabajando", "esperando_externo", "termino", "bloqueado", "desconocido") else "desconocido"
            refs = [n for n in (c.get("refs") or []) if isinstance(n, int) and not isinstance(n, bool) and 0 < n < 10**6][:6] if isinstance(c.get("refs"), list) else []
            chats_out.append({"chat_id": cid, "title": title, "state": cs, "current_task": ct, "refs": refs,
                              "phase": phase, "directed": c.get("directed") is True,
                              "last_prompt_s": num("last_prompt_s", 10**7), "last_reply_s": num("last_reply_s", 10**7),
                              "last_reply_chars": num("last_reply_chars", 10**6), "msgs_hour": num("msgs_hour", 10**4)})
    idle = p.get("idle_reason", "desconocido")
    if idle not in AF_IDLE:
        raise ValueError("idle_reason inválido")
    run_id = str(p.get("run_id", ""))[:64]
    run_id = run_id if re.fullmatch(r"[A-Za-z0-9_.:-]{3,64}", run_id) else None
    since = p.get("idle_since")
    since = str(since)[:25] if since else None
    lim = p.get("limit_resets_at")
    lim = str(lim)[:25] if lim else None
    return {"instance_id": iid, "label": label, "state": state, "current_task": task,
            "chats_open": chats, "limit_resets_at": lim, "idle_reason": idle, "idle_since": since, "chats": chats_out, "run_id": run_id, "seen": time.time()}


TOKEN = secrets.token_hex(16)
PORT = 8777
HOST = "0.0.0.0"  # LAN: los agentes de otro PC publican aquí; todo lo demás sigue siendo solo del Mac


def load_agent_key():
    """Clave compartida para agentes remotos; se crea una vez y se reutiliza."""
    f = HERE / "agent_key.txt"
    try:
        k = f.read_text(encoding="utf-8").strip()
        if len(k) >= 32:
            return k
    except OSError:
        pass
    k = secrets.token_hex(24)
    f.write_text(k + "\n", encoding="utf-8")
    f.chmod(0o600)
    return k


AGENT_KEY = load_agent_key()
SESSION = hashlib.sha256((AGENT_KEY + "|sesion-lan").encode()).hexdigest()
LOGIN_HTML = ("""<!doctype html><meta charset=utf-8><meta name=viewport content="width=device-width">
<title>Orquestador local</title><body style="font:16px system-ui;background:#0d1117;color:#e6edf3;display:grid;place-items:center;height:100vh">
<form method=post action=/login style="display:grid;gap:10px;width:min(340px,90vw)"><b>Orquestador local (solo lectura)</b>
<input name=key type=password placeholder="Clave de agente" autofocus style="padding:8px;font:inherit">
<button style="padding:8px;font:inherit">Entrar</button>__MSG__</form>""")
LABELS = {"brvtal": ("status: blocked", "status: available")}
DEFAULT_LABELS = ("estado: bloqueado", "estado: disponible")
LOGFILE = HERE / "acciones.log"


def log_action(msg):
    try:
        with LOGFILE.open("a", encoding="utf-8") as f:
            f.write(time.strftime("%Y-%m-%d %I:%M:%S %p (hora Colombia)", time.gmtime(time.time() - 5 * 3600)) + "  " + msg + "\n")
    except OSError:
        pass


def run_action(body):
    """Acciones tipadas y acotadas; nunca ejecuta comandos libres."""
    action, repo, num = body.get("action"), body.get("repo"), body.get("number")
    if repo not in REPOS or type(num) is not int or not 0 < num < 100000:
        raise ValueError("repo o número inválido")
    full = f"{OWNER}/{repo}"
    if action == "unblock":
        blocked, avail = LABELS.get(repo, DEFAULT_LABELS)
        gh(["issue", "edit", str(num), "-R", full, "--remove-label", blocked, "--add-label", avail])
        gh(["issue", "comment", str(num), "-R", full, "--body",
            "Liberado por el dueño desde el orquestador local: pasa a disponible. Si la causa del bloqueo persiste, Coordinación lo volverá a marcar con su motivo."])
        return "issue liberado (bloqueado → disponible)"
    if action == "merge":
        gh(["pr", "merge", str(num), "-R", full, "--squash"], timeout=90)
        return "PR fusionado (squash)"
    if action == "update":
        gh(["pr", "update-branch", str(num), "-R", full], timeout=90)
        return "rama actualizada con la base"
    if action == "decide":
        opt = body.get("option")
        if type(opt) is not str or not re.fullmatch(r"[A-Z]", opt):
            raise ValueError("opción inválida")
        i = gh_json(["issue", "view", str(num), "-R", full, "--json", "state,body,labels"])
        if i.get("state") != "OPEN":
            raise ValueError("la decisión ya no está abierta")
        if not any(re.search(r"decisi.n: due|decision: owner", l["name"]) for l in i.get("labels", [])):
            raise ValueError("el issue no es una decisión del dueño")
        gate = parse_gate(i.get("body") or "")
        if not gate or opt not in {o["id"] for o in gate["options"]}:
            raise ValueError("la opción no existe en esta decisión")
        gh(["issue", "comment", str(num), "-R", full, "--body", f"/decidir {opt}"])
        log_action(f"OK   decisión del dueño desde el panel: {repo}#{num} -> /decidir {opt}")
        return f"decisión registrada: /decidir {opt} en {repo}#{num}"
    if action == "release_forced":
        item = next((i for i in release_preview() if i["repo"] == repo and i["n"] == num), None)
        if item is None:
            raise ValueError("el issue ya no está en «requiere recuperación»")
        if item["at_risk"]:
            raise ValueError("tiene PR abierto o commits propios: se liberará automáticamente con Factory #1175 sin perder trabajo")
        gh(["issue", "comment", str(num), "-R", full, "--body", "/liberar-forzado"])
        return "liberación forzada solicitada a Coordinación (/liberar-forzado)"
    raise ValueError("acción no permitida")


import urllib.request
import urllib.error


def load_config():
    try:
        return json.loads((HERE / "config.json").read_text(encoding="utf-8"))
    except Exception:
        return {"metas": [], "salud": []}


def probe(item):
    t0 = time.time()
    code, err = None, None
    try:
        req = urllib.request.Request(item["url"], headers={"User-Agent": "orquestador-local"})
        with urllib.request.urlopen(req, timeout=8) as r:
            code = r.status
    except urllib.error.HTTPError as e:
        code = e.code
    except Exception as e:  # red caída, DNS, TLS
        err = str(e)[:80]
    ok_codes = item.get("ok", [200, 301, 302])
    return {"nombre": item["nombre"], "url": item["url"], "code": code, "ms": round((time.time() - t0) * 1000),
            "ok": code in ok_codes, "error": err}


EPIC_RE = re.compile(r'"epic"\s*:\s*(\d+)')


def collect_goals(cfg):
    """Metas calculadas con el trabajo real: cada épica (campo epic de factory-plan-task)
    agrupa sus issues; avance = cerrados / total; pendiente = los abiertos. Sin listas fijas.
    Si config.json trae "metas_auto": false se usa la lista manual "metas" (modo anterior)."""
    if cfg.get("metas_auto", True) is False:
        return collect_goals_manual(cfg)
    out = []
    for repo in REPOS:
        try:
            lst = gh_json(["issue", "list", "-R", f"{OWNER}/{repo}", "--state", "all", "--limit", "1000",
                           "--json", "number,state,title,body"])
        except Exception:
            continue
        by_num = {i["number"]: i for i in lst}
        epics = {}
        for i in lst:
            m = EPIC_RE.search(i.get("body") or "")
            if not m or "factory-plan-task" not in (i.get("body") or ""):
                continue
            e = int(m.group(1))
            if e == i["number"]:
                continue
            epics.setdefault(e, []).append(i)
        for e, items in epics.items():
            pend = [{"n": i["number"], "title": i["title"][:70]} for i in items if i["state"] != "CLOSED"]
            if not pend:
                continue  # épica terminada: ya no es trabajo pendiente
            head = by_num.get(e)
            name = re.sub(r"^(epic|épica)\s*[:\-]\s*", "", (head["title"] if head else f"Épica #{e}"), flags=re.I)[:80]
            out.append({"nombre": name, "repo": repo, "total": len(items), "done": len(items) - len(pend),
                        "unknown": 0, "pend": sorted(pend, key=lambda p: p["n"])})
    out.sort(key=lambda g: (-(g["total"] - g["done"]), g["repo"], g["nombre"]))
    return out


def collect_goals_manual(cfg):
    by_repo = {}
    for m in cfg.get("metas", []):
        by_repo.setdefault(m["repo"], set()).update(m["issues"])
    states = {}
    for repo in by_repo:
        try:
            lst = gh_json(["issue", "list", "-R", f"{OWNER}/{repo}", "--state", "all", "--limit", "400",
                           "--json", "number,state,title"])
            states[repo] = {i["number"]: i for i in lst}
        except Exception:
            states[repo] = {}
    out = []
    for m in cfg.get("metas", []):
        st = states.get(m["repo"], {})
        done, pend, unknown = 0, [], 0
        for n in m["issues"]:
            i = st.get(n)
            if i is None:
                unknown += 1
            elif i["state"] == "CLOSED":
                done += 1
            else:
                pend.append({"n": n, "title": i["title"][:70]})
        total = len(m["issues"])
        out.append({"nombre": m["nombre"], "repo": m["repo"], "total": total, "done": done, "unknown": unknown, "pend": pend})
    return out


def collect_release():
    import base64
    try:
        raw = gh(["api", f"repos/{OWNER}/Factory/contents/config/version.json", "-q", ".content"])
        ver = json.loads(base64.b64decode(raw).decode())["version"]
        rel = gh_json(["release", "list", "-R", f"{OWNER}/Factory", "--limit", "1", "--json", "tagName"])
        last = rel[0]["tagName"] if rel else None
        cmp_ = gh_json(["api", f"repos/{OWNER}/Factory/compare/v1...main"])
        return {"main_version": ver, "last_release": last, "ahead": cmp_.get("ahead_by")}
    except Exception as exc:
        return {"error": str(exc)[:100]}


def release_preview():
    """Para cada frente «requiere recuperación»: ¿hay PR abierto o commits propios que se perderían?"""
    data = _cache.get("data")
    if not data:
        raise RuntimeError("sin datos todavía")
    out = []
    for r in data["repos"]:
        prefix = "work/issue-" if r["repo"] == "brvtal" else "trabajo/issue-"
        for w in r["work"]:
            if w["state"] != "requiere recuperación":
                continue
            branch = prefix + str(w["n"])
            pr = w.get("pr")
            ahead, exists = None, None
            try:
                cmp_ = gh_json(["api", f"repos/{OWNER}/{r['repo']}/compare/main...{branch}"])
                ahead, exists = cmp_.get("ahead_by"), True
            except Exception:
                exists = False
            at_risk = bool(pr) or bool(ahead)
            out.append({"repo": r["repo"], "n": w["n"], "title": w["title"][:70], "branch": branch,
                        "branch_exists": exists, "ahead": ahead, "pr": pr["n"] if pr else None, "at_risk": at_risk})
    return out


_lock = threading.Lock()
_cache = {"at": 0.0, "data": None, "error": None}


def gh(args, timeout=60):
    out = subprocess.run(["gh"] + args, capture_output=True, text=True, timeout=timeout)
    if out.returncode != 0:
        raise RuntimeError((out.stderr or out.stdout).strip()[:300])
    return out.stdout


def gh_json(args):
    return json.loads(gh(args) or "null")


def state_of(labels):
    for name in labels:
        m = re.match(r"^(estado|status):\s*(.+)$", name)
        if m:
            return m.group(2).strip().lower()
    return "sin estado"


NORMAL = {
    "blocked": "bloqueado", "available": "disponible", "reserved": "reservado",
    "in review": "en revisión", "completed": "completado", "planned": "planificado",
    "recovery required": "requiere recuperación",
}


def parse_gate(body):
    """Lee la puerta humana (<!-- factory-human-gate {...} -->) de un issue y la deja lista para mostrar al dueño."""
    m = re.search(r"<!-- factory-human-gate (\{.*?\}) -->", body or "", re.S)
    if not m:
        return None
    try:
        g = json.loads(m.group(1))
    except Exception:
        return None
    if not isinstance(g, dict) or not isinstance(g.get("options"), list):
        return None
    cut = lambda v, n=700: v[:n] if isinstance(v, str) else ""
    opts = [{"id": cut(o.get("id"), 4), "label": cut(o.get("label"), 200), "effect": cut(o.get("effect"), 600)}
            for o in g["options"] if isinstance(o, dict) and re.fullmatch(r"[A-Z]", str(o.get("id", "")))]
    if not opts:
        return None
    return {"title": cut(g.get("title_simple"), 220), "summary": cut(g.get("summary_simple")), "explain": cut(g.get("explain_simple")),
            "why": cut(g.get("why_recommended")), "blocks": cut(g.get("blocks")), "recommendation": cut(g.get("recommendation"), 4),
            "safe_default": cut(g.get("safe_default"), 4), "options": opts}


def classify_block(title):
    t = title.lower()
    if "[auto]" in t:
        return "alerta automática"
    if "live" in t or "readiness" in t or "go-live" in t:
        return "readiness (preparar, no activar el live)"
    if re.match(r"(roadmap|planificaci|planning|futuro|epic|producto:)", t):
        return "roadmap / planificado"
    return "tarea con dependencia"


DEP_LINE = re.compile(r"[Dd]epende de|[Ss]erializar detr|[Ee]sperar |[Cc]ondición de desbloqueo|[Dd]esbloqueo:|[Bb]loqueado (hasta|por)|tras (cerrar|integrar|estabilizar)")


def compute_unlockers(issues):
    """Issues abiertos que desbloquean más trabajo (depends_on y condiciones de desbloqueo, transitivo)."""
    by = {i["number"]: i for i in issues}
    dep = {}
    for i in issues:
        b = i.get("body") or ""
        ds = set()
        m = re.search(r'"depends_on":\s*\[([^\]]*)\]', b)
        if m:
            ds |= {int(x) for x in re.findall(r"\d+", m.group(1))}
        for line in b.splitlines():
            if DEP_LINE.search(line):
                ds |= {int(x) for x in re.findall(r"#(\d+)", line)}
        for d in ds:
            if d in by and d != i["number"]:
                dep.setdefault(d, set()).add(i["number"])

    def desc(n, seen):
        for c in dep.get(n, ()):
            if c not in seen:
                seen.add(c)
                desc(c, seen)
        return seen

    out = []
    for n, i in by.items():
        k = len(desc(n, set()) - {n})
        if k < 2 or classify_block(i["title"]) in ("alerta automática", "roadmap / planificado"):
            continue
        labels = [l["name"] for l in i["labels"]]
        st = NORMAL.get(state_of(labels), state_of(labels))
        crit = any(re.search(r"cr[ií]tic", x) and re.match(r"(prioridad|priority)", x) for x in labels)
        out.append({"n": n, "title": i["title"][:80], "k": k, "state": st, "crit": crit})
    return sorted(out, key=lambda x: -x["k"])[:8]


def latest_checks(roll):
    """Un check repetido (falló y se relanzó en verde) cuenta una sola vez, con su ejecución más reciente."""
    best = {}
    for c in roll:
        name = c.get("name") or c.get("context") or ""
        stamp = c.get("startedAt") or c.get("completedAt") or ""
        if name not in best or stamp >= (best[name].get("startedAt") or best[name].get("completedAt") or ""):
            best[name] = c
    return list(best.values())


def collect_repo(repo):
    full = f"{OWNER}/{repo}"
    issues = gh_json(["issue", "list", "-R", full, "--state", "open", "--limit", "300",
                      "--json", "number,title,labels,updatedAt,body"])
    prs = gh_json(["pr", "list", "-R", full, "--state", "open", "--limit", "60",
                   "--json", "number,title,isDraft,mergeStateStatus,autoMergeRequest,updatedAt,statusCheckRollup,headRefName"])
    merged = gh_json(["pr", "list", "-R", full, "--state", "merged", "--limit", "100",
                      "--json", "number,title,mergedAt"])
    now = time.time()

    def age_h(iso):
        try:
            t = time.mktime(time.strptime(iso, "%Y-%m-%dT%H:%M:%SZ")) - time.timezone
            return round((now - t) / 3600, 1)
        except Exception:
            return None

    counts, blockers, gates, blocked_list = {}, {}, [], []
    work = []
    available = []
    claims, states, depmap = {}, {}, {}
    for i in issues:
        labels = [l["name"] for l in i["labels"]]
        st = NORMAL.get(state_of(labels), state_of(labels))
        # Métrica honesta: un «bloqueado» que es contenedor de roadmap o puerta del dueño no es trabajo atascado.
        dst = st
        if st == "bloqueado":
            _k = classify_block(i["title"])
            if any(re.search(r"decisi.n: due|decision: owner", n) for n in labels) or _k.startswith("readiness"):
                dst = "del dueño (live/decisión)"
            elif _k == "roadmap / planificado":
                dst = "planificado"
        counts[dst] = counts.get(dst, 0) + 1
        states[i["number"]] = st
        mt = re.search(r"<!-- factory-plan-task (\{.*?\}) -->", i.get("body") or "", re.S)
        if mt:
            try:
                _task = json.loads(mt.group(1))
                pts = [p for p in _task.get("paths", []) if isinstance(p, str)]
                if pts:
                    claims[i["number"]] = pts[:40]
                _dp = [d for d in _task.get("depends_on", []) if type(d) is int]
                if _dp:
                    depmap[i["number"]] = _dp[:12]
            except Exception:
                pass
        if any(re.search(r"decisi.n: due|decision: owner", n) for n in labels):
            gates.append({"n": i["number"], "title": i["title"][:90], "gate": parse_gate(i.get("body") or "")})
        if st == "bloqueado":
            k = classify_block(i["title"])
            blockers[k] = blockers.get(k, 0) + 1
            blocked_list.append({"n": i["number"], "title": i["title"][:95], "cause": k, "h": age_h(i["updatedAt"])})
        if st == "disponible":
            available.append({"n": i["number"], "title": i["title"][:90], "h": age_h(i["updatedAt"])})
        if st in ("reservado", "en revisión", "requiere recuperación"):
            work.append({"n": i["number"], "state": st, "title": i["title"][:90], "h": age_h(i["updatedAt"])})

    unlockers = compute_unlockers(issues)

    pr_rows = []
    for p in prs:
        roll = latest_checks(p.get("statusCheckRollup") or [])
        fails = sum(1 for c in roll if c.get("conclusion") == "FAILURE")
        pend = sum(1 for c in roll if c.get("status") not in (None, "COMPLETED"))
        pr_rows.append({
            "n": p["number"], "title": p["title"][:80], "draft": p["isDraft"],
            "merge": p["mergeStateStatus"], "auto": p.get("autoMergeRequest") is not None,
            "fails": fails, "pend": pend, "h": age_h(p["updatedAt"]),
            "issue": int(re.search(r"issue-(\d+)", p.get("headRefName") or "").group(1)) if re.search(r"issue-(\d+)", p.get("headRefName") or "") else None,
        })
    by_issue = {p["issue"]: p for p in pr_rows if p["issue"]}
    for w in work:
        pr = by_issue.get(w["n"])
        w["pr"] = ({"n": pr["n"], "fails": pr["fails"], "pend": pr["pend"], "draft": pr["draft"], "merge": pr["merge"]} if pr else None)

    def merged_since(hours):
        n = 0
        for m in merged:
            h = age_h(m["mergedAt"])
            if h is not None and h <= hours:
                n += 1
        return n

    def kind_of(t):
        t = t.lower()
        if re.match(r"(feat|feature)", t): return "funcionalidad"
        if re.match(r"fix", t) and re.search(r"incidente|seguridad|security|cr[ií]tic", t): return "corrección crítica"
        if re.match(r"fix", t): return "corrección"
        if re.match(r"(docs|test|chore|ci|refactor|build)", t): return "mantenimiento"
        return "otros"

    merged_list = []
    for m in merged:
        h = age_h(m["mergedAt"])
        if h is not None and h <= 168:
            merged_list.append({"n": m["number"], "title": m["title"][:110], "at": m["mergedAt"], "kind": kind_of(m["title"])})
    try:
        rel = gh_json(["release", "list", "-R", full, "--limit", "8", "--json", "tagName,publishedAt,name"])
        releases = [{"tag": x["tagName"], "at": x["publishedAt"], "name": (x.get("name") or "")[:60]} for x in rel
                    if age_h(x["publishedAt"]) is not None and age_h(x["publishedAt"]) <= 336]
    except Exception:
        releases = []
    days = {}
    for m in merged:
        t_utc = time.mktime(time.strptime(m["mergedAt"], "%Y-%m-%dT%H:%M:%SZ")) - time.timezone
        day = time.strftime("%Y-%m-%d", time.gmtime(t_utc - 5 * 3600))
        days[day] = days.get(day, 0) + 1
    last_merge_h = min([age_h(m["mergedAt"]) for m in merged if age_h(m["mergedAt"]) is not None], default=None)
    return {
        "repo": repo, "claims": claims, "deps": depmap, "states": states, "available": available[:40], "open_numbers": [i["number"] for i in issues], "counts": counts, "total": len(issues), "blockers": blockers,
        "gates": gates, "merged_list": merged_list, "releases": releases, "blocked": blocked_list[:150], "work": work, "prs": pr_rows, "days": days,
        "unlockers": unlockers, "merged24": merged_since(24), "merged96": merged_since(96), "merged168": merged_since(168),
        "last_merge_h": last_merge_h,
    }


def collect():
    rate = gh_json(["api", "rate_limit"])["resources"]["core"]
    if rate["remaining"] < MIN_RATE_LEFT and _cache["data"]:
        raise RuntimeError(f"Cupo de API bajo ({rate['remaining']}); se muestra la última lectura")
    repos, errors = [], {}
    from concurrent.futures import ThreadPoolExecutor

    def one(r):
        try:
            return r, collect_repo(r), None
        except Exception as exc:  # un repo caído no tumba el panel
            return r, None, str(exc)[:160]

    with ThreadPoolExecutor(max_workers=7) as pool:
        results = list(pool.map(one, REPOS))
    for r, data, err in results:
        if data:
            repos.append(data)
        else:
            errors[r] = err
    ready = []
    for r in repos:
        for p in r["prs"]:
            if not p["draft"] and p["fails"] == 0 and p["merge"] == "CLEAN":
                ready.append({"repo": r["repo"], **p})
    cfg = load_config()
    from concurrent.futures import ThreadPoolExecutor as _TPE
    with _TPE(max_workers=4) as pool:
        f_goals = pool.submit(collect_goals, cfg)
        f_rel = pool.submit(collect_release)
        f_health = [pool.submit(probe, h) for h in cfg.get("salud", [])]
        goals, release, health = f_goals.result(), f_rel.result(), [f.result() for f in f_health]
    avail = sum(r["counts"].get("disponible", 0) for r in repos)
    active = sum(r["counts"].get(k, 0) for r in repos for k in ("reservado", "en revisión"))
    recover = sum(r["counts"].get("requiere recuperación", 0) for r in repos)
    return {
        "generated_at": time.strftime("%Y-%m-%d %I:%M:%S %p (hora Colombia)", time.gmtime(time.time() - 5 * 3600)),
        "rate": {"remaining": rate["remaining"], "limit": rate["limit"], "reset": rate["reset"]},
        "repos": repos, "errors": errors, "ready": ready, "goals": goals, "release": release, "health": health,
        "totals": {"available": avail, "active": active, "recover": recover,
                   "merged24": sum(r["merged24"] for r in repos),
                   "merged96": sum(r["merged96"] for r in repos)},
        "alerts": build_alerts(repos, avail, recover),
    }


def build_alerts(repos, avail, recover):
    out = []
    if avail == 0:
        out.append("COLA VACÍA: no hay ningún issue disponible en ningún repo.")
    if recover > RECOVER_LIMIT:
        out.append(f"{recover} issues en «requiere recuperación»: agentes inactivos o sin cupo.")
    for r in repos:
        h = r["last_merge_h"]
        if h is not None and h > 12 and any(not p["draft"] and p["fails"] == 0 for p in r["prs"]):
            out.append(f"{r['repo']}: {round(h)} h sin fusiones y hay PRs en verde esperando.")
    for r in repos:
        for g in r["gates"]:
            out.append(f"Decisión tuya pendiente en {r['repo']} #{g['n']}: {g['title']}")
    return out


def get_data(force=False):
    with _lock:
        fresh = time.time() - _cache["at"] < CACHE_SECONDS
        if fresh and _cache["data"] and not force:
            return _cache["data"], None
        try:
            data = collect()
            _cache.update(at=time.time(), data=data, error=None)
            return data, None
        except Exception as exc:
            _cache["error"] = str(exc)[:300]
            return _cache["data"], _cache["error"]


DELIVERED = {}
ASSIGNED_FILE = HERE / "assigned.json"
try:
    ASSIGNED = {tuple(k.split("|", 1)): v for k, v in json.loads(ASSIGNED_FILE.read_text(encoding="utf-8")).items()}
except Exception:
    ASSIGNED = {}


BLOCKED_FILE = HERE / "agent_blocked.json"
try:
    AGENT_BLOCKED = json.loads(BLOCKED_FILE.read_text(encoding="utf-8"))
except Exception:
    AGENT_BLOCKED = {}  # "Repo#N" -> ts en que un agente respondió «bloqueado»
BLOCK_COOLDOWN_S = 30 * 60


def mark_agent_blocked(ref):
    AGENT_BLOCKED[ref] = time.time()
    try:
        BLOCKED_FILE.write_text(json.dumps(AGENT_BLOCKED), encoding="utf-8")
    except Exception:
        pass


def agent_blocked(ref):
    t = AGENT_BLOCKED.get(ref)
    return bool(t and time.time() - t < BLOCK_COOLDOWN_S)


def save_assigned():
    try:
        ASSIGNED_FILE.write_text(json.dumps({"|".join(k): v for k, v in ASSIGNED.items()}), encoding="utf-8")
    except Exception:
        pass
  # (iid, chat_id) -> {"issue": "Repo#N", "at": ts}
PLANNER = {"on": True, "last": 0, "per_repo": 12, "min_gap_s": 120, "max_active": 99, "per_tick": 8, "wait_external_s": 420}
PLANNER_STATE_FILE = Path(__file__).resolve().parent / "planner_state.json"


def planner_load_state():
    try:
        PLANNER["on"] = bool(json.loads(PLANNER_STATE_FILE.read_text(encoding="utf-8")).get("on", True))
    except Exception:
        pass


def planner_set(on):
    PLANNER["on"] = bool(on)
    try:
        PLANNER_STATE_FILE.write_text(json.dumps({"on": PLANNER["on"]}), encoding="utf-8")
    except Exception:
        pass


planner_load_state()


REVIEW_ASKED = {}  # (repo, pr, sha) -> ts ; evita repetir la petición para el mismo HEAD
REVIEW_NEXT_OK = {}  # repo -> ts. CodeRabbit OSS: 1–10 revisiones/hora por desarrollador y POR REPOSITORIO (repos con pocas estrellas: 1)
REVIEW_MIN_GAP_S = 65 * 60


def ask_coderabbit(repo, pr_n, pr_row=None):
    """Pide la revisión de CodeRabbit para el HEAD actual si aún no tiene una (o la saltó).
    Una sola vez por HEAD y con 15 min de pausa por PR. Devuelve True si comentó."""
    # La cuota gratuita es escasa y compartida: solo se pide con CI en verde y no más de una cada 45 min
    # en total (y nunca antes de que CodeRabbit diga que vuelve a haber cupo).
    if time.time() < REVIEW_NEXT_OK.get(repo, 0.0):
        return False
    if pr_row is not None and (pr_row.get("fails", 0) > 0 or pr_row.get("pend", 0) > 0):
        return False
    try:
        head = gh_json(["pr", "view", str(pr_n), "-R", f"{OWNER}/{repo}", "--json", "headRefOid,state"])
        if head.get("state") != "OPEN":
            return False
        sha = head["headRefOid"]
        key = (repo, pr_n, sha)
        if key in REVIEW_ASKED or any(k[:2] == (repo, pr_n) and time.time() - t < 3 * 3600 for k, t in REVIEW_ASKED.items()):
            return False
        revs = gh_json(["api", f"repos/{OWNER}/{repo}/pulls/{pr_n}/reviews", "--paginate"])
        if any((r.get("user") or {}).get("login", "").startswith("coderabbitai") and r.get("commit_id") == sha for r in revs):
            return False  # ya revisó este HEAD
        # Si CodeRabbit avisó de límite de cuota recientemente, no insistir.
        cmts = gh_json(["api", f"repos/{OWNER}/{repo}/issues/{pr_n}/comments?per_page=30"])
        for c in reversed(cmts[-10:]):
            body_c = c.get("body") or ""
            if (c.get("user") or {}).get("login", "").startswith("coderabbitai") and re.search(r"rate limit|review limit|quota|cuota", body_c, re.I):
                m = re.search(r"available in (\d+) (minute|hour)", body_c)
                wait = (int(m.group(1)) * (3600 if m.group(2) == "hour" else 60)) if m else REVIEW_MIN_GAP_S
                created = c.get("created_at", "")
                try:
                    t0 = time.mktime(time.strptime(created, "%Y-%m-%dT%H:%M:%SZ")) - time.timezone
                except Exception:
                    t0 = time.time()
                REVIEW_NEXT_OK[repo] = max(REVIEW_NEXT_OK.get(repo, 0.0), t0 + wait + 60)
                REVIEW_ASKED[key] = time.time()
                return False
        gh(["pr", "comment", str(pr_n), "-R", f"{OWNER}/{repo}", "--body", "@coderabbitai review"], timeout=30)
        REVIEW_ASKED[key] = time.time()
        REVIEW_NEXT_OK[repo] = time.time() + REVIEW_MIN_GAP_S
        log_action(f"OK   planner pidió revisión de CodeRabbit en {repo}#{pr_n} (HEAD {sha[:7]})")
        return True
    except Exception as exc:
        REVIEW_ASKED[(repo, pr_n, "err")] = time.time()
        log_action(f"FALLO planner CodeRabbit {repo}#{pr_n} -> {str(exc)[:120]}")
        return False


def unblock_repo(repo, rd, issue_n=None):
    """El orquestador destraba lo que puede por su cuenta antes de volver a escribirle al chat:
    ramas de agentes atrasadas respecto a main (update-branch). Solo PRs trabajo/issue-* abiertos."""
    done = []
    for p in rd.get("prs", []):
        key = (repo, p["n"])
        if p.get("merge") == "BEHIND" and p.get("issue") and key not in UNBLOCKED:
            try:
                gh(["api", "-X", "PUT", f"repos/{OWNER}/{repo}/pulls/{p['n']}/update-branch"], timeout=30)
                UNBLOCKED[key] = time.time()
                log_action(f"OK   planner destrabó {repo}#{p['n']} (rama actualizada con main)")
                done.append(key)
            except Exception as exc:
                UNBLOCKED[key] = time.time()
                log_action(f"FALLO planner update-branch {repo}#{p['n']} -> {str(exc)[:120]}")
    if issue_n is not None:
        for p in rd.get("prs", []):
            if p.get("issue") == issue_n and p.get("merge") != "DIRTY":
                if ask_coderabbit(repo, p["n"], p):
                    done.append((repo, p["n"]))
                break
    return done


REVIEW_SCAN_AT = {}  # repo -> ts del último barrido de la cola de revisiones (acota el gasto de API)


def dependents_count(rd, issue_n):
    """Cuántos issues dependen, directa o indirectamente, de issue_n (depends_on declarado)."""
    if not issue_n:
        return 0
    rev = {}
    for n, ds in (rd.get("deps") or {}).items():
        for d in ds:
            rev.setdefault(d, set()).add(n)
    seen, todo = set(), [issue_n]
    while todo:
        x = todo.pop()
        for y in rev.get(x, ()):
            if y not in seen:
                seen.add(y)
                todo.append(y)
    return len(seen)


def review_queue_tick():
    """CodeRabbit gratuito da ~1 revisión por hora por repo. Cuando el cupo de un repo se abre, se gasta en el PR
    que más trabajo desbloquea (APIs antes que sus pantallas), con CI en verde y sin conflicto."""
    data = _cache.get("data") or {}
    repos = data.get("repos") if isinstance(data, dict) else None
    if not isinstance(repos, list):
        return
    now = time.time()
    for rd in repos:
        repo = rd.get("repo")
        if not repo or now < REVIEW_NEXT_OK.get(repo, 0.0) or now - REVIEW_SCAN_AT.get(repo, 0) < 600:
            continue
        REVIEW_SCAN_AT[repo] = now
        cands = [p for p in rd.get("prs", []) if p.get("fails", 0) == 0 and p.get("pend", 0) == 0 and p.get("merge") != "DIRTY"
                 and not agent_blocked(f"{repo}#{p.get('issue')}")]
        ranked = sorted(cands, key=lambda p: (-dependents_count(rd, p.get("issue")), p["n"]))
        for p in ranked[:6]:
            if ask_coderabbit(repo, p["n"], p):
                log_action(f"OK   planner cola de revisiones {repo}: turno para #{p['n']} (desbloquea {dependents_count(rd, p.get('issue'))})")
                break


UNBLOCKED = {}
CI_DUR = {}  # repo -> (calculado_en, segundos típicos del CI)


def ci_seconds(repo):
    """Duración típica del CI de un repo (percentil 95 de las ejecuciones recientes de PR), cacheada 30 min."""
    got = CI_DUR.get(repo)
    if got and time.time() - got[0] < 1800:
        return got[1]
    secs = 240
    try:
        runs = gh_json(["run", "list", "-R", f"{OWNER}/{repo}", "--limit", "40",
                        "--json", "startedAt,updatedAt,conclusion,event,status"])
        durs = []
        for r in runs:
            if r.get("status") != "completed" or r.get("event") != "pull_request":
                continue
            t0 = time.mktime(time.strptime(r["startedAt"], "%Y-%m-%dT%H:%M:%SZ"))
            t1 = time.mktime(time.strptime(r["updatedAt"], "%Y-%m-%dT%H:%M:%SZ"))
            if 5 <= t1 - t0 <= 3600:
                durs.append(t1 - t0)
        if len(durs) >= 3:
            durs.sort()
            secs = int(durs[max(0, int(len(durs) * 0.95) - 1)])
    except Exception:
        pass
    CI_DUR[repo] = (time.time(), secs)
    return secs


def issue_fingerprint(rd, num):
    """Huella del estado de un issue en GitHub: cambia cuando hay algo nuevo que decirle al agente."""
    w = next((x for x in rd.get("work", []) if x.get("n") == num), None)
    if not w:
        return "sin-frente"
    pr = w.get("pr") or {}
    # Sin "pend": que arranque un CI no es novedad; sí lo es su resultado (fails/merge) o un PR nuevo.
    return "|".join(str(x) for x in (w.get("state"), pr.get("n"), pr.get("draft"), pr.get("merge"), pr.get("fails")))


BLOCKER_CACHE = {}   # "Repo#N" -> (ts, [Repo#M, ...])
OWNER_BLOCKERS = {}  # "Repo#N" -> {"since": ts, "blockers": [...], "why": str}
KW = re.compile(r"bloquead[oa] por|depende de|dependencia|requiere|blocked by|depends on|prerrequisito|desbloque", re.I)


def find_blockers(repo, num):
    """Issues abiertos del mismo repo que frenan a Repo#num (depends_on y menciones #M en líneas de bloqueo)."""
    key = f"{repo}#{num}"
    got = BLOCKER_CACHE.get(key)
    if got and time.time() - got[0] < 600:
        return got[1]
    refs = []
    try:
        i = gh_json(["issue", "view", str(num), "-R", f"{OWNER}/{repo}", "--json", "body,comments"])
        texts = [i.get("body") or ""] + [c.get("body") or "" for c in (i.get("comments") or [])[-4:]]
        deps, mentions = [], []
        for t in texts:
            m = re.search(r'"depends_on":\s*\[([^\]]*)\]', t)
            if m:
                deps += [int(x) for x in re.findall(r"\d+", m.group(1))]
            for line in t.splitlines():
                if KW.search(line):
                    mentions += [int(x) for x in re.findall(r"#(\d{1,6})\b", line)]
        found = deps + mentions  # primero lo declarado en depends_on
        refs = [f"{repo}#{n}" for n in dict.fromkeys(found) if n != num and n != 767][:8]
    except Exception:
        pass
    BLOCKER_CACHE[key] = (time.time(), refs)
    return refs


def unmet_deps(rd, num):
    """Dependencias declaradas (depends_on) de Repo#num que siguen abiertas: el issue aún no se puede trabajar."""
    openn = set(rd.get("open_numbers", []))
    return [d for d in (rd.get("deps") or {}).get(num, []) if d in openn]


def paths_collide(a, b):
    for x in a:
        xs = x.rstrip("*/")
        for y in b:
            ys = y.rstrip("*/")
            if x == y or xs.startswith(ys) or ys.startswith(xs):
                return True
    return False


def candidates(repos, taken):
    """Issues asignables, en orden: primero los frentes que requieren recuperación (bloquean trabajo
    nuevo), luego los disponibles, luego los desbloqueadores (resolver un bloqueo = trabajo útil)."""
    out = []
    for name, rd in repos.items():
        openn = set(rd.get("open_numbers", []))
        blocked_n = {b["n"] for b in rd.get("blocked", []) if b.get("cause") != "alerta automática"}
        alerts = [b for b in rd.get("blocked", []) if b.get("cause") == "alerta automática" and b["n"] in openn]
        rd = dict(rd, work=[w for w in rd.get("work", []) if w["n"] in openn and w["n"] not in blocked_n],
                  available=[x for x in rd.get("available", []) if x["n"] in openn and x["n"] not in blocked_n])
        # La coordinación pospone TODO trabajo nuevo de un repo mientras tenga frentes sin recuperar
        # («primero debe recuperarse #N»): en ese repo solo se asignan los frentes por recuperar.
        repo_has_recovery = any(w.get("state") == "requiere recuperación" for w in rd.get("work", []))
        if repo_has_recovery:
            rd = dict(rd, available=[])
        # Un issue cuyas rutas reclamadas chocan con las de otro issue ya reservado/en revisión se serializa.
        held = [p for n_, st_ in (rd.get("states") or {}).items() if st_ in ("reservado", "en revisión", "requiere recuperación")
                for p in (rd.get("claims") or {}).get(n_, [])]
        rd = dict(rd, available=[x for x in rd.get("available", [])
                                 if not paths_collide((rd.get("claims") or {}).get(x["n"], []), held)
                                 and not unmet_deps(rd, x["n"])])
        for w in rd.get("work", []):
            if w.get("state") == "requiere recuperación":
                out.append((0, -(w.get("h") or 0), f"{name}#{w['n']}"))
            elif w.get("state") == "en revisión":
                # En revisión pero atascado (PR en borrador, con fallos, atrasado o con conflicto): nadie lo
                # empuja a la fusión; es trabajo real para un chat (hilos de revisión, CI, merge).
                pr = w.get("pr") or {}
                if pr and (pr.get("draft") or pr.get("fails", 0) > 0 or pr.get("merge") in ("BEHIND", "DIRTY", "UNSTABLE")):
                    # Un borrador limpio (CI verde, sin conflicto) solo espera su turno de revisión: un mensaje
                    # al chat no lo adelanta, así que va DESPUÉS del trabajo nuevo disponible.
                    waiting_review = (pr.get("draft") and pr.get("fails", 0) == 0 and pr.get("pend", 0) == 0
                                      and pr.get("merge") == "CLEAN")
                    out.append((1.6 if waiting_review else 0.5, -(w.get("h") or 0), f"{name}#{w['n']}"))
        for it in rd.get("available", []):
            out.append((1, -(it.get("h") or 0), f"{name}#{it['n']}"))
        # Alertas automáticas abiertas: sí son trabajo para un chat (investigar y resolver la causa), tras lo demás.
        for it in alerts:
            out.append((2, -(it.get("h") or 0), f"{name}#{it['n']}"))
        # Los desbloqueadores NO se asignan por defecto: casi siempre están ellos mismos bloqueados y el
        # agente solo contestaría «bloqueado». Solo entran si dejan de estar bloqueados (pasan a disponible).
    return [ref for _, _, ref in sorted(out) if ref not in taken and not agent_blocked(ref)]


UNBLOCK_MODE = {}  # "Repo#N" -> ts en que se asignó un issue bloqueado para que el chat lo desbloquee


UNBLOCK_TRIES = {}  # ref -> [timestamps] de intentos de desbloqueo ya enviados


def unblock_tries_recent(ref, now=None):
    now = now or time.time()
    return [t for t in UNBLOCK_TRIES.get(ref, []) if now - t < 6 * 3600]


def unblock_candidates(repos, taken):
    """Issues bloqueados que SÍ son tarea (no roadmap, readiness ni alerta): el chat debe destrabarlos."""
    out = []
    for name, rd in repos.items():
        openn = set(rd.get("open_numbers", []))
        for b in rd.get("blocked", []):
            cause = (b.get("cause") or "").lower()
            if b["n"] not in openn or any(w in cause for w in ("roadmap", "readiness", "alerta", "planificado")):
                continue
            ref = f"{name}#{b['n']}"
            if ref in taken or agent_blocked(ref):
                continue
            if len(unblock_tries_recent(ref)) >= 2:
                continue  # ya se intentó 2 veces en 6 h sin lograrlo: no se insiste, el chat debe hacer otra cosa
            out.append((-(b.get("h") or 0), ref))
    return [r for _, r in sorted(out)]


def chat_items(cur):
    """Issues que lleva un chat: el actual y los aparcados (esperando algo externo)."""
    if not cur or not cur.get("issue"):
        return []
    items = [{"issue": cur["issue"], "at": cur["at"], "n": cur.get("n", 1), "fp": cur.get("fp")}]
    items += [dict(p) for p in cur.get("parked", [])]
    return items


def issue_waiting(rd, num, chat_phase, is_current):
    """¿El issue solo espera algo externo (CI corriendo, revisión/gate) y el chat no puede hacer más?"""
    w = next((x for x in rd.get("work", []) if x.get("n") == num), None)
    pr = (w or {}).get("pr") or {}
    if pr.get("pend", 0) > 0:
        return True  # CI corriendo
    if pr and not pr.get("draft") and pr.get("fails", 0) == 0 and pr.get("merge") == "BLOCKED":
        return True  # PR listo esperando revisión / puertas externas
    return bool(is_current and chat_phase == "esperando_externo")


def persist_items(key, items):
    """Guarda la lista de issues de un chat tras soltar alguno (evita repetir el mismo descarte cada ciclo)."""
    if items:
        head = items[0]
        ASSIGNED[key] = {"issue": head["issue"], "at": head["at"], "n": head.get("n", 1), "fp": head.get("fp"),
                         "parked": [dict(i) for i in items[1:3]]}
    else:
        ASSIGNED[key] = {"at": time.time(), "n": 0}
    save_assigned()


def steal_stuck(issue, except_key, repos, dry=False):
    """Quita `issue` a otro chat solo si ese chat lo lleva estancado (3+ envíos sin cambios en GitHub)."""
    for k, v in list(ASSIGNED.items()):
        if k == except_key:
            continue
        its = chat_items(v)
        hit = next((i for i in its if i["issue"] == issue), None)
        if not hit:
            continue
        r_, n_ = issue.split("#")
        fp = issue_fingerprint(repos.get(r_) or {}, int(n_))
        if hit.get("n", 1) >= 3 and hit.get("fp") == fp:
            if dry:
                return True
            persist_items(k, [i for i in its if i["issue"] != issue])
            log_action(f"OK   planner {issue} quitado al chat {k[1][-4:]} (estancado) para dárselo a quien lo nombró como bloqueador")
            return True
        return False
    return True  # nadie lo tiene


def plan_tick():
    """El objetivo es que NINGÚN chat dirigido quede libre: cada chat ocioso recibe trabajo ya.
    Prioridad por chat ocioso: (A) un issue suyo cuyo estado cambió en GitHub, (B) un issue nuevo
    de la cola (paralelizar al máximo; el anterior queda aparcado y vuelve a su turno), (C) reenviar
    el más antiguo tras un retroceso creciente (1,5 / 3 / 6 / 10 min). Issue cerrado -> se descarta;
    issue bloqueado -> se descarta (el desbloqueador entra por la cola). Una orden por ciclo."""
    if not PLANNER["on"]:
        return []
    data = _cache.get("data") or {}
    repos = {r["repo"]: r for r in data.get("repos", [])} if isinstance(data, dict) else {}
    if not repos:
        return []
    now = time.time()
    # Recuperación automática: un frente «requiere recuperación» (30 min sin actividad en GitHub) frena TODO el
    # trabajo nuevo del repo. Se re-toma con /tomar (mismo mecanismo que usan los chats): máx. 3 por ciclo y una
    # vez cada 25 min por issue, conservando rama y PR.
    # Chats que ya no existen (pestaña cerrada o reabierta con otro id): sus issues quedan libres tras 10 min.
    live_keys = {(iid_, ch_["chat_id"]) for iid_, ag_ in AF.items() if now - ag_["seen"] <= 180 for ch_ in ag_["chats"]}
    dead = [k_ for k_, v_ in ASSIGNED.items() if k_ not in live_keys and now - v_.get("at", now) > 600
            and k_[0] in AF and now - AF[k_[0]]["seen"] <= 180]
    for k_ in dead:
        log_action(f"OK   planner chat {k_[1]} ya no existe: libera {ASSIGNED[k_].get('issue')}")
        del ASSIGNED[k_]
    if dead:
        save_assigned()
    n_tomar = 0
    for rname, rd_ in repos.items():
        for w_ in rd_.get("work", []):
            if w_.get("state") != "requiere recuperación" or n_tomar >= 10:
                continue
            if re.match(r"(epic|roadmap|planificaci|planning)", (w_.get("title") or "").strip().lower()):
                continue  # un contenedor de roadmap no se reserva ni se recupera: no es trabajo de un chat
            tk = f"{rname}#{w_['n']}"
            if now - TOMAR_AT.get(tk, 0) < 900:
                continue
            TOMAR_AT[tk] = now
            n_tomar += 1
            try:
                gh(["issue", "comment", str(w_["n"]), "-R", f"{OWNER}/{rname}", "--body", "/tomar"])
                log_action(f"OK   planner recuperó automáticamente {tk} (/tomar): su reserva llevaba 30+ min sin actividad")
            except Exception as exc:
                log_action(f"FALLO planner no pudo recuperar {tk}: {str(exc)[:80]}")
    active = sum(1 for ag in AF.values() if now - ag["seen"] <= 180
                 for ch in ag["chats"] if ch["directed"] and ch["state"] == "generando")
    if active >= PLANNER["max_active"]:
        return []

    def alive(item):
        repo, num = item["issue"].split("#")
        rd = repos.get(repo) or {}
        n_ = int(num)
        if n_ not in rd.get("open_numbers", []):
            return False
        if any(x["n"] == n_ and x.get("cause") != "alerta automática" for x in rd.get("blocked", [])):
            return time.time() - UNBLOCK_MODE.get(item["issue"], 0) < 1800  # asignado para desbloquearlo
        st = (rd.get("states") or {}).get(n_)
        # La coordinación no concede trabajo nuevo si el repo tiene frentes por recuperar, ni si las rutas
        # chocan con un issue ya reservado: seguir reenviándolo solo produce «BLOQUEADO».
        if st == "disponible":
            if any(v == "requiere recuperación" for v in (rd.get("states") or {}).values()):
                return False
            held = [p for k_, st_ in (rd.get("states") or {}).items() if k_ != n_ and st_ in ("reservado", "en revisión", "requiere recuperación")
                    for p in (rd.get("claims") or {}).get(k_, [])]
            if paths_collide((rd.get("claims") or {}).get(n_, []), held):
                return False
        return True

    done = []
    idle = []
    for iid, ag in list(AF.items()):
        if now - ag["seen"] > 180:
            continue
        for ch in ag["chats"]:
            reloaded = (not ch["directed"] and ch["state"] in ("desconocido", "esperando_envio")
                        and (iid, ch["chat_id"]) in ASSIGNED)  # pestaña recargada: perdió la marca de dirigido
            if (ch["directed"] and ch["state"] == "esperando_envio") or reloaded:
                idle.append((ASSIGNED.get((iid, ch["chat_id"]), {}).get("at", 0), iid, ch))
    idle.sort(key=lambda x: x[0])  # primero el chat que lleva más tiempo sin recibir nada
    if True:
        for _, iid, ch in idle:
            key = (iid, ch["chat_id"])
            if key in CMD and not CMD[key]["acked"]:
                # La extensión no confirma las órdenes por chat (por diseño): se dan por entregadas a los 90 s.
                if now - CMD[key].get("at", now) < 90:
                    continue
                del CMD[key]
            cur = ASSIGNED.get(key) if ASSIGNED.get(key, {}).get("issue") else None
            if cur and now - cur["at"] < 150:
                continue  # la orden tarda hasta ~90 s en entregarse y ~30 s en reflejarse en su estado
            items = [it for it in chat_items(cur) if alive(it)]
            # Un issue cuyas dependencias declaradas siguen abiertas y que aún no tiene PR no se puede avanzar:
            # reenviarlo solo gasta turnos. Se suelta y el chat toma trabajo que sí avanza.
            _waiting = []
            for it in items:
                _r, _n = it["issue"].split("#")
                _rd = repos.get(_r) or {}
                _w = next((x for x in _rd.get("work", []) if x.get("n") == int(_n)), None)
                if unmet_deps(_rd, int(_n)) and not (_w or {}).get("pr"):
                    _waiting.append(it)
            if _waiting:
                for it in _waiting:
                    log_action(f"OK   planner {it['issue']} soltado: espera sus dependencias {unmet_deps(repos.get(it['issue'].split('#')[0]) or {}, int(it['issue'].split('#')[1]))}")
                items = [it for it in items if it not in _waiting]
                persist_items(key, items)
            unblock_target = None
            # El agente respondió «bloqueado» a su issue actual: se aparta de él ya (3 h) y recibe otro.
            if cur and items and ch.get("phase") == "bloqueado" and now - cur["at"] > 20:
                mark_agent_blocked(cur["issue"])
                brepo, bnum = cur["issue"].split("#")
                brd = repos.get(brepo) or {}
                openn = set(brd.get("open_numbers", []))
                workable = {f"{brepo}#{w['n']}" for w in brd.get("work", [])} | {f"{brepo}#{x['n']}" for x in brd.get("available", [])}
                named = [f"{brepo}#{n}" for n in (ch.get("refs") or []) if n != int(bnum)]
                blockers = [b for b in dict.fromkeys(named + find_blockers(brepo, int(bnum))) if int(b.split("#")[1]) in openn]
                try_first = [b for b in blockers if b in workable and not agent_blocked(b)
                             and steal_stuck(b, key, repos, dry=True)]
                if try_first:
                    unblock_target = try_first[0]
                    steal_stuck(unblock_target, key, repos)
                    log_action(f"OK   planner {cur['issue']} bloqueado por {unblock_target}: el chat trabaja el desbloqueo")
                else:
                    unblock_target = None
                    OWNER_BLOCKERS[cur["issue"]] = {"since": now, "blockers": blockers[:4],
                                                    "why": "el agente respondió «bloqueado» y ningún bloqueador es trabajable por un chat"}
                    log_action(f"OK   planner {cur['issue']} necesita al dueño/administración (bloqueadores: {', '.join(blockers[:4]) or 'no identificados'})")
                items = [it for it in items if it["issue"] != cur["issue"]]
                persist_items(key, items)
            # Un issue con 4+ envíos y ningún cambio en GitHub no avanza con más mensajes: se aparta 3 h.
            _quiet = [it for it in items if it.get("n", 1) >= 4 and it.get("fp") is not None
                      and it["fp"] == issue_fingerprint(repos.get(it["issue"].split("#")[0]) or {}, int(it["issue"].split("#")[1]))]
            if _quiet:
                for it in _quiet:
                    mark_agent_blocked(it["issue"])
                    AGENT_BLOCKED[it["issue"]] = time.time() + (3 * 3600 - BLOCK_COOLDOWN_S)  # apartado 3 h en total
                    log_action(f"OK   planner {it['issue']} apartado 3 h: {it.get('n', 1)} envíos sin ningún cambio en GitHub")
                items = [it for it in items if it not in _quiet]
                persist_items(key, items)
            # issues tomados por TODOS los chats (actuales y aparcados) para no duplicar
            taken = set()
            per_repo = {}
            for k, v in ASSIGNED.items():
                for it in chat_items(v):
                    if k == key and it not in items and it["issue"] not in {i["issue"] for i in items}:
                        continue  # los descartados de este chat quedan libres
                    taken.add(it["issue"])
                    per_repo[it["issue"].split("#")[0]] = per_repo.get(it["issue"].split("#")[0], 0) + 1
            pick, why, chosen = None, "", None
            # A) cambió algo en GitHub
            for it in items:
                repo, num = it["issue"].split("#")
                fp = issue_fingerprint(repos.get(repo) or {}, int(num))
                if it.get("fp") is not None and it["fp"] != fp and now - it["at"] >= PLANNER["min_gap_s"]:
                    pick, why, chosen = it["issue"], "cambió el estado", it
                    break
            # B) trabajo nuevo, en dos casos: (1) el chat no tiene issue vivo (terminó o se bloqueó), o
            #    (2) TODOS sus issues solo esperan algo externo (CI/revisión): no se queda ocioso, adelanta
            #    otro issue (máx. 3 por chat) y vuelve al anterior cuando cambie su estado.
            def stuck(it):
                # 3+ envíos del mismo issue sin ningún cambio en GitHub: el agente no avanza con él
                r_, n_ = it["issue"].split("#")
                return it.get("n", 1) >= 3 and it.get("fp") == issue_fingerprint(repos.get(r_) or {}, int(n_))
            # Un chat con 3 issues y todos estancados/esperando no avanza: se suelta el que lleva más
            # reenvíos sin cambios (queda apartado 3 h) para dejar sitio a trabajo nuevo.
            if len(items) >= 3 and all(
                    issue_waiting(repos.get(it["issue"].split("#")[0]) or {}, int(it["issue"].split("#")[1]),
                                  ch.get("phase"), it["issue"] == (cur or {}).get("issue")) or stuck(it) for it in items):
                worst = max(items, key=lambda x: x.get("n", 1))
                mark_agent_blocked(worst["issue"])
                log_action(f"OK   planner {worst['issue']} soltado por estancamiento ({worst.get('n', 1)} envíos sin cambios): el chat toma trabajo nuevo")
                items = [it for it in items if it["issue"] != worst["issue"]]
                persist_items(key, items)
            can_advance = bool(items) and len(items) < 3 and all(
                issue_waiting(repos.get(it["issue"].split("#")[0]) or {}, int(it["issue"].split("#")[1]),
                              ch.get("phase"), it["issue"] == (cur or {}).get("issue")) or stuck(it) for it in items)
            if not pick and (not items or can_advance):
                prev = cur["issue"] if cur else None
                blocked_prev = False
                if prev and not items:
                    prepo, pnum = prev.split("#")
                    prd = repos.get(prepo) or {}
                    blocked_prev = int(pnum) in prd.get("open_numbers", [])  # abierto pero no vivo = bloqueado
                cands = [c for c in candidates(repos, taken) if per_repo.get(c.split("#")[0], 0) < PLANNER["per_repo"]]
                if unblock_target and unblock_target not in taken:
                    cands = [unblock_target] + [c for c in cands if c != unblock_target]
                    why_override = "desbloquea el issue anterior"
                else:
                    why_override = None
                if blocked_prev:
                    same = [c for c in cands if c.split("#")[0] == prev.split("#")[0]]
                    cands = same + [c for c in cands if c not in same]
                if cands:
                    pick = cands[0]
                    if why_override and pick == unblock_target:
                        why = "trabaja el desbloqueo del issue anterior"
                    elif items:
                        why = "adelanta otro issue mientras el anterior espera CI/revisión"
                    else:
                        why = "nuevo" if not prev else ("su issue se bloqueó: trabaja el desbloqueo" if blocked_prev else "terminó su issue")
            # B1) Chat ocioso hace 5+ min con menos de 3 issues: toma un issue real de la cola antes que el repo completo.
            if not pick and (not items or now - max(i["at"] for i in items) >= 120):
                free = [c for c in candidates(repos, taken) if per_repo.get(c.split("#")[0], 0) < PLANNER["per_repo"]]
                if free and len(items) >= 3:
                    # Chat saturado de issues atascados: hay trabajo real disponible, así que suelta el más antiguo.
                    stale = min(items, key=lambda x: x["at"])
                    log_action(f"OK   planner {stale['issue']} soltado: el chat toma trabajo real disponible ({free[0]})")
                    items = [i for i in items if i["issue"] != stale["issue"]]
                    persist_items(key, items)
                if free and len(items) < 3:
                    pick, why = free[0], "ocioso: toma trabajo nuevo de la cola"
                elif len(items) < 3:
                    unb = unblock_candidates(repos, taken)
                    if unb:
                        pick, why = unb[0], "intenta desbloquearlo (resolver el bloqueo, no reportarlo)"
                        UNBLOCK_MODE[pick] = now
                        UNBLOCK_TRIES.setdefault(pick, []).append(now)
            if pick and chosen is None and why == "ocioso: toma trabajo nuevo de la cola":
                pass
            # B2) Sin trabajo asignable y chat ocioso: no se repite el mismo issue cada pocos minutos; se le da
            #     el repositorio completo para que siga la escalera de PLAN-AGENTES (inventario, descomponer
            #     hojas ejecutables, destrabar PRs). Un envío por chat cada 20 min, rotando de repositorio.
            if not pick and (not items or now - min(i["at"] for i in items) >= 300):
                if now - REPO_SENT.get(key, (0, 0))[0] >= 1200:
                    pool = sorted([r_ for r_, d_ in repos.items() if d_.get("open_numbers")],
                                  key=lambda r_: -len(repos[r_].get("open_numbers", [])))
                    if pool:
                        REPO_RR[0] += 1
                        rname = pool[REPO_RR[0] % len(pool)]
                        REPO_SENT[key] = (now, REPO_RR[0])
                        set_command({"instance_id": iid, "chat_id": ch["chat_id"], "want": "assign", "repo": rname})
                        log_action(f"OK   planner {iid}/{ch['chat_id']} -> repo {rname} (sin issue asignable: sigue la escalera de PLAN-AGENTES)")
                        done.append((iid, ch["chat_id"], rname))
                        continue
            # C) reenviar el issue actual solo si lleva rato ocioso (retroceso creciente 5, 10, 20 min)
            if not pick and items:
                it = min(items, key=lambda x: x["at"])
                backoff = min(900, 120 * (2 ** max(0, it.get("n", 1) - 1)))
                r_, n_ = it["issue"].split("#")
                if it.get("n", 1) >= 3 and it.get("fp") == issue_fingerprint(repos.get(r_) or {}, int(n_)):
                    backoff = 3600  # estancado sin cambios en GitHub: un mensaje cada 30 min, no cada pocos  # 90 s, 3 min, 6 min, 10 min: un chat ocioso no espera mucho
                if now - it["at"] >= backoff:
                    pick, why, chosen = it["issue"], "continuar", it
            if not pick:
                if int(now) % 600 < 60:
                    log_action(f"DIAG chat {ch['chat_id']} ocioso sin orden: cur={cur and cur['issue']} items={[(i['issue'], i.get('n'), round(now - i['at'])) for i in items]} can_advance={can_advance} cands={len(candidates(repos, taken))}")
                continue
            repo, num = pick.split("#")
            fp = issue_fingerprint(repos.get(repo) or {}, int(num))
            if chosen is not None:
                unblock_repo(repo, repos.get(repo) or {}, int(num))
            n_new = (chosen.get("n", 1) + 1) if chosen is not None else 1
            parked = [{"issue": i["issue"], "at": i["at"], "n": i.get("n", 1), "fp": i.get("fp")}
                      for i in items if i["issue"] != pick][:2]
            set_command({"instance_id": iid, "chat_id": ch["chat_id"], "want": "assign", "issue": pick})
            ASSIGNED[key] = {"issue": pick, "at": now, "n": n_new, "fp": fp, "parked": parked}
            save_assigned()
            log_action(f"OK   planner {iid}/{ch['chat_id']} -> {pick} ({why}, envío {n_new}, {len(parked)} aparcados)")
            done.append((iid, ch["chat_id"], pick))
            if len(done) >= PLANNER["per_tick"]:
                break
    return done


REPO_SENT = {}
TOMAR_AT = {}
DBG_SEEN = {}
REPO_RR = [0]
OWNER_TASKS = {"at": 0, "items": []}


def _http_json(url, timeout=12):
    try:
        with urllib.request.urlopen(urllib.request.Request(url, headers={"User-Agent": "orq-local"}), timeout=timeout) as r:
            return r.status, json.loads(r.read().decode("utf-8", "ignore"))
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read().decode("utf-8", "ignore"))
        except Exception:
            return e.code, None
    except Exception:
        return 0, None


def _secrets(repo):
    try:
        out = gh(["secret", "list", "-R", f"{OWNER}/{repo}", "--json", "name"])
        return {x["name"] for x in json.loads(out)}
    except Exception:
        return None


def collect_owner_tasks():
    """Decisiones y acciones que solo puede hacer el dueño, con verificación automática del estado real."""
    items = []
    code, h = _http_json("https://www.brvtal.com.co/api/health.php")
    pend = (h or {}).get("schema", {}).get("pending") if isinstance(h, dict) else None
    items.append({"id": "brvtal-migracion", "titulo": "Aplicar la migración pendiente de brvtal en producción",
        "hecho": bool(h) and pend == 0 and h.get("status") == "ok",
        "estado": ("producción al día: 0 migraciones pendientes" if (h and pend == 0 and h.get("status") == "ok")
                   else (f"{pend} migración pendiente · salud {code}" if pend is not None else "no se pudo leer la salud")),
        "destraba": 9, "repo": "brvtal", "issues": ["#739", "#993", "#984", "#969"],
        "como": "Comentar exactamente /reconcile-production-migrations en el issue #681 de brvtal (ya hace su propia copia de respaldo)."})
    need = {"SSH_HOST", "DEPLOY_SSH_KEY", "HOSTINGER_KNOWN_HOSTS"}
    for repo in ("GrindFlow", "ControlBot"):
        names = _secrets(repo)
        missing = sorted(need - names) if names is not None else None
        items.append({"id": f"secretos-{repo}", "titulo": f"Guardar los accesos de despliegue de Hostinger en {repo}",
            "hecho": missing == [], "estado": ("accesos completos" if missing == [] else ("faltan: " + ", ".join(missing) if missing is not None else "no se pudo comprobar")),
            "destraba": 3, "repo": repo, "issues": ["#625", "#45"] if repo == "ControlBot" else ["#2"],
            "como": "Crearlos como secretos del repositorio en GitHub (Settings → Secrets). Nunca por chat ni en un issue."})
    code2, _ = _http_json("https://control.condorapp.com.co/")
    items.append({"id": "controlbot-backup", "titulo": "Backup real de ControlBot (D-059) para abrir el sitio",
        "hecho": code2 in (200, 401), "estado": ("sitio en línea (protegido con acceso)" if code2 in (200, 401) else f"sitio responde {code2 or 'sin respuesta'} (cerrado hasta el backup)"),
        "destraba": 6, "repo": "ControlBot", "issues": ["AutoFactory #129–#134", "ControlBot #625"],
        "como": "Hacer y verificar un backup real; luego los agentes abren el sitio."})
    try:
        i = gh_json(["issue", "view", "453", "-R", f"{OWNER}/GrindFlow", "--json", "state"])
        items.append({"id": "grindflow-facebook", "titulo": "Autorizar la publicación de prueba en la Page de Facebook (GrindFlow #453)",
            "hecho": i.get("state") != "OPEN", "estado": "aplazado por el dueño" if i.get("state") == "OPEN" else "resuelto",
            "destraba": 4, "repo": "GrindFlow", "issues": ["#271", "#453"], "decision": {"repo": "GrindFlow", "n": 453}, "como": "Decidir si se hace el piloto; si sigue aplazado, no hay nada que hacer."})
    except Exception:
        pass
    items.append({"id": "deepl", "titulo": "Cuenta y clave de DeepL para traducir brvtal (opcional)",
        "hecho": False, "estado": "sin autorizar (el adaptador con transporte falso sí avanza)", "destraba": 1, "repo": "brvtal", "decision": {"repo": "brvtal", "n": 920},
        "issues": ["#212", "#967"], "como": "Crear la cuenta, guardar la clave como secreto y autorizar el gasto."})
    OWNER_TASKS["items"] = items
    OWNER_TASKS["at"] = time.time()


def set_command(b):
    iid = str(b.get("instance_id", ""))
    chat = b.get("chat_id")
    want = b.get("want")
    issue = b.get("issue")
    if not re.fullmatch(r"[A-Za-z0-9_.:-]{3,64}", iid) or want not in ("stop", "start", "assign"):
        raise ValueError("orden inválida")
    repo = b.get("repo")
    if want == "assign" and (not chat or not (re.fullmatch(r"[A-Za-z][A-Za-z0-9_-]{0,39}#[1-9]\d{0,9}", str(issue))
                                              or repo in REPOS)):
        raise ValueError("asignación inválida (chat e issue Repo#N o repo conocido)")
    if chat is not None and not re.fullmatch(r"[A-Za-z0-9_.:-]{2,40}", str(chat)):
        raise ValueError("chat_id inválido")
    if iid not in AF:
        raise ValueError("agente desconocido (aún no ha enviado señal)")
    CMD_SEQ[0] += 1
    key = (iid, str(chat) if chat else "*")
    if key[1] == "*":  # una orden global reemplaza las órdenes por chat
        for k in [k for k in CMD if k[0] == iid]:
            del CMD[k]
    CMD[key] = {"want": want, "id": CMD_SEQ[0], "at": time.time(), "acked": False}
    if want == "assign":
        if repo in REPOS:
            CMD[key]["repo"] = repo
            return "repo asignado: " + repo
        CMD[key]["issue"] = str(issue)
        return "issue asignado: " + str(issue)
    return "orden enviada: " + ("parar" if want == "stop" else "arrancar")


def pending_for(iid):
    out = []
    for k, c in CMD.items():
        if k[0] == iid and not c["acked"]:
            row = {"id": c["id"], "chat_id": (None if k[1] == "*" else k[1]), "want": c["want"]}
            if c["want"] == "assign":
                if c.get("repo"):
                    row["repo"] = c["repo"]
                else:
                    row["issue"] = c["issue"]
            out.append(row)
    return out


def ack_command(b):
    iid, cid = str(b.get("instance_id", "")), b.get("id")
    for k, c in CMD.items():
        if k[0] == iid and c["id"] == cid:
            c["acked"] = True
            c["acked_at"] = time.time()
            return
    raise ValueError("orden desconocida")



def safe_extension_origin(origin):
    """Accept only one extension origin without a port, path or CR/LF."""
    if not isinstance(origin, str) or len(origin) > 160:
        return None
    if re.fullmatch(r"(?:chrome-extension|safari-web-extension|moz-extension)://[A-Za-z0-9][A-Za-z0-9-]{0,127}", origin) is None:
        return None
    return origin


class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt, *a):
        # Solo trazas del enlace con AutoFactory, como máximo una por minuto.
        line = (fmt % a) if a else str(fmt)
        kind = line.split(" ")[0] + (" cmd" if "/api/af/commands" in line else " ack" if "/api/af/ack" in line else "")
        if "/api/af" in line and time.time() - Handler._af_log_at.get(kind, 0) > 60:
            Handler._af_log_at[kind] = time.time()
            log_action(f"AF petición: {line[:80]} origin={self.headers.get('Origin', '-')[:45]}")

    _af_log_at = {}

    def _send(self, code, body, ctype):
        raw = body.encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(raw)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(raw)

    def do_OPTIONS(self):
        origin = self._ext_origin()
        ok = origin is not None
        self.send_response(204 if ok else 403)
        if ok:
            self.send_header("Access-Control-Allow-Origin", origin.replace("\r", "").replace("\n", ""))
            self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
            self.send_header("Access-Control-Allow-Headers", "Content-Type, X-Agent-Key")
        self.end_headers()

    def _is_local(self):
        return self.client_address[0] in ("127.0.0.1", "::1")

    def _agent_ok(self):
        """Loopback conserva el modo anterior (solo origen de extensión); la LAN exige la clave."""
        if self._is_local():
            return True
        return secrets.compare_digest(self.headers.get("X-Agent-Key", ""), AGENT_KEY)

    def _lan_viewer(self):
        for part in self.headers.get("Cookie", "").split(";"):
            k, _, v = part.strip().partition("=")
            if k == "orq_sess" and secrets.compare_digest(v, SESSION):
                return True
        return False

    def _ext_origin(self):
        return safe_extension_origin(self.headers.get("Origin", ""))

    def _json_ext(self, code, obj, origin):
        """Nunca reflejar cabeceras no validadas, incluso si cambia el caller."""
        allowed = "*" if origin == "*" else safe_extension_origin(origin)
        raw = json.dumps(obj).encode("utf-8")
        self.send_response(code)
        if allowed is not None:
            self.send_header("Access-Control-Allow-Origin", allowed.replace("\r", "").replace("\n", ""))
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(raw)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(raw)

    def _same_origin(self):
        host = self.headers.get("Host", "")
        origin = self.headers.get("Origin", "")
        if not self._is_local():
            return False
        return host in (f"127.0.0.1:{PORT}", f"localhost:{PORT}") and origin in ("", f"http://127.0.0.1:{PORT}", f"http://localhost:{PORT}")

    def do_POST(self):
        if self.path == "/api/action":
            try:
                if not self._same_origin() or self.headers.get("X-Orq-Token") != TOKEN:
                    return self._send(403, '{"error":"no autorizado"}', "application/json")
                n = int(self.headers.get("Content-Length", "0"))
                if not 0 < n <= 2048:
                    raise ValueError("tamaño inválido")
                body = json.loads(self.rfile.read(n).decode("utf-8"))
                msg = run_action(body)
                log_action(f"OK   {body.get('action')} {body.get('repo')}#{body.get('number')} -> {msg}")
                _cache["at"] = 0  # fuerza lectura nueva
                return self._send(200, json.dumps({"ok": True, "msg": msg}), "application/json")
            except Exception as exc:
                log_action(f"FALLO {self.path} -> {str(exc)[:200]}")
                return self._send(400, json.dumps({"ok": False, "error": str(exc)[:240]}), "application/json")
        if self.path == "/login":
            n = int(self.headers.get("Content-Length", "0") or 0)
            form = dict(p.split("=", 1) for p in self.rfile.read(min(n, 256)).decode("utf-8", "ignore").split("&") if "=" in p)
            if secrets.compare_digest(form.get("key", ""), AGENT_KEY):
                self.send_response(303)
                self.send_header("Set-Cookie", f"orq_sess={SESSION}; HttpOnly; SameSite=Strict; Path=/; Max-Age=2592000")
                self.send_header("Location", "/")
                self.end_headers()
                return
            return self._send(401, LOGIN_HTML.replace("__MSG__", "<span style=color:#f85149>Clave incorrecta</span>"), "text/html; charset=utf-8")
        if self.path == "/api/planner":
            try:
                if not self._same_origin() or self.headers.get("X-Orq-Token") != TOKEN:
                    return self._send(403, '{"error":"no autorizado"}', "application/json")
                n = int(self.headers.get("Content-Length", "0"))
                if not 0 < n <= 128:
                    raise ValueError("tamaño inválido")
                body = json.loads(self.rfile.read(n).decode("utf-8"))
                if type(body.get("on")) is not bool:
                    raise ValueError("on debe ser booleano")
                planner_set(body["on"])
                log_action(f"OK   reparto de chats {'ACTIVADO' if PLANNER['on'] else 'DETENIDO'} desde el panel")
                return self._send(200, json.dumps({"ok": True, "on": PLANNER["on"]}), "application/json")
            except Exception as exc:
                return self._send(400, json.dumps({"ok": False, "error": str(exc)[:200]}), "application/json")
        if self.path == "/api/agent-cmd":
            try:
                if not self._same_origin() or self.headers.get("X-Orq-Token") != TOKEN:
                    return self._send(403, '{"error":"no autorizado"}', "application/json")
                n = int(self.headers.get("Content-Length", "0"))
                if not 0 < n <= 512:
                    raise ValueError("tamaño inválido")
                body = json.loads(self.rfile.read(n).decode("utf-8"))
                msg = set_command(body)
                log_action(f"OK   agent-cmd {body.get('instance_id')} {body.get('chat_id') or '*'} -> {body.get('want')}")
                return self._send(200, json.dumps({"ok": True, "msg": msg}), "application/json")
            except Exception as exc:
                return self._send(400, json.dumps({"ok": False, "error": str(exc)[:200]}), "application/json")
        origin = self._ext_origin()
        if self.path not in ("/api/af", "/api/af/ack", "/api/af/log") or not origin:
            log_action(f"AF rechazado: origen/ruta no permitido ({self.path}, origin={self.headers.get('Origin','-')[:40]})")
            return self._send(403, '{"error":"origen no permitido"}', "application/json")
        if not self._agent_ok():
            log_action("AF rechazado: clave de agente inválida")
            return self._send(401, '{"error":"clave de agente inválida"}', "application/json")
        if self.path == "/api/af/log":
            try:
                n = int(self.headers.get("Content-Length", "0"))
                if not 0 < n <= 65536:
                    raise ValueError("tamaño inválido")
                b = json.loads(self.rfile.read(n).decode("utf-8"))
                iid = str(b.get("instance_id", ""))[:8]
                entries = b.get("entries")
                if not isinstance(entries, list):
                    raise ValueError("entries inválido")
                path = HERE / "af_logs.jsonl"
                if path.exists() and path.stat().st_size > 5_000_000:
                    path.write_text("".join(path.read_text(encoding="utf-8").splitlines(True)[-2000:]), encoding="utf-8")
                with path.open("a", encoding="utf-8") as fh:
                    for e in entries[:50]:
                        fh.write(json.dumps({"iid": iid, "e": e}, ensure_ascii=False)[:1200] + "\n")
                return self._json_ext(200, {"ok": True}, origin)
            except Exception as exc:
                return self._json_ext(400, {"error": str(exc)[:120]}, origin)
        if self.path == "/api/af/ack":
            try:
                n = int(self.headers.get("Content-Length", "0"))
                if not 0 < n <= 256:
                    raise ValueError("tamaño inválido")
                b = json.loads(self.rfile.read(n).decode("utf-8"))
                ack_command(b)
                return self._json_ext(200, {"ok": True}, origin)
            except Exception as exc:
                return self._json_ext(400, {"error": str(exc)[:120]}, origin)
        try:
            n = int(self.headers.get("Content-Length", "0"))
            if not 0 < n <= AF_MAX_BYTES:
                raise ValueError("tamaño inválido")
            _raw = json.loads(self.rfile.read(n).decode("utf-8"))
            if not self._is_local():
                _k = str(_raw.get("instance_id", ""))[:8]
                if time.time() - DBG_SEEN.get(_k, 0) > 120:
                    DBG_SEEN[_k] = time.time()
                    _c = _raw.get("chats")
                    log_action(f"DIAG LAN {self.client_address[0]} {_k}: claves={sorted(_raw.keys())} chats_open={_raw.get('chats_open')} chats={'ausente' if _c is None else (len(_c) if isinstance(_c, list) else type(_c).__name__)}")
            clean = af_clean(_raw)
            AF[clean["instance_id"]] = clean
            return self._json_ext(200, {"ok": True}, origin)
        except Exception as exc:
            log_action(f"AF rechazado: payload inválido ({str(exc)[:100]})")
            self._send(400, json.dumps({"error": str(exc)[:120]}), "application/json")

    def do_GET(self):
        if self.path.startswith("/api/af/commands"):
            origin = self._ext_origin()
            # Chrome no envía Origin en los GET de una extensión con permiso de host:
            # en bucle local (sin Origin de página web) se acepta igualmente.
            # Desde la red local la clave del agente (X-Agent-Key) hace de autenticación.
            if not origin and not self.headers.get("Origin") and not self.headers.get("Referer") \
                    and (self._is_local() or self._agent_ok()):
                origin = "*"
            if not origin:
                return self._send(403, '{"error":"origen no permitido"}', "application/json")
            if not self._agent_ok():
                return self._send(401, '{"error":"clave de agente inválida"}', "application/json")
            m = re.search(r"instance_id=([A-Za-z0-9_.:-]{3,64})$", self.path)
            if not m:
                return self._json_ext(400, {"error": "instance_id inválido"}, origin)
            pend = pending_for(m.group(1))
            for c in pend:
                if not DELIVERED.get(c["id"]):
                    DELIVERED[c["id"]] = True
                    log_action(f"AF orden entregada id={c['id']} {c['want']} {c.get('issue', c.get('repo', ''))}")
            return self._json_ext(200, {"commands": pend}, origin)
        if not self._is_local():
            lan_ok = self.path in ("/", "/index.html", "/data.json", "/grafo", "/grafo.html") or self.path.startswith("/api/data")
            if not lan_ok:
                return self._send(403, '{"error":"solo el Mac del dueño"}', "application/json")
        if self.path == "/api/release-preview":
            if not self._same_origin():
                return self._send(403, '{"error":"no autorizado"}', "application/json")
            try:
                return self._send(200, json.dumps({"items": release_preview()}), "application/json")
            except Exception as exc:
                return self._send(500, json.dumps({"error": str(exc)[:200]}), "application/json")
        if self.path == "/api/token":
            if not self._same_origin():
                return self._send(403, '{"error":"no autorizado"}', "application/json")
            return self._send(200, json.dumps({"token": TOKEN}), "application/json")
        if self.path.startswith("/api/data"):
            data, err = get_data(force="refresh=1" in self.path)
            g = grafo_orq.DATA
            now = time.time()
            agents = [dict(a, age_s=round(now - a["seen"])) for a in AF.values()]
            cmds = [{"instance_id": k[0], "chat_id": (None if k[1] == "*" else k[1]), "want": c["want"], "acked": c["acked"], "age_s": round(now - c["at"])} for k, c in CMD.items()]
            assigned = [{"iid": k[0], "chat": k[1], "issue": v.get("issue"), "n": v.get("n", 1), "age_s": round(now - v["at"])}
                        for k, v in ASSIGNED.items() if v.get("issue")]
            extra = {"planner_on": PLANNER["on"], "commands": cmds, "assigned": assigned, "owner_blockers": [{"issue": k, **v} for k, v in OWNER_BLOCKERS.items()], "owner_tasks": OWNER_TASKS["items"], "agents": sorted(agents, key=lambda a: a["label"]), "kill": g.get("kill"), "condor": g.get("condor"), "bus": g.get("bus", [])[-40:], "owner": g.get("owner", [])}
            self._send(200, json.dumps({"data": data, "error": err, "cached_at": _cache["at"], "extra": extra}), "application/json")
        elif self.path.startswith("/data.json"):
            self._send(200, json.dumps(grafo_orq.DATA), "application/json")
        elif self.path in ("/grafo", "/grafo.html"):
            self._send(200, (HERE / "grafo.html").read_text(encoding="utf-8"), "text/html; charset=utf-8")
        elif self.path in ("/", "/index.html"):
            self._send(200, (HERE / "index.html").read_text(encoding="utf-8"), "text/html; charset=utf-8")
        else:
            self._send(404, "no encontrado", "text/plain")


if __name__ == "__main__":
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 8777
    PORT = port
    def keep_warm():
        while True:
            try:
                get_data(force=True)
            except Exception:
                pass
            try:
                collect_owner_tasks()
            except Exception as exc:
                log_action(f"FALLO tareas del dueño -> {str(exc)[:120]}")
            time.sleep(CACHE_SECONDS)

    AUTOMERGE_DONE = set()

    def auto_merge_ready():
        # El dueño activó fusión automática hasta el MVP: los PR listos (CI verde, limpios) se encolan solos.
        # `--auto` respeta las reglas de la rama (ControlBot incluido: main protegida el 2026-10-21 por el dueño).
        data = _cache.get("data") or {}
        for p in (data.get("ready") or []) if isinstance(data, dict) else []:
            repo, num = p.get("repo"), p.get("n")
            if not repo or not num or p.get("auto") or (repo, num) in AUTOMERGE_DONE:
                continue
            AUTOMERGE_DONE.add((repo, num))
            try:
                gh(["pr", "merge", str(num), "-R", f"{OWNER}/{repo}", "--squash", "--auto"], timeout=90)
                log_action(f"OK   fusión automática activada {repo}#{num}")
            except Exception as exc:
                log_action(f"FALLO fusión automática {repo}#{num} -> {str(exc)[:120]}")

    def planner_loop():
        tick_n = 0
        while True:
            time.sleep(30)
            tick_n += 1
            try:
                t0 = time.time()
                done = plan_tick()
                auto_merge_ready()
                review_queue_tick()
                if tick_n % 10 == 0 or done:
                    idle = sum(1 for ag in AF.values() for ch in ag["chats"] if ch["directed"] and ch["state"] == "esperando_envio")
                    log_action(f"OK   planner ciclo {tick_n}: {len(done)} órdenes, {idle} chats ociosos, {round(time.time() - t0, 1)} s")
            except Exception as exc:
                log_action(f"FALLO planner -> {str(exc)[:160]}")

    threading.Thread(target=keep_warm, daemon=True).start()
    threading.Thread(target=planner_loop, daemon=True).start()
    threading.Thread(target=grafo_orq.loop, daemon=True).start()
    srv = ThreadingHTTPServer((HOST, port), Handler)
    print(f"Orquestador local en http://127.0.0.1:{port}  (Ctrl+C para salir)", flush=True)
    print(f"Agentes remotos: usar http://<IP-de-este-Mac>:{port}/api/af con la clave de {HERE / 'agent_key.txt'}", flush=True)
    srv.serve_forever()
