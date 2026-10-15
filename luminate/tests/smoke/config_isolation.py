"""Batch 4c.3: per-tenant DB-backed settings (escolalms/settings), against the live stack.

    python -I luminate/tests/smoke/config_isolation.py

Wellms keeps ALL admin-editable settings in one `config` row (id = 1), caches them under ONE constant cache key, and loads them
in provider boot() before any tenant exists. This proves: tenants can each save settings (the PK is composite); each request
sees only its own tenant's values; a tenant that never saved anything sees the defaults, not someone else's; the cache holds
one entry per tenant and none unprefixed; and a long-lived queue worker switching between tenants never carries values over.
Needs the dev fixtures (docker-compose.dev.yml).
"""
import json
import pathlib
import subprocess
import sys
import time
import urllib.error
import urllib.request
import uuid

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[2] / "tools"))
import context_token as ct  # noqa: E402
import luminate_client as lc  # noqa: E402  (per-tenant machine-admin secrets, contract A.2.2)

E = ct.read_env()
BASE = (sys.argv[1] if len(sys.argv) > 1 else f"http://{E['LAN_HOST']}:{E.get('API_PORT', '8080')}").rstrip("/")
KEY = E["TENANT_CONTEXT_KEY"]
ADMIN = E["INITIAL_USER_EMAIL"]  # the password is per tenant: lc.password(tid)
SETTING = "escola_auth.return_url"  # a registered, non-readonly, PUBLIC setting (config key 'escola_auth')
RUN = uuid.uuid4().hex[:8]
results = []


def call(method, path, tid=None, bearer=None, body=None):
    h = {"Accept": "application/json"}
    if tid:
        h["X-Tenant-Context"] = ct.mint(KEY, tid)
    if bearer:
        h["Authorization"] = "Bearer " + bearer
    data = json.dumps(body).encode() if body is not None else None
    if data:
        h["Content-Type"] = "application/json"
    req = urllib.request.Request(BASE + path, data=data, method=method, headers=h)
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            raw = r.read()
            return r.status, (json.loads(raw) if raw else None)
    except urllib.error.HTTPError as e:
        raw = e.read().decode()
        try:
            return e.code, json.loads(raw)
        except Exception:
            return e.code, raw[:300]


def check(name, ok, detail=""):
    results.append(ok)
    print(f"{'PASS' if ok else 'FAIL'}  {name}" + (f"  [{detail}]" if detail and not ok else ""))


def sh(args, stdin=None):
    p = subprocess.run(args, input=stdin, capture_output=True, text=True)
    return p.returncode, p.stdout.strip(), p.stderr.strip()


def redis(*a):
    return sh(["docker", "exec", "luminate-wellms-redis-1", "redis-cli", "-a", E["REDIS_PASSWORD"], "--no-auth-warning", *a])[1]


def psql(role, pw, sql, guc=None):
    pre = f"select set_config('app.tenant_id','{guc}',false);" if guc else ""
    p = subprocess.run(["docker", "exec", "-i", "-e", f"PGPASSWORD={pw}", "luminate-wellms-postgres-1", "psql", "-h", "127.0.0.1", "-U", role,
                        "-d", E.get("DB_NAME", "wellms"), "-At", "-q", "-v", "ON_ERROR_STOP=1"], input=pre + sql, capture_output=True, text=True)
    return (p.stdout.strip().splitlines() or [""])[-1]


def public_value(tid):
    s, r = call("GET", "/api/config", tid)
    if s != 200:
        return f"HTTP {s}"
    cfg = r.get("data", r) if isinstance(r, dict) else {}
    node = cfg
    for part in SETTING.split("."):
        node = node.get(part) if isinstance(node, dict) else None
    return node


tok = {}
for t in ("t-alpha", "t-beta", "t-delta"):
    s, r = call("POST", "/api/auth/login", t, body={"email": ADMIN, "password": lc.password(t)})
    tok[t] = r["data"]["token"] if s == 200 else ""
    check(f"{t}: admin logs in", bool(tok[t]), f"{s} {str(r)[:100]}")

default = public_value("t-delta")
print(f"INFO  default value of {SETTING} (t-delta never saves one): {default!r}")

# --- save per-tenant values (both must succeed: composite PK) ----------------------------------------------------------
vals = {"t-alpha": f"https://alpha-{RUN}.example/return", "t-beta": f"https://beta-{RUN}.example/return"}
for t, v in vals.items():
    s, r = call("POST", "/api/admin/config", t, tok[t], {"config": [{"key": SETTING, "value": v}]})
    check(f"{t}: saves its own setting (200)", s == 200, f"{s} {str(r)[:200]}")

