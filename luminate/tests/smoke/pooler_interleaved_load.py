"""Two tenants hammering the real API concurrently, through the pooler. Point 3 of the acceptance (the load part).

    python -I luminate/tests/smoke/pooler_interleaved_load.py [--seconds 40] [--workers 16] [--label pooler] [--out result.json]

Each tenant has its own marker data (users `ld-<run>-n@<tenant>.test`, a course `load-<run>-<tenant>`). Concurrent workers of BOTH
tenants read, write and cross-probe at once, so their transactions interleave over the pooler's few backends. A cross-tenant observation
of any kind (a foreign marker in a list, a foreign id readable, a token accepted in the wrong tenant, a 5xx) is a failure.
Run it against any stack: with docker-compose.pgbouncer.yml it exercises the pooler; without it, a direct connection (for comparison).
"""
import argparse
import json
import pathlib
import random
import re
import statistics
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.request
import uuid

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[2] / "tools"))
import context_token as ct  # noqa: E402

ap = argparse.ArgumentParser()
ap.add_argument("--seconds", type=int, default=40, help="minimum run time")
ap.add_argument("--min-cross", type=int, default=60, help="keep going until this many cross-tenant reads (and 25 of every other operation) have run")
ap.add_argument("--max-seconds", type=int, default=900, help="hard cap")
ap.add_argument("--workers", type=int, default=16)
ap.add_argument("--label", default="run")
ap.add_argument("--out")
args = ap.parse_args()

E = ct.read_env()
BASE = f"http://{E['LAN_HOST']}:{E.get('API_PORT', '8080')}"
KEY = E["TENANT_CONTEXT_KEY"]
TENANTS = ("t-alpha", "t-beta")
RUN = uuid.uuid4().hex[:6]
PW = "Fake-Passw0rd!x"
results = []
LOCK = threading.Lock()
violations = []
lat = {}          # op -> [seconds]
status_count = {}
created = {t: [] for t in TENANTS}      # (id, email) created by this run, per tenant (e-mail domain == tenant)
shared = []                             # e-mails deliberately created in BOTH tenants (domain shared.test)


def check(name, ok, detail=""):
    results.append(ok)
    print(f"{'PASS' if ok else 'FAIL'}  {name}" + (f"  [{detail}]" if detail and not ok else ""))


def call(method, path, tid, bearer=None, body=None):
    h = {"Accept": "application/json", "X-Tenant-Context": ct.mint(KEY, tid)}
    if bearer:
        h["Authorization"] = "Bearer " + bearer
    data = json.dumps(body).encode() if body is not None else None
    if data:
        h["Content-Type"] = "application/json"
    req = urllib.request.Request(BASE + path, data=data, method=method, headers=h)
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            raw = r.read()
            return r.status, (json.loads(raw) if raw else None)
    except urllib.error.HTTPError as e:
        raw = e.read().decode()
        try:
            return e.code, json.loads(raw)
        except Exception:
            return e.code, raw[:200]
    except Exception as e:  # connection reset, timeout
        return 0, str(e)[:200]


def other(t):
    return TENANTS[1] if t == TENANTS[0] else TENANTS[0]


def bad(kind, **detail):
    with LOCK:
        violations.append({"kind": kind, **detail})


def timed(op, fn):
    t0 = time.perf_counter()
    status, body = fn()
    dt = time.perf_counter() - t0
    with LOCK:
        lat.setdefault(op, []).append(dt)
        status_count[status] = status_count.get(status, 0) + 1
    if status == 0 or status >= 500:
        bad("server error or no answer", op=op, status=status, body=str(body)[:120])
    return status, body


def wait_ready(seconds=120):
    """Right after a container is recreated the API answers 502 for a while. Wait for the first real answer; never retries later requests."""
    t0 = time.time()
    while time.time() - t0 < seconds:
        s, r = call("POST", "/api/auth/login", TENANTS[0], body={"email": E["INITIAL_USER_EMAIL"], "password": E["INITIAL_USER_PASSWORD"]})
        if s == 200 and isinstance(r, dict):
            if time.time() - t0 > 2:
                print(f"INFO  waited {time.time() - t0:.0f}s for the API to start serving")
            return
        time.sleep(1)
    print("FAIL  the API never became ready; last answer:", s, str(r)[:150])
    sys.exit(1)


