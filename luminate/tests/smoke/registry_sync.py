"""Registry replica sync (contract §A.2): push on change + reconcile pull, against the live stack.

    python -I luminate/tests/smoke/registry_sync.py

Luminate's side is played by this script (signed pushes) and by `registry-stub` (the signed listing Wellms pulls). Proves:
  A. the push endpoint authenticates (HMAC over timestamp+method+path+body, a key that is NOT the tenant-context key) and refuses
     everything else, and the tenant-context exemption it needs does not leak to any other route;
  B. updates only move forward (rev), including the explicit-removal tombstone;
  C. "add a tenant = a row": a pushed tenant is provisioned by the scheduler, becomes servable, and every consumer (scheduler, queue
     workers) follows later status changes;
  D. the reconcile pull converges, and refuses every untrustworthy answer without touching the replica;
  E. status/health, key separation, and the request path never reading the registry.
Stops the compose `scheduler` while it runs (so its own ticks do not interfere) and restores it. Needs the dev stack.
"""
import hashlib
import hmac
import http.client
import json
import os
import pathlib
import subprocess
import sys
import time
import urllib.error
import urllib.request
import uuid

HERE = pathlib.Path(__file__).resolve()
sys.path.insert(0, str(HERE.parents[2] / "tools"))
import context_token as ct  # noqa: E402

INFRA = HERE.parents[2] / "infra"
E = ct.read_env()
HOST, PORT = os.environ.get("LUMINATE_TEST_HOST", E["LAN_HOST"]), E.get("API_PORT", "8080")  # the LAN IP changes with the network
BASE = f"http://{HOST}:{PORT}"
KEY, CTX_KEY = E["LUMINATE_REGISTRY_KEY"], E["TENANT_CONTEXT_KEY"]
APP, DB = E["DB_APP_USER"], E.get("DB_NAME", "wellms")
API, WORKER = "luminate-wellms-api-1", "luminate-wellms-worker-1"
RUN = uuid.uuid4().hex[:6]
REV0 = int(time.time())          # revs only ever grow across runs of this suite
results = []
DEV = {"t-alpha": "active", "t-beta": "active", "t-delta": "active", "t-gamma": "suspended"}


def check(name, ok, detail=""):
    results.append(bool(ok))
    print(f"{'PASS' if ok else 'FAIL'}  {name}" + (f"  [{detail}]" if detail and not ok else ""))


def sh(args, stdin=None, cwd=None, timeout=300):
    p = subprocess.run(args, input=stdin, capture_output=True, text=True, cwd=cwd, timeout=timeout)
    return p.returncode, p.stdout.strip(), p.stderr.strip()


def psql(sql, guc=None, owner=False):
    user, pw = (E["DB_OWNER_USER"], E["DB_OWNER_PASSWORD"]) if owner else (APP, E["DB_APP_PASSWORD"])
    pre = f"select set_config('app.tenant_id','{guc}',false);" if guc else ""
    rc, out, err = sh(["docker", "exec", "-i", "-e", f"PGPASSWORD={pw}", "luminate-wellms-postgres-1", "psql", "-h", "127.0.0.1", "-U", user,
                       "-d", DB, "-At", "-q", "-v", "ON_ERROR_STOP=1"], stdin=pre + sql)
    if rc != 0:
        raise RuntimeError(err)
    return (out.splitlines() or [""])[-1]


def redis(*a):
    return sh(["docker", "exec", "luminate-wellms-redis-1", "redis-cli", "-a", E["REDIS_PASSWORD"], "--no-auth-warning", *a])[1]


def artisan(*a, env=None):
    args = ["docker", "exec", "-u", "www-data"]
    for k, v in (env or {}).items():
        args += ["-e", f"{k}={v}"]
    return sh(args + [API, "php", "artisan", *a])


def events_of(out):
    return [json.loads(l) for l in out.splitlines() if l.startswith("{")]


