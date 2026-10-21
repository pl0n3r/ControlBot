#!/usr/bin/env python3
"""Orquestador visual (prototipo, solo lectura). Datos reales de GitHub vía `gh`. Sirve http://127.0.0.1:8765"""
import json,subprocess,threading,time,datetime,re,os,sys,urllib.request,http.server,socketserver
REPOS=["Factory","Condor","GrindFlow","brvtal","FactoryRunner","ControlBot","AutoFactory"]
HERE=os.path.dirname(os.path.abspath(__file__))
REFRESH=600
DATA={"ok":False,"generado":None,"fronts":[],"bus":[],"repos":{},"owner":[],"kill":"?","condor":None,"errores":0}
ERR=[0]
def gh(args):
    try:
        p=subprocess.run(["gh","api"]+args,capture_output=True,text=True,timeout=40)
        if p.returncode!=0: ERR[0]+=1; return None
        return json.loads(p.stdout or "null")
    except Exception: ERR[0]+=1; return None
def iso(s): return datetime.datetime.fromisoformat(s.replace("Z","+00:00"))
def clean(t):
    t=re.sub(r"<!--.*?-->","",t or "",flags=re.S)
    t=re.sub(r"\[!\[[^\]]*\]\([^)]*\)\]\([^)]*\)","",t); t=re.sub(r"!\[[^\]]*\]\([^)]*\)","",t)
    t=re.sub(r"\[([^\]]*)\]\([^)]*\)",r"\1",t); t=re.sub(r"\[!(IMPORTANT|NOTE|TIP|WARNING)\]","",t); t=re.sub(r"https?://\S+","",t)
    t=re.sub(r"[*`#>_]","",t); t=re.sub(r"\s+"," ",t).strip(); return t
def short(t,n): return t if len(t)<=n else t[:n-1]+"…"
ESTADOS={"estado: reservado":("reservado",32),"status: reserved":("reservado",32),"estado: en revisión":("en revisión",76),"status: in review":("en revisión",76)}
def compute():
    ERR[0]=0
    now=datetime.datetime.now(datetime.timezone.utc)
    since=(now-datetime.timedelta(hours=2)).strftime("%Y-%m-%dT%H:%M:%SZ")
    fronts=[];bus=[];repos={};owner=[];agentes={}
    for r in REPOS:
        iss=gh([f"repos/pl0n3r/{r}/issues?state=open&per_page=100"]) or []
        com=gh([f"repos/pl0n3r/{r}/issues/comments?since={since}&per_page=100&sort=created&direction=desc"]) or []
        pr=gh([f"repos/pl0n3r/{r}/pulls?state=closed&sort=updated&direction=desc&per_page=8"]) or []
        c={"disponible":0,"reservado":0,"en revisión":0,"bloqueado":0}
        last_agent={}
        for cm in com:
            n=cm["issue_url"].split("/")[-1]; body=cm.get("body") or ""
            if cm["user"]["login"].endswith("[bot]") and cm["user"]["login"]!="github-actions[bot]": continue
            m=re.search(r"Despacho \(([^)]+)\)",body); typ="nota"; txt=clean(body)
            if m: typ="despacho"; last_agent.setdefault(n,m.group(1))
            elif txt[:5]=="STATE": typ="state"
            elif "Decisión humana registrada" in body: typ="decision"
            elif body.strip().startswith("/"): typ="cmd"
            elif "Materializaci" in body[:80]: typ="materializa"
            if not txt: continue
            bus.append({"t":cm["created_at"],"repo":r,"n":n,"tipo":typ,"autor":cm["user"]["login"],"txt":short(txt,170)})
        for i in iss:
            names=[l["name"] for l in i["labels"]]
            if "pull_request" in i: continue
            st=None
            for nme in names:
                if nme in ("estado: disponible","status: available"): c["disponible"]+=1
                if nme in ("estado: bloqueado","status: blocked"): c["bloqueado"]+=1
                if nme in ESTADOS: st=ESTADOS[nme]
            if st:
                c[st[0]]+=1
                fronts.append({"repo":r,"n":i["number"],"titulo":short(re.sub(r"^[A-Za-z]+: ","",i["title"]),34),"estado":st[0],"pct":st[1],"t":i["updated_at"],"agente":last_agent.get(str(i["number"]))})
            if any(nme in ("decisión: dueño","decision: owner") for nme in names):
                owner.append({"repo":r,"n":i["number"],"titulo":short(i["title"],70),"desde":i["created_at"]})
        for p in pr:
            if p.get("merged_at") and (now-iso(p["merged_at"])).total_seconds()<2700:
                fronts.append({"repo":r,"n":p["number"],"titulo":short(re.sub(r"^[a-z]+(\([^)]*\))?: ","",p["title"]),34),"estado":"fusionado","pct":100,"t":p["merged_at"],"agente":None,"pr":True})
                bus.append({"t":p["merged_at"],"repo":r,"n":str(p["number"]),"tipo":"merge","autor":"","txt":"PR fusionado · "+short(p["title"],110)})
        repos[r]=c
    for o in owner:
        bus.append({"t":now.strftime("%Y-%m-%dT%H:%M:%SZ"),"repo":o["repo"],"n":str(o["n"]),"tipo":"humano","autor":"","txt":"esperando decisión del dueño · "+o["titulo"]})
    fronts.sort(key=lambda f:f["t"],reverse=True)
    bus.sort(key=lambda b:b["t"])
    kill="?"
    k=gh(["repos/pl0n3r/Factory/issues/767"])
    if k:
        m=re.search(r'"state"\s*:\s*"(\w+)"',k.get("body") or ""); kill=m.group(1) if m else "UNKNOWN"
    condor=None
    try: condor=json.load(urllib.request.urlopen("https://www.condorapp.com.co/health",timeout=10))
    except Exception: pass
    return {"ok":True,"generado":now.strftime("%Y-%m-%dT%H:%M:%SZ"),"fronts":fronts[:12],"bus":bus[-70:],"repos":repos,"owner":owner,"kill":kill,"condor":condor,"errores":ERR[0]}
def loop():
    global DATA
    while True:
        try: DATA=compute()
        except Exception as e: DATA["errores"]=DATA.get("errores",0)+1; DATA["detalle"]=str(e)
        time.sleep(REFRESH)
class H(http.server.BaseHTTPRequestHandler):
    def log_message(self,*a): pass
    def do_GET(self):
        if self.path.startswith("/data.json"):
            b=json.dumps(DATA).encode(); ct="application/json"
        else:
            b=open(os.path.join(HERE,"grafo.html"),"rb").read(); ct="text/html; charset=utf-8"
        self.send_response(200); self.send_header("Content-Type",ct); self.send_header("Cache-Control","no-store"); self.send_header("Content-Length",str(len(b))); self.end_headers(); self.wfile.write(b)
class S(socketserver.ThreadingMixIn,http.server.HTTPServer): daemon_threads=True; allow_reuse_address=True
if __name__=="__main__":
    threading.Thread(target=loop,daemon=True).start()
    port=int(sys.argv[1]) if len(sys.argv)>1 else 8765
    print("Orquestador visual en http://127.0.0.1:%d"%port,flush=True)
    S(("127.0.0.1",port),H).serve_forever()