# --- each tenant reads its own; the untouched tenant sees the default -------------------------------------------------------
check("alpha reads alpha's value", public_value("t-alpha") == vals["t-alpha"], repr(public_value("t-alpha")))
check("beta reads beta's value", public_value("t-beta") == vals["t-beta"], repr(public_value("t-beta")))
check("delta (never saved) reads the DEFAULT, not alpha's or beta's", public_value("t-delta") == default and default not in vals.values(), repr(public_value("t-delta")))
for _ in range(3):  # alternate to catch anything sticky between requests
    a, b = public_value("t-alpha"), public_value("t-beta")
    if a != vals["t-alpha"] or b != vals["t-beta"]:
        check("alternating requests keep returning each tenant's own value", False, f"alpha={a!r} beta={b!r}")
        break
else:
    check("alternating requests keep returning each tenant's own value", True)

s, r = call("GET", "/api/admin/config", "t-alpha", tok["t-alpha"])
dump = json.dumps(r, ensure_ascii=False)  # the response was parsed, so slashes are unescaped here
check("alpha's admin config view shows alpha's value", vals["t-alpha"] in dump, dump[:160])
check("...and never beta's", vals["t-beta"] not in dump)

# --- the database: one row per tenant, each invisible to the other -------------------------------------------------------------
owner_rows = psql(E["DB_OWNER_USER"], E["DB_OWNER_PASSWORD"], "select count(*) from config where tenant_id in ('t-alpha','t-beta')")
check("two config rows exist (one per tenant, both with id = 1)", owner_rows == "2" and psql(E["DB_OWNER_USER"], E["DB_OWNER_PASSWORD"], "select count(*) from config where id = 1") == "2", owner_rows)
check("beta cannot see alpha's config row at the database level", psql(E["DB_APP_USER"], E["DB_APP_PASSWORD"], "select count(*) from config where value::text like '%alpha-%'", guc="t-beta") == "0")
check("the config primary key is (tenant_id, id)", "tenant_id, id" in psql(E["DB_OWNER_USER"], E["DB_OWNER_PASSWORD"], "select pg_get_constraintdef(oid) from pg_constraint where conrelid = 'config'::regclass and contype = 'p'"))

# --- the cache: one entry per tenant, none shared ------------------------------------------------------------------------------
_notify = redis("--scan", "--pattern", "*queues:default:notify").splitlines()
prefix = _notify[0][: -len("queues:default:notify")] if _notify else ""
# Laravel keeps the cache on its own Redis connection/database (not the queue's), so look in every database that has keys.
keys = []
for db in range(0, 6):
    for k in sh(["docker", "exec", "luminate-wellms-redis-1", "redis-cli", "-a", E["REDIS_PASSWORD"], "--no-auth-warning", "-n", str(db),
                 "--scan", "--pattern", "*escolalms_config_cache*"])[1].splitlines():
        if k:
            keys.append(k)
print(f"INFO  settings cache keys: {sorted(keys)}")
check("alpha and beta each have their own cached settings entry", f"{prefix}t-alpha:escolalms_config_cache" in keys and f"{prefix}t-beta:escolalms_config_cache" in keys, str(keys))
check("there IS something to check (the scan is not vacuously empty)", len(keys) >= 2, str(keys))
check("no settings cache entry exists outside a tenant prefix (none bare, none under 'untenanted')",
      all(k.startswith(prefix + "t-") for k in keys), str(keys))

# --- a long-lived worker switching tenants never carries settings over ---------------------------------------------------------------
def artisan(*a):
    return sh(["docker", "exec", "-u", "www-data", "luminate-wellms-api-1", "php", "artisan", *a])


rc, _, _ = artisan("list", "--raw")
_probe = redis("--scan", "--pattern", "*luminate:probe").splitlines()
probe_key = (_probe[0] if _probe else prefix + "luminate:probe")
redis("del", probe_key)
order = ["t-alpha", "t-beta", "t-delta", "t-alpha", "t-delta", "t-beta", "t-delta", "t-alpha"]
labels = []
for i, t in enumerate(order):
    label = f"{RUN}-cfg-{i}-{t}"
    labels.append((label, t))
    artisan("luminate:tenancy:probe-job", label, t)
want = {"t-alpha": vals["t-alpha"], "t-beta": vals["t-beta"], "t-delta": default}
deadline = time.time() + 120
got = {}
while time.time() < deadline and len(got) < len(labels):
    for line in redis("lrange", probe_key, "0", "-1").splitlines():
        if line.startswith("{"):
            r = json.loads(line)
            if r["label"].startswith(RUN):
                got[r["label"]] = r
    time.sleep(2)
check(f"all {len(labels)} jobs ran", len(got) == len(labels), f"{len(got)}")
bad = [(l, t, got[l].get("return_url")) for l, t in labels if l in got and got[l].get("return_url") != want[t]]
check("every job saw its OWN tenant's setting (alpha/beta/delta interleaved in one worker)", not bad, str(bad[:3]))

print(f"\n{sum(results)}/{len(results)} passed")
sys.exit(0 if all(results) else 1)