# ---- Luminate's side: signing and calling ---------------------------------------------------------------------------------
def sign(key, ts, method, path, body):
    return "sha256=" + hmac.new(key.encode(), f"{ts}.{method}.{path}.{hashlib.sha256(body).hexdigest()}".encode(), hashlib.sha256).hexdigest()


def call(method, path, body=b"", key=KEY, ts=None, ts_off=0, sign_path=None, sign_method=None, sign_body=None, headers=None, signed=True):
    ts = str(int(time.time()) + ts_off) if ts is None else str(ts)  # offset applied when SENT: calls take seconds
    h = {"Accept": "application/json"}
    if signed:
        h["X-Luminate-Timestamp"] = ts
        h["X-Luminate-Signature"] = sign(key, ts, sign_method or method, sign_path or path, body if sign_body is None else sign_body)
    h.update(headers or {})
    if body:
        h["Content-Type"] = "application/json"
    req = urllib.request.Request(BASE + path, data=body or None, method=method, headers=h)
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


def entry(status="active", rev=0, tid=None, **placement):
    p = {"mode": "pooled", "connection": "shard-1", "bucket": "wellms", "cache_prefix": tid or "x"}
    p.update(placement)
    return {"status": status, "rev": rev, "placement": p}


def push(tid, status="active", rev=0, **kw):
    placement = {k: kw.pop(k) for k in list(kw) if k in ("mode", "connection", "bucket", "cache_prefix")}
    e = entry(status, rev, tid, **placement)
    return call("PUT", f"/api/luminate/registry/tenants/{tid}", json.dumps(e).encode(), **kw)


def show(tid):
    return call("GET", f"/api/luminate/registry/tenants/{tid}")


def code(r):
    return r[1].get("code") if isinstance(r[1], dict) else None


# ---- the stub: Luminate's registry listing --------------------------------------------------------------------------------
def stub_ctl(**data):
    req = urllib.request.Request("http://127.0.0.1:8098/_control", data=json.dumps(data).encode(), method="POST", headers={"Content-Type": "application/json"})
    urllib.request.urlopen(req, timeout=10).read()


def stub_seen():
    return json.load(urllib.request.urlopen("http://127.0.0.1:8098/_control", timeout=10))["seen"]


def listing(extra=None, drop=()):
    t = {tid: {"status": s, "rev": 1, "placement": {"mode": "pooled", "connection": "shard-1", "bucket": "wellms", "cache_prefix": tid}} for tid, s in DEV.items()}
    for d in drop:
        t.pop(d, None)
    for tid, (s, rev) in (extra or {}).items():
        t[tid] = {"status": s, "rev": rev, "placement": {"mode": "pooled", "connection": "shard-1", "bucket": "wellms", "cache_prefix": tid}}
    return t


def reconcile(*flags):
    rc, out, err = artisan("luminate:registry:reconcile", *flags)
    ev = (events_of(out) or [{}])[-1]
    return rc, ev, out + err


def registry_rows():
    return psql("select coalesce(string_agg(tenant_id||':'||status||':'||rev||':'||source||':'||updated_at, ',' order by tenant_id), '') from luminate_tenant_registry")


def row(tid):
    r = psql(f"select status||'|'||rev||'|'||source||'|'||coalesce(provisioned_at::text,'') from luminate_tenant_registry where tenant_id='{tid}'")
    return r.split("|") if r else None


def tick():
    rc, out, err = artisan("luminate:schedule:work", "--once")
    return rc, events_of(out), out + "\n" + err


_notify = redis("--scan", "--pattern", "*queues:default:notify").splitlines()
KEY_PREFIX = _notify[0][: -len("queues:default:notify")] if _notify else ""
PROBE = KEY_PREFIX + "luminate:probe"


def probes(kind, tid=None):
    out = []
    for line in redis("lrange", PROBE, "0", "-1").splitlines():
        if line.startswith("{"):
            r = json.loads(line)
            if r.get("kind") == kind and (tid is None or r.get("tenant") == tid):
                out.append(r)
    return out