wait_ready()

# ---- setup -----------------------------------------------------------------------------------------------------------------------------
admin, student, course = {}, {}, {}
for t in TENANTS:
    s, r = call("POST", "/api/auth/login", t, body={"email": E["INITIAL_USER_EMAIL"], "password": E["INITIAL_USER_PASSWORD"]})
    admin[t] = r["data"]["token"]
    s, r = call("POST", "/api/admin/courses", t, admin[t], {"title": f"load-{RUN}-{t}", "status": "published"})
    course[t] = r["data"]["id"]
    email = f"ld-{RUN}-student@{t}.test"
    s, r = call("POST", "/api/admin/users", t, admin[t], {"first_name": "S", "last_name": t, "email": email, "password": PW, "return_url": "http://localhost/", "verified": True})
    created[t].append((r["data"]["id"], email))
    s, r = call("POST", "/api/auth/login", t, body={"email": email, "password": PW})
    student[t] = r["data"]["token"]
    for i in range(2):
        e2 = f"ld-{RUN}-seed{i}@{t}.test"
        s, r = call("POST", "/api/admin/users", t, admin[t], {"first_name": "Seed", "last_name": str(i), "email": e2, "password": PW, "return_url": "http://localhost/", "verified": True})
        created[t].append((r["data"]["id"], e2))
check("setup: two tenants each have an admin, a course, a student and seed users", all(len(created[t]) == 3 for t in TENANTS) and all(course.values()))


# ---- the workload -----------------------------------------------------------------------------------------------------------------------
def op_list_users(t):
    s, r = timed("list users", lambda: call("GET", f"/api/admin/users?per_page=50&page={random.randint(1, 3)}", t, admin[t]))
    if s == 200:
        text = json.dumps(r)
        o = other(t)
        # markers of THIS run only: earlier runs left their own users behind
        if f"@{o}.test" in text and f"ld-{RUN}-" in text and re.search(rf"ld-{RUN}-[A-Za-z0-9]+@{re.escape(o)}\.test", text) or f"load-{RUN}-{o}" in text:
            bad("foreign tenant's marker in my user list", tenant=t, looking_at=o)
        ids = [u["id"] for u in r["data"]]
        if len(ids) != len(set(ids)):
            bad("duplicate ids in one list page", tenant=t)
    else:
        bad("list users failed", tenant=t, status=s, body=str(r)[:120])


def op_create_user(t):
    n = uuid.uuid4().hex[:8]
    email = f"ld-{RUN}-{n}@{t}.test"
    s, r = timed("create user", lambda: call("POST", "/api/admin/users", t, admin[t], {"first_name": "L", "last_name": n, "email": email, "password": PW, "return_url": "http://localhost/", "verified": True}))
    if s in (200, 201):
        uid = r["data"]["id"]
        with LOCK:
            created[t].append((uid, email))
        s2, r2 = timed("read own created user", lambda: call("GET", f"/api/admin/users/{uid}", t, admin[t]))
        if s2 != 200 or r2["data"]["email"] != email:
            bad("user I just created is not readable back in my own tenant", tenant=t, status=s2)
        # the SAME e-mail must be creatable in BOTH tenants (composite unique key). It uses a shared domain on purpose, so it can never be
        # mistaken for a tenant-marked user leaking across.
        dup = f"dup-{RUN}-{n}@shared.test"
        o = other(t)
        sa, ra = timed("create a shared e-mail (own tenant)", lambda: call("POST", "/api/admin/users", t, admin[t], {"first_name": "D", "last_name": n, "email": dup, "password": PW, "return_url": "http://localhost/", "verified": True}))
        sb, rb = timed("create the SAME e-mail in the other tenant", lambda: call("POST", "/api/admin/users", o, admin[o], {"first_name": "D", "last_name": n, "email": dup, "password": PW, "return_url": "http://localhost/", "verified": True}))
        if sa in (200, 201) and sb in (200, 201):
            with LOCK:
                shared.append(dup)
        else:
            bad("the same e-mail could not be created in both tenants (a global unique key?)", tenant=t, status=(sa, sb), body=str((ra, rb))[:160])
    else:
        bad("create user failed", tenant=t, status=s, body=str(r)[:160])


