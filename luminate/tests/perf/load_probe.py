"""A bounded, READ-ONLY load probe for the Wellms stack: throughput and latency versus concurrency.

    python -I luminate/tests/perf/load_probe.py [--duration 15] [--levels 1,2,5,10,20,40] [--workloads config,profile,users]

Goes through the real path (Caddy -> php-fpm -> RLS database), rotating across the active dev tenants, with HTTP keep-alive like a
real proxy. It does NOT write anything. The load generator runs on the same machine as the stack, so it takes CPU from it: treat the
numbers as a floor for that hardware, not a benchmark of production hardware. Background services (worker, scheduler) keep running.
"""
import argparse
import http.client
import json
import pathlib
import statistics
import subprocess
import sys
import threading
import time
import urllib.parse

HERE = pathlib.Path(__file__).resolve()
sys.path.insert(0, str(HERE.parents[2] / "tools"))
import context_token as ct  # noqa: E402

E = ct.read_env()
KEY = E["TENANT_CONTEXT_KEY"]
TENANTS = ["t-alpha", "t-beta", "t-delta"]
HOST, PORT = "127.0.0.1", int(E.get("API_PORT", "8080"))
ap = argparse.ArgumentParser()
ap.add_argument("--duration", type=int, default=15)
ap.add_argument("--levels", default="1,2,5,10,20,40")
ap.add_argument("--workloads", default="config,profile,users")
args = ap.parse_args()


def sh(cmd, stdin=None):
    return subprocess.run(cmd, input=stdin, capture_output=True, text=True).stdout.strip()


def one(method, path, tid, bearer=None, body=None):
    c = http.client.HTTPConnection(HOST, PORT, timeout=30)
    h = {"X-Tenant-Context": ct.mint(KEY, tid), "Accept": "application/json"}
    if bearer:
        h["Authorization"] = "Bearer " + bearer
    if body is not None:
        body = json.dumps(body)
        h["Content-Type"] = "application/json"
    c.request(method, path, body=body, headers=h)
    r = c.getresponse()
    data = r.read()
    c.close()
    return r.status, data


tokens = {}
passwords = {tid: sh([sys.executable, "-I", str(HERE.parents[2] / "tools" / "luminate_client.py"), "password", tid]) for tid in TENANTS}


def login_all():
    """User access tokens live only 5 minutes in this deployment, so log in again before every level."""
    for tid in TENANTS:
        s, d = one("POST", "/api/auth/login", tid, body={"email": E["INITIAL_USER_EMAIL"], "password": passwords[tid]})
        if s != 200:
            sys.exit(f"cannot log in to {tid}: HTTP {s}")
        tokens[tid] = json.loads(d)["data"]["token"]


login_all()

WORKLOADS = {
    "config": ("GET /api/config (public, light)", lambda tid: ("GET", "/api/config", tid, None)),
    "profile": ("GET /api/profile/me (auth + DB)", lambda tid: ("GET", "/api/profile/me", tid, tokens[tid])),
    "users": ("GET /api/admin/users?per_page=15 (auth + paged query)", lambda tid: ("GET", "/api/admin/users?per_page=15", tid, tokens[tid])),
}


def run_level(workload, conc, seconds):
    make = WORKLOADS[workload][1]
    lat, codes, stop = [], {}, time.time() + seconds
    lock = threading.Lock()

    def worker(n):
        i = n
        conn = None
        while time.time() < stop:
            tid = TENANTS[i % len(TENANTS)]
            i += 1
            method, path, tid, bearer = make(tid)
            t0 = time.perf_counter()
            try:
                if conn is None:
                    conn = http.client.HTTPConnection(HOST, PORT, timeout=30)
                h = {"X-Tenant-Context": ct.mint(KEY, tid), "Accept": "application/json"}
                if bearer:
                    h["Authorization"] = "Bearer " + bearer
                conn.request(method, path, headers=h)
                r = conn.getresponse()
                r.read()
                code = r.status
                if r.getheader("Connection", "").lower() == "close":
                    conn.close()
                    conn = None
            except Exception:
                code = 0
                try:
                    conn and conn.close()
                except Exception:
                    pass
                conn = None
            dt = (time.perf_counter() - t0) * 1000
            with lock:
                lat.append(dt)
                codes[code] = codes.get(code, 0) + 1

    threads = [threading.Thread(target=worker, args=(n,), daemon=True) for n in range(conc)]
    sample = {}

    def sampler():
        time.sleep(seconds * 0.6)
        out = sh(["docker", "stats", "--no-stream", "--format", "{{.Name}}|{{.CPUPerc}}"])
        sample["cpu"] = {l.split("|")[0].replace("luminate-wellms-", "").replace("-1", ""): l.split("|")[1] for l in out.splitlines() if "luminate-wellms" in l}
        script = "n=0; t=0; for p in $(pgrep -f 'php-fpm: pool'); do r=$(awk '/VmRSS/{print $2}' /proc/$p/status 2>/dev/null); [ -n \"$r\" ] && n=$((n+1)) && t=$((t+r)); done; echo \"$n $t\""
        o = sh(["docker", "exec", "luminate-wellms-api-1", "sh", "-c", script]).split()
        sample["fpm"] = (int(o[0]), int(o[1]) // 1024) if len(o) == 2 else (0, 0)

    s = threading.Thread(target=sampler, daemon=True)
    t0 = time.time()
    s.start()
    for t in threads:
        t.start()
    for t in threads:
        t.join()
    elapsed = time.time() - t0
    s.join(timeout=5)
    lat.sort()
    ok = codes.get(200, 0)
    n = len(lat)
    pct = lambda p: lat[min(n - 1, int(n * p))] if n else 0
    return {
        "conc": conc, "rps": ok / elapsed, "n": n, "ok_pct": 100 * ok / n if n else 0, "codes": codes,
        "p50": pct(0.50), "p95": pct(0.95), "p99": pct(0.99), "max": lat[-1] if n else 0, "sample": sample,
    }


print(f"host={HOST}:{PORT} tenants={TENANTS} duration={args.duration}s/level")
all_results = {}
for w in args.workloads.split(","):
    print(f"\n=== {WORKLOADS[w][0]}")
    print(f"{'conc':>5} {'req/s':>8} {'ok%':>6} {'p50 ms':>8} {'p95 ms':>8} {'p99 ms':>8} {'max ms':>8}  fpm workers/RSS  api CPU  pg CPU  errors")
    run_level(w, 2, 3)  # warm-up (opcache, connections)
    all_results[w] = []
    for conc in [int(x) for x in args.levels.split(",")]:
        login_all()
        r = run_level(w, conc, args.duration)
        all_results[w].append(r)
        cpu = r["sample"].get("cpu", {})
        fpm = r["sample"].get("fpm", (0, 0))
        errs = {k: v for k, v in r["codes"].items() if k != 200}
        print(f"{conc:>5} {r['rps']:>8.1f} {r['ok_pct']:>5.1f}% {r['p50']:>8.0f} {r['p95']:>8.0f} {r['p99']:>8.0f} {r['max']:>8.0f}  "
              f"{fpm[0]:>2} / {fpm[1]:>4} MB   {cpu.get('api', '?'):>7}  {cpu.get('postgres', '?'):>6}  {errs or ''}")
        sys.stdout.flush()
        if r["ok_pct"] < 80:
            print("      (stopping this workload: more than 20% of requests failed)")
            break

out = HERE.parent / "last_load_probe.json"
out.write_text(json.dumps(all_results, indent=1, default=str), encoding="utf-8")
print(f"\nraw results: {out}")