def admin_login(tid):
    tok = ct.mint(CTX_KEY, tid)
    req = urllib.request.Request(BASE + "/api/auth/login", data=json.dumps({"email": E["INITIAL_USER_EMAIL"], "password": E["INITIAL_USER_PASSWORD"]}).encode(),
                                 method="POST", headers={"Content-Type": "application/json", "Accept": "application/json", "X-Tenant-Context": tok})
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            return r.status
    except urllib.error.HTTPError as e:
        return e.code


def raw_get(path):
    c = http.client.HTTPConnection(HOST, int(PORT), timeout=30)
    c.request("GET", path, headers={"Accept": "application/json"})
    r = c.getresponse()
    body = r.read().decode(errors="replace")
    c.close()
    return r.status, body


LIFE = f"t-reg-{RUN}"      # the tenant whose whole life this run drives
ORD = "t-reg-ord"          # the ordering tenant (fixed id; revs grow from the clock)
PULL = f"t-reg-pull-{RUN}"  # the tenant that arrives by pull (new each run: provisioning happens once per tenant)
r = REV0

try:
    sh(["docker", "compose", "stop", "scheduler"], cwd=INFRA)
    stub_ctl(reset=True)
    redis("del", PROBE)

    # ==================================================================================================================
    print("--- A. The dev stack booted on the registry replica (seeded from the snapshot, converged by the pull)")
    s, idx = call("GET", "/api/luminate/registry/tenants")
    got = {t["tenant_id"]: t for t in (idx or {}).get("tenants", [])} if s == 200 else {}
    check("a signed index lists the four dev tenants with their dev statuses", all(got.get(t, {}).get("status") == st for t, st in DEV.items()), str({t: v.get("status") for t, v in got.items()}))
    check("the dev tenants were provisioned (the provision job recorded it)", all(got.get(t, {}).get("provisioned") for t in ("t-alpha", "t-beta", "t-delta")), str({t: got.get(t, {}).get("provisioned") for t in DEV}))
    check("the registry tables are global by design: exempt from tenancy, no RLS, no policy (workers read them with no tenant active)",
          psql("select count(*) from luminate_tenancy_exempt where table_name in ('luminate_tenant_registry','luminate_registry_meta')") == "2"
          and psql("select count(*) from pg_class where relname in ('luminate_tenant_registry','luminate_registry_meta') and relrowsecurity") == "0"
          and psql("select count(*) from pg_policies where tablename in ('luminate_tenant_registry','luminate_registry_meta')") == "0")

    # ==================================================================================================================
    print("--- B. The push endpoint authenticates, and the tenant-context exemption does not leak")
    probe_tid = f"t-reg-x-{RUN}"
    path = f"/api/luminate/registry/tenants/{probe_tid}"
    body = json.dumps(entry("active", r, probe_tid)).encode()
    bad = [
        ("no signature headers at all", dict(signed=False), 401, "registry_signature_missing"),
        ("the wrong key", dict(key="not-the-key"), 401, "registry_signature_invalid"),
        ("the TENANT-CONTEXT key (the two keys must be independent)", dict(key=CTX_KEY), 401, "registry_signature_invalid"),
        ("a timestamp 310 s in the past", dict(ts_off=-310), 401, "registry_timestamp_expired"),
        ("a timestamp 310 s in the future", dict(ts_off=310), 401, "registry_timestamp_expired"),
        ("a signature made for ANOTHER tenant's path (replay)", dict(sign_path="/api/luminate/registry/tenants/t-other"), 401, "registry_signature_invalid"),
        ("a signature made for a different body (tampered)", dict(sign_body=b"{}"), 401, "registry_signature_invalid"),
        ("a GET signature replayed as a PUT", dict(sign_method="GET"), 401, "registry_signature_invalid"),
        ("a valid tenant-context token instead of a signature", dict(signed=False, headers={"X-Tenant-Context": ct.mint(CTX_KEY, "t-alpha")}), 401, "registry_signature_missing"),
    ]
    for label, kw, want_s, want_c in bad:
        res = call("PUT", path, body, **kw)
        check(f"push refused: {label}", res[0] == want_s and code(res) == want_c, f"{res[0]} {str(res[1])[:100]}")
    check("none of those refused pushes created anything", row(probe_tid) is None)
    res = call("GET", path, signed=False)
    check("an unsigned read of an UNKNOWN tenant answers 401, not 404 (no existence oracle)", res[0] == 401, str(res[0]))

    invalid = [
        ("an unknown status", entry("deleted", r, probe_tid)),
        ("a negative rev", entry("active", -1, probe_tid)),
        ("a string rev", {**entry("active", r, probe_tid), "rev": "7"}),
        ("a DSN smuggled in as the connection", entry("active", r, probe_tid, connection="pgsql://u:p@h/db")),
        ("a path-traversal bucket", entry("active", r, probe_tid, bucket="a/../b")),
        ("a body tenant_id that contradicts the path", {**entry("active", r, probe_tid), "tenant_id": "t-someone-else"}),
        ("no placement", {"status": "active", "rev": r}),
    ]
    for label, e in invalid:
        res = call("PUT", path, json.dumps(e).encode())
        check(f"422 with a stable code: {label}", res[0] == 422 and code(res) == "registry_payload_invalid", f"{res[0]} {str(res[1])[:120]}")
    res = call("PUT", path, b"{not json")
    check("422: a body that is not JSON", res[0] == 422, str(res[0]))
    res = call("PUT", "/api/luminate/registry/tenants/bad%20id", json.dumps(entry("active", r)).encode())
    check("422/404: a malformed tenant id in the path", res[0] in (404, 422), str(res[0]))
    check("none of the invalid pushes created anything either", row(probe_tid) is None)

    s1, b1 = call("GET", "/api/admin/users", signed=False)
    check("the exemption is not a hole: an ordinary route with no tenant context is still refused (401)", s1 == 401 and "tenant_context" in str(b1), f"{s1} {str(b1)[:100]}")
    s2, b2 = call("PUT", "/api/luminate/registry/tenants", b"{}")
    check("PUT on the collection (no tenant id) is not a route", s2 in (404, 405), str(s2))
    s3, b3 = raw_get("/api/luminate/registry/tenants/..%2f..%2fadmin%2fusers")
    check("a traversal out of the registry prefix does not reach another route", s3 in (400, 401, 404) and '"data"' not in b3, f"{s3} {b3[:100]}")
    s4, b4 = call("DELETE", path, signed=False)
    check("only GET and PUT exist on the registry (DELETE is refused)", s4 in (401, 404, 405), str(s4))

    # ==================================================================================================================
    print("--- C. Updates only move forward")
    r = REV0 + 100
    res = push(ORD, "active", r)
    check("first push of a tenant is applied", res[0] == 200 and res[1].get("outcome") == "applied" and res[1].get("rev") == r, str(res))
    res = push(ORD, "active", r)
    check("the same push again is 'unchanged' (idempotent)", res[0] == 200 and res[1].get("outcome") == "unchanged", str(res))
    res = push(ORD, "suspended", r + 1)
    check("a newer rev is applied (suspended)", res[1].get("outcome") == "applied" and res[1].get("status") == "suspended", str(res))
    res = push(ORD, "active", r)
    check("a LATE older 'active' is stale and does not undo the suspension", res[0] == 200 and res[1].get("outcome") == "stale" and res[1].get("status") == "suspended", str(res))
    res = push(ORD, "active", r + 1)
    check("the same rev with different content is a conflict (409), refused", res[0] == 409 and res[1].get("outcome") == "conflict", str(res))
    check("...and neither changed the stored state", row(ORD)[:2] == ["suspended", str(r + 1)], str(row(ORD)))
    res = push(ORD, "removed", r + 2)
    check("an explicit removal is applied and is a tombstone, not a delete", res[1].get("outcome") == "applied" and row(ORD) is not None and row(ORD)[0] == "removed", str(res))
    res = push(ORD, "active", r + 2)
    check("a removed tenant is NOT revived by the same rev (an explicit removal needs a strictly newer one)", res[1].get("outcome") == "stale" and row(ORD)[0] == "removed", str(res))
    res = push(ORD, "active", r + 3)
    check("a strictly newer rev brings it back", res[1].get("outcome") == "applied" and row(ORD)[0] == "active", str(res))
    s, sh1 = show(ORD)
    check("a signed read shows status, rev, placement and the provisioned flag", s == 200 and sh1["status"] == "active" and sh1["rev"] == r + 3 and sh1["provisioned"] is False and sh1["placement"]["connection"] == "shard-1", str(sh1))
    check("pushes were recorded with their source", row(ORD)[2] == "push", str(row(ORD)))
    res = push(ORD, "active", r + 4, connection="shard-9")
    check("a placement this deployment cannot serve is stored (the registry is a mirror)...", res[1].get("outcome") == "applied", str(res))
    rc, ev, tk = tick()
    skip = {e["tenant"]: e["reason"] for e in ev if e["event"] == "scheduler.skip"}
    check("...but no consumer will serve it: the scheduler skips it with the reason", skip.get(ORD) not in (None, "status active") and ORD not in {e["tenant"] for e in ev if e["event"] == "scheduler.ran"}, str(skip))
    push(ORD, "suspended", r + 5)

    # ==================================================================================================================
    print("--- D. Add a tenant = a row: provisioning, serving, and every consumer following status changes")
    r = REV0 + 200
    res = push(LIFE, "provisioning", r)
    check("a brand-new tenant is pushed as 'provisioning'", res[0] == 200 and res[1].get("outcome") == "applied", str(res))
    s, v = show(LIFE)
    check("Luminate can see it is NOT provisioned yet", s == 200 and v["provisioned"] is False, str(v))
    rc, ev, tk = tick()
    check("the next scheduler tick provisions it, with no operator step", {"event": "scheduler.provisioned", "tenant": LIFE} in ev, str([e for e in ev if "provision" in e.get("event", "")]))
    s, v = show(LIFE)
    check("and the registry now says provisioned (Luminate can wait on this before flipping it active)", v.get("provisioned") is True and v.get("provisioned_at"), str(v))
    roles, admins = psql("select count(*) from roles", guc=LIFE), psql("select count(*) from users", guc=LIFE)
    check("its baseline data exists, in ITS tenant only (roles, a first admin)", int(roles) > 0 and int(admins) >= 1, f"roles={roles} users={admins}")
    check("a 'provisioning' tenant is not given scheduled work", LIFE not in {e["tenant"] for e in ev if e["event"] == "scheduler.ran"}
          and {e["tenant"]: e["reason"] for e in ev if e["event"] == "scheduler.skip"}.get(LIFE) == "status provisioning", str([e for e in ev if e.get("tenant") == LIFE]))
    rc2, ev2, tk2 = tick()
    check("provisioning is not repeated on later ticks", not [e for e in ev2 if e["event"].startswith("scheduler.provision") and e["tenant"] == LIFE])

    r += 1
    push(LIFE, "active", r)
    rc, ev, tk = tick()
    check("once pushed active, the scheduler runs it", {"event": "scheduler.ran", "tenant": LIFE} == {k: e[k] for e in ev if e.get("tenant") == LIFE and e["event"] == "scheduler.ran" for k in ("event", "tenant")}, str([e for e in ev if e.get("tenant") == LIFE]))
    runs = probes("schedule-closure", LIFE)
    check("...and the scheduled closure really ran as that tenant", len(runs) == 1 and runs[0]["guc"] == LIFE and runs[0]["cache_prefix"] == f"{LIFE}:", str(runs))
    check("the new tenant can serve requests: the admin logs in under its context token", admin_login(LIFE) == 200)

    # queue workers follow the registry too (replica cache is 2 s)
    lbl = f"{RUN}-life-active"
    artisan("luminate:tenancy:probe-job", lbl, LIFE)
    for _ in range(40):
        if any(p["label"] == lbl for p in [json.loads(x) for x in redis("lrange", PROBE, "0", "-1").splitlines() if x.startswith("{")]):
            break
        time.sleep(1)
    seen = [json.loads(x) for x in redis("lrange", PROBE, "0", "-1").splitlines() if x.startswith("{")]
    ran = [p for p in seen if p["label"] == lbl]
    check("a job for the active new tenant runs, as that tenant", len(ran) == 1 and ran[0]["tenant"] == LIFE and ran[0]["guc"] == LIFE, str(ran))

    r += 1
    push(LIFE, "suspended", r)
    time.sleep(4)  # > the replica cache TTL
    lbl2 = f"{RUN}-life-suspended"
    artisan("luminate:tenancy:probe-job", lbl2, LIFE, "--forge=signed")
    for _ in range(40):
        if int(psql(f"select count(*) from failed_jobs where payload like '%{lbl2}%'") or 0) >= 1:
            break
        time.sleep(1)
    dead = psql(f"select count(*) from failed_jobs where payload like '%{lbl2}%' and exception like '%tenant_inactive%'")
    seen = [json.loads(x) for x in redis("lrange", PROBE, "0", "-1").splitlines() if x.startswith("{")]
    check("after the suspension is pushed, a (validly signed) job for it is refused (tenant_inactive) and dead-lettered", dead == "1" and not [p for p in seen if p["label"] == lbl2], f"dead-lettered={dead}")
    n_before = len(probes("schedule-closure", LIFE))
    rc, ev, tk = tick()
    check("...and the scheduler skips it, with the reason", {e["tenant"]: e["reason"] for e in ev if e["event"] == "scheduler.skip"}.get(LIFE) == "status suspended" and len(probes("schedule-closure", LIFE)) == n_before)
    check("the request path does not consult the replica: the context token alone decides (an 'active' token still serves)", admin_login(LIFE) == 200)

    # ==================================================================================================================
    print("--- E. The reconcile pull converges, and refuses every untrustworthy answer")
    stub_ctl(reset=True, tenants=listing())
    rc, ev, raw = reconcile()
    check("a pull against the unchanged listing succeeds", rc == 0 and ev.get("event") == "registry.reconcile.ok", raw[-300:])
    check("Wellms really called the stub, and its signed request verified", stub_seen() and stub_seen()[-1]["verified"] is True, str(stub_seen()[-1:]))
    # tenants pushed (not listed by the stub) were touched seconds ago, so the snapshot cannot remove them
    check("tenants pushed moments ago and absent from the listing are NOT removed by it (an in-flight push is safe)", row(LIFE)[0] == "suspended" and row(ORD)[0] == "suspended", f"{row(LIFE)} {row(ORD)}")

    r = REV0 + 300
    stub_ctl(tenants=listing({PULL: ("active", r)}))
    rc, ev, raw = reconcile()
    check("a tenant that only exists in Luminate's listing arrives by pull", PULL in ev.get("applied", []) and row(PULL)[0] == "active" and row(PULL)[2] == "pull", raw[-300:])
    rc, evs, tk = tick()
    check("...and is provisioned by the next tick, just like a pushed one", {"event": "scheduler.provisioned", "tenant": PULL} in evs)
    stub_ctl(tenants=listing({PULL: ("suspended", r + 1)}))
    rc, ev, raw = reconcile()
    check("a status change arrives by pull", PULL in ev.get("applied", []) and row(PULL)[0] == "suspended", raw[-300:])
    stub_ctl(tenants=listing({PULL: ("active", r)}))
    rc, ev, raw = reconcile()
    check("a pull cut BEFORE that change (older rev) is stale and does not undo it", PULL in ev.get("stale", []) and row(PULL)[0] == "suspended", raw[-300:])

    before = registry_rows()
    refusals = [
        ("a forged signature on the listing", dict(mode="badsig", tenants=listing({PULL: ("active", r + 9)})), "registry_signature_invalid"),
        ("a listing with no signature headers", dict(mode="nots", tenants=listing({PULL: ("active", r + 9)})), "registry_signature_missing"),
        ("an HTTP 500", dict(mode="http500"), "HTTP 500"),
        ("a body that is not JSON", dict(mode="notjson"), "not JSON"),
        ("an as_of in the future", dict(mode="ok", as_of=int(time.time()) + 3600, tenants=listing({PULL: ("active", r + 9)})), "future"),
        ("an EMPTY listing (would remove every tenant)", dict(mode="ok", as_of=None, tenants={}), "lists no tenants"),
        ("a listing with one malformed entry among good ones", dict(mode="ok", as_of=None, tenants={**listing(), "t-bad": {"status": "bogus", "rev": 1, "placement": {}}}), "t-bad"),
    ]
    for label, ctl, needle in refusals:
        stub_ctl(reset=True, **ctl)
        n0 = len(stub_seen())
        rc, ev, raw = reconcile()
        check(f"pull refused: {label}", rc != 0 and ev.get("event") == "registry.reconcile.failed" and needle in raw and len(stub_seen()) == n0 + 1, raw[-250:])
        check(f"   ...and the replica is byte-for-byte unchanged", registry_rows() == before)
    failed_state = psql("select v from luminate_registry_meta where k='pull_error'")
    check("the failure is recorded for operators", failed_state != "", failed_state)

    # tombstoning: absent from the listing AND not touched since before the snapshot was cut
    stub_ctl(reset=True, tenants=listing({}), mode="ok", as_of=None)
    psql(f"update luminate_tenant_registry set updated_at = updated_at - 1000 where tenant_id = '{PULL}'")
    rc, ev, raw = reconcile()
    check("a tenant the listing no longer holds (and that nothing touched recently) is tombstoned, not deleted", PULL in ev.get("removed", []) and row(PULL)[0] == "removed" and row(PULL)[2] == "absent", raw[-300:])
    pending_pull = [e for e in tick()[1] if e.get("tenant") == PULL]
    check("a tombstoned tenant is unknown to every consumer (the scheduler does not even list it)", not pending_pull, str(pending_pull))
    stub_ctl(tenants=listing({PULL: ("active", r + 1)}))
    rc, ev, raw = reconcile()
    check("if Luminate lists it again at its rev, it is brought back", PULL in ev.get("applied", []) and row(PULL)[0] == "active", raw[-300:])

    # ==================================================================================================================
    print("--- F. Seeding, status, key separation, and the request path")
    snap = {"version": 1, "tenants": {
        f"t-reg-seed-{RUN}": {"status": "active", "placement": {"mode": "pooled", "connection": "shard-1", "bucket": "wellms", "cache_prefix": f"t-reg-seed-{RUN}"}},
        "t-alpha": {"status": "suspended", "placement": {"mode": "pooled", "connection": "shard-1", "bucket": "wellms", "cache_prefix": "t-alpha"}},
    }}
    sh(["docker", "exec", "-i", "-u", "www-data", API, "sh", "-c", "cat > /tmp/seed.json"], stdin=json.dumps(snap))
    rc, out, err = artisan("luminate:registry:import", "/tmp/seed.json")
    check("import adds only tenants the registry has never heard of", rc == 0 and '"added":1' in out.replace(" ", "") and row(f"t-reg-seed-{RUN}")[2] == "seed", out + err)
    check("...and can never overwrite or resurrect: a snapshot saying t-alpha is suspended changes nothing", row("t-alpha")[0] == "active", str(row("t-alpha")))
    rc, out, err = artisan("luminate:registry:import", "/tmp/nonexistent.json")
    check("no snapshot file is not an error (production has none)", rc == 0 and "skipped" in out, out + err)

    tick()  # provisions the tenant the import just added (the scheduler does that, not the import)
    rc, out, err = artisan("luminate:tenancy:status", "--json", "--allow-privileged")
    try:
        st = json.loads(out)
    except Exception:
        st = {}
    reg = st.get("registry", {})
    check("status reports the replica: database driver, key and pull configured, last pull recorded", reg.get("driver") == "database" and reg.get("keys_configured") and reg.get("pull_configured")
          and (reg.get("last_pull") or {}).get("ok_at"), str(reg)[:300])
    check("status shows the tenants by status and none pending provisioning", "active" in reg.get("tenants", {}) and reg.get("pending_provision") == [], str(reg)[:300])
    check("with a fresh reconcile, status does not call the replica stale", not [p for p in st.get("problems", []) if "stale" in p], str(st.get("problems")))

    psql("update luminate_registry_meta set v = (extract(epoch from now())::bigint - 100000)::text where k = 'pull_ok_at'")
    rc, out, err = artisan("luminate:tenancy:status", "--json", "--allow-privileged")
    check("a replica not reconciled for hours is REPORTED as stale (and status exits non-zero)", rc != 0 and "registry replica is stale" in out, out[-300:])
    rc, ev, raw = reconcile()
    rc, out, err = artisan("luminate:tenancy:status", "--json", "--allow-privileged")
    check("a successful pull clears it", "registry replica is stale" not in out, out[-300:])

    rc, out, err = artisan("luminate:tenancy:status", "--json", "--allow-privileged", env={"LUMINATE_REGISTRY_KEY": CTX_KEY})
    check("a registry key equal to the tenant-context key is flagged", "equals a tenant-context key" in out, out[-300:])
    rc, out, err = artisan("tinker", "--execute=echo json_encode(app(Luminate\\Tenancy\\Registry\\RegistryAuth::class)->enabled());", env={"LUMINATE_REGISTRY_KEY": CTX_KEY, "LUMINATE_REGISTRY_KEY_PREVIOUS": ""})
    check("...and registry sync is then DISABLED (it fails closed rather than accept the shared key)", out.strip().endswith("false"), out + err)
    rc, out, err = artisan("tinker", "--execute=echo json_encode(app(Luminate\\Tenancy\\Registry\\RegistryAuth::class)->enabled());")
    check("with the real, distinct key it is enabled", out.strip().endswith("true"), out + err)

    # fail closed: the replica table unreadable. The request path must not care; consumers must refuse.
    psql("alter table luminate_tenant_registry rename to luminate_tenant_registry_off", owner=True)
    try:
        rc_login = admin_login("t-alpha")
        rc, evs, tk = tick()
        ran_any = [e for e in evs if e.get("event") == "scheduler.ran"]
        check("with the replica table GONE, requests are still served (the request path never reads the registry)", rc_login == 200, str(rc_login))
        check("...and the scheduler tick fails closed: it reports the replica unreadable and runs nothing", rc != 0 and "unreadable" in tk and not ran_any, tk[-300:])
    finally:
        psql("alter table luminate_tenant_registry_off rename to luminate_tenant_registry", owner=True)
    rc, evs, tk = tick()
    check("renamed back: the scheduler resumes", rc == 0 and {e["tenant"] for e in evs if e.get("event") == "scheduler.ran"} >= {"t-alpha", "t-beta", "t-delta"}, tk[-300:])

    # leave the dev registry as found: the stub serves the dev list, the four dev tenants converge on it
    stub_ctl(reset=True)
    rc, ev, raw = reconcile()
    check("after the suite the dev tenants are exactly as the dev snapshot says", rc == 0 and all(row(t)[0] == s for t, s in DEV.items()), str({t: row(t) for t in DEV}))
    # leave nothing servable behind: the tenants this run made are suspended or removed
    for t in (LIFE, ORD, PULL, f"t-reg-seed-{RUN}", probe_tid):
        cur = row(t)
        if cur and cur[0] == "active":
            push(t, "suspended", int(cur[1]) + 1)
finally:
    sh(["docker", "compose", "start", "scheduler"], cwd=INFRA)

deadline = time.time() + 240
health = ""
while time.time() < deadline:
    health = sh(["docker", "inspect", "luminate-wellms-scheduler-1", "--format", "{{.State.Health.Status}}"])[1]
    if health == "healthy":
        break
    time.sleep(5)
check("the scheduler service comes back and is healthy", health == "healthy", health)

print(f"\n{sum(results)}/{len(results)} passed   (run {RUN})")
sys.exit(0 if all(results) else 1)