def op_cross_read(t):
    o = other(t)
    with LOCK:
        pool = list(created[o])
    uid, email = random.choice(pool)
    s, r = timed("cross-tenant read by id", lambda: call("GET", f"/api/admin/users/{uid}", t, admin[t]))
    if s == 200 and isinstance(r, dict) and r.get("data", {}).get("email") == email and not email.endswith(f"@{t}.test"):
        bad("READ ANOTHER TENANT'S USER BY ID", tenant=t, victim=uid)
    if s == 200 and email in json.dumps(r) and not email.endswith(f"@{t}.test"):
        bad("another tenant's e-mail came back in a response", tenant=t, victim=uid)


def op_token_binding(t):
    o = other(t)
    s, r = timed("my token in the other tenant's context", lambda: call("GET", "/api/profile/me", o, admin[t]))
    if s != 403:
        bad("a token was NOT refused (403) in the wrong tenant's context", tenant=t, used_in=o, status=s)


def op_courses(t):
    o = other(t)
    s, r = timed("list courses", lambda: call("GET", "/api/admin/courses?per_page=50", t, admin[t]))
    if s == 200:
        text = json.dumps(r)
        if f"load-{RUN}-{o}" in text:
            bad("foreign tenant's course in my course list", tenant=t, looking_at=o)
        if f"load-{RUN}-{t}" not in text and f"load-{RUN}-{t}" not in json.dumps(call("GET", "/api/admin/courses?per_page=50&search=load", t, admin[t])[1]):
            pass  # my own course may be on another page; absence is not a leak
    else:
        bad("list courses failed", tenant=t, status=s)


def op_profile(t):
    s, r = timed("student profile", lambda: call("GET", "/api/profile/me", t, student[t]))
    if s != 200 or not r["data"]["email"].endswith(f"@{t}.test"):
        bad("student profile is not this tenant's", tenant=t, status=s, got=str(r)[:100])


OPS = [(op_list_users, 30), (op_create_user, 12), (op_cross_read, 18), (op_token_binding, 12), (op_courses, 14), (op_profile, 14)]
WEIGHTED = [f for f, w in OPS for _ in range(w)]
started = time.time()
deadline = started + args.seconds
hard_deadline = started + args.max_seconds
done = {"n": 0}
REQUIRED = {"cross-tenant read by id": args.min_cross, "my token in the other tenant's context": 25, "list users": 25, "create user": 25, "list courses": 25, "student profile": 25}


def enough():
    """Coverage is count-driven, not time-driven: a slow system must not turn 'no leak seen' into 'never looked'."""
    with LOCK:
        return all(len(lat.get(op, [])) >= n for op, n in REQUIRED.items())


def worker(i):
    t = TENANTS[i % 2]   # half the workers per tenant: the two tenants are always interleaved
    rnd = random.Random(i)
    while time.time() < hard_deadline and (time.time() < deadline or not enough()):
        rnd.choice(WEIGHTED)(t)
        with LOCK:
            done["n"] += 1


# pooler sampling (only meaningful when the pooler is present)
pool_samples = {"servers": 0, "busy": 0, "waiting": 0}
stop = threading.Event()
have_pooler = subprocess.run(["docker", "inspect", "-f", "{{.State.Running}}", "luminate-wellms-pgbouncer-1"], capture_output=True, text=True).stdout.strip() == "true"
xact0 = None
if have_pooler:
    from pooler_admin import admin as pgb_admin, pool, stats  # noqa: E402   (NOT `admin`: that name holds the per-tenant admin tokens)
    POOL_SIZE = int({r["key"]: r["value"] for r in pgb_admin("SHOW CONFIG")[1]}.get("default_pool_size", "2"))
    xact0 = int(stats().get("total_xact_count", 0))

    def sample():
        while not stop.is_set():
            try:
                p = pool()
                pool_samples["servers"] = max(pool_samples["servers"], int(p["sv_active"]) + int(p["sv_idle"]) + int(p["sv_used"]))
                pool_samples["busy"] = max(pool_samples["busy"], int(p["cl_active"]) + int(p["cl_waiting"]))
                pool_samples["waiting"] = max(pool_samples["waiting"], int(p["cl_waiting"]))
            except Exception:
                pass
            time.sleep(0.25)
    threading.Thread(target=sample, daemon=True).start()

print(f"--- load: {args.workers} workers ({args.workers // 2} per tenant), at least {args.seconds}s and until {args.min_cross} cross-tenant reads (cap {args.max_seconds}s), label={args.label}, pooler={'yes' if have_pooler else 'no'}")
t0 = time.time()
threads = [threading.Thread(target=worker, args=(i,)) for i in range(args.workers)]
[t.start() for t in threads]
[t.join() for t in threads]
elapsed = time.time() - t0
stop.set()
requests = sum(len(v) for v in lat.values())
print(f"INFO  {requests} requests in {elapsed:.1f}s = {requests / elapsed:.0f} req/s; statuses {dict(sorted(status_count.items()))}")
if have_pooler:
    xact = int(stats().get("total_xact_count", 0)) - xact0
    print(f"INFO  pooler: {xact} transactions carried; max server connections {pool_samples['servers']}; max clients active-or-waiting {pool_samples['busy']} (max waiting {pool_samples['waiting']})")

check(f"ZERO cross-tenant observations or server errors in {requests} concurrent requests", not violations, json.dumps(violations[:4])[:700])
check("the workload really covered every operation", all(len(lat.get(k, [])) >= n for k, n in REQUIRED.items()), str({k: len(v) for k, v in lat.items()}))
check(f"cross-tenant reads WERE attempted at least {args.min_cross} times (so 'no leak' is not 'never looked')", len(lat.get("cross-tenant read by id", [])) >= args.min_cross and len(lat.get("my token in the other tenant's context", [])) >= 25)
if have_pooler:
    check("the pool never exceeded its size while serving all of that", pool_samples["servers"] <= POOL_SIZE, str(pool_samples))
    check("non-vacuous: many clients were active or waiting for a backend at once", pool_samples["busy"] >= 3, str(pool_samples))

# ---- the data itself, read by the OWNER role (which sees every tenant): each row sits in exactly the tenant its e-mail says ----------------
def owner(sql):
    p = subprocess.run(["docker", "exec", "-i", "-e", f"PGPASSWORD={E['DB_OWNER_PASSWORD']}", "luminate-wellms-postgres-1", "psql", "-h", "127.0.0.1", "-U", E["DB_OWNER_USER"],
                        "-d", E.get("DB_NAME", "wellms"), "-At", "-q"], input=sql, capture_output=True, text=True)
    return p.stdout.strip()


wrong = owner(f"select count(*) from users where email like 'ld-{RUN}-%@t-alpha.test' and tenant_id <> 't-alpha' or email like 'ld-{RUN}-%@t-beta.test' and tenant_id <> 't-beta'")
check("every user this run created sits in the tenant its e-mail names (owner view of the whole table)", wrong == "0", wrong)
for t in TENANTS:
    n = owner(f"select count(*) from users where email like 'dup-{RUN}-%@shared.test' and tenant_id = '{t}'")
    check(f"{t}: each of the {len(shared)} shared e-mails exists exactly once in this tenant (the composite unique key, under load)", n == str(len(shared)), f"db={n} api={len(shared)}")
    n = owner(f"select count(*) from users where email like 'ld-{RUN}-%@{t}.test' and tenant_id = '{t}'")
    check(f"{t}: the database holds exactly the {len(created[t])} users the API reported creating (none lost, none duplicated)", n == str(len(created[t])), f"db={n} api={len(created[t])}")

ordered = {k: sorted(v) for k, v in lat.items()}
pct = lambda xs, p: xs[min(len(xs) - 1, int(len(xs) * p))] * 1000
summary = {"label": args.label, "pooler": have_pooler, "seconds": round(elapsed, 1), "workers": args.workers, "requests": requests, "rps": round(requests / elapsed, 1),
           "ops": {k: {"n": len(v), "p50_ms": round(pct(v, .5), 1), "p95_ms": round(pct(v, .95), 1), "mean_ms": round(statistics.mean(v) * 1000, 1)} for k, v in ordered.items()},
           "violations": len(violations)}
for k, v in summary["ops"].items():
    print(f"INFO  {k:42} n={v['n']:5}  p50={v['p50_ms']:7.1f} ms  p95={v['p95_ms']:7.1f} ms")
if args.out:
    pathlib.Path(args.out).write_text(json.dumps(summary, indent=1), encoding="utf-8")

print(f"\n{sum(results)}/{len(results)} passed")
sys.exit(0 if all(results) else 1)
