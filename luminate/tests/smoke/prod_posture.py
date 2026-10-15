"""Batch 4c: production posture and "the app never connects as the owner/superuser" (contract §H), against the live stack.

    python -I luminate/tests/smoke/prod_posture.py

(b) A production-like API is started from the BASE compose file only (no docker-compose.dev.yml) and served over real HTTP with PHP's
    built-in server, so the real middleware runs with the real production configuration: Swagger and the spec must need a tenant
    token, and no test fixture may exist.
(c) Request, queue-job and provisioning work refuses to run when configured with the owner role, and Postgres' own session list,
    sampled while requests, jobs, a scheduler tick and provisioning run, shows ONLY the app role.
Needs the stack to be up with the dev override (the default for local development).
"""
import json
import pathlib
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.request

HERE = pathlib.Path(__file__).resolve()
sys.path.insert(0, str(HERE.parents[2] / "tools"))
import context_token as ct  # noqa: E402

INFRA = HERE.parents[2] / "infra"
E = ct.read_env()
KEY = E["TENANT_CONTEXT_KEY"]
DB = E.get("DB_NAME", "wellms")
APP, OWNER, BOOT = E["DB_APP_USER"], E["DB_OWNER_USER"], E["DB_BOOTSTRAP_USER"]
results = []
BASE_ONLY = ["docker", "compose", "-f", "docker-compose.yml"]  # explicitly NOT the dev override


def check(name, ok, detail=""):
    results.append(ok)
    print(f"{'PASS' if ok else 'FAIL'}  {name}" + (f"  [{detail}]" if detail and not ok else ""))


def sh(args, stdin=None, cwd=INFRA, timeout=300):
    p = subprocess.run(args, input=stdin, capture_output=True, text=True, cwd=cwd, timeout=timeout)
    return p.returncode, p.stdout.strip(), p.stderr.strip()


def get(url, headers=None, method="GET", data=None):
    req = urllib.request.Request(url, headers=headers or {}, method=method, data=data)
    try:
        with urllib.request.urlopen(req, timeout=20) as r:
            return r.status, r.read().decode()
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode()
    except Exception as e:  # not listening yet
        return 0, str(e)


def prodlike_server(name, port, extra_env=()):
    """Start the api image with BASE compose config only, serving HTTP via php -S (so runningInConsole() is false)."""
    script = ("mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache storage/logs && "
              "printf '%s' \"$JWT_PUBLIC_KEY_BASE64\" | base64 -d > storage/oauth-public.key && "
              "printf '%s' \"$JWT_PRIVATE_KEY_BASE64\" | base64 -d > storage/oauth-private.key && "
              f"exec php -S 0.0.0.0:{port} -t public public/index.php")
    sh(["docker", "rm", "-f", name])
    env_args = sum([["-e", e] for e in extra_env], [])
    rc, out, err = sh(BASE_ONLY + ["run", "-d", "--no-deps", "--name", name, "-p", f"127.0.0.1:{port}:{port}", *env_args, "api", "sh", "-c", script])
    for _ in range(60):
        # an HTTP answer only counts if it came from OUR container: something else may already own the port (it did once)
        running = sh(["docker", "inspect", "-f", "{{.State.Running}}", name])[1].strip() == "true"
        if running and get(f"http://127.0.0.1:{port}/docs")[0] != 0:
            return True
        time.sleep(1)
    print("server did not start:", err or out)
    return False


def token(tid):
    return ct.mint(KEY, tid)


# ======================================================================================================================
print("--- B1. The BASE compose file (what production uses) configures the safe posture")
rc, out, err = sh(BASE_ONLY + ["config", "--format", "json"])
cfg = json.loads(out)
for svc in ("api", "worker", "provision", "scheduler"):
    env = cfg["services"][svc]["environment"]
    check(f"{svc}: no tenant-less exempt path, fixtures off", env.get("LUMINATE_TENANCY_EXEMPT_PATHS") == "" and env.get("LUMINATE_TENANCY_TEST_FIXTURES") == "false",
          f"{env.get('LUMINATE_TENANCY_EXEMPT_PATHS')!r} {env.get('LUMINATE_TENANCY_TEST_FIXTURES')!r}")
    check(f"{svc}: connects as the APP role", env.get("DB_USERNAME") == APP, env.get("DB_USERNAME"))
    check(f"{svc}: the owner and bootstrap roles are on its forbidden list",
          {OWNER, BOOT} <= set(env.get("LUMINATE_TENANCY_FORBIDDEN_DB_USERS", "").split(",")), env.get("LUMINATE_TENANCY_FORBIDDEN_DB_USERS"))
check("only the one-shot migrate job connects as the owner", cfg["services"]["migrate"]["environment"].get("DB_USERNAME") == OWNER)
others = [s for s, v in cfg["services"].items() if s != "migrate" and v.get("environment", {}).get("DB_USERNAME") == OWNER]
check("no other service is configured with the owner role", not others, str(others))

# ======================================================================================================================
print("--- B2. A production-like API over real HTTP")
PORT = 8097  # not 8099 (dev webhook receiver) or 8098 (dev registry stub)
try:
    up = prodlike_server("luminate-prodcheck", PORT, ["LUMINATE_REGISTRY_PULL_URL=https://luminate.invalid/registry/tenants"])
    check("the production-like server starts", up)
    if up:
        u = f"http://127.0.0.1:{PORT}"
        for path in ("/docs", "/api/documentation", "/api/documentation/", "/api/oauth2-callback"):
            s, body = get(u + path)
            check(f"GET {path} with no tenant token is refused (401), not served", s == 401 and "tenant_context_missing" in body, f"{s} {body[:80]}")
        s, body = get(u + "/docs", {"X-Tenant-Context": token("t-alpha")})
        check("the same route WITH a valid tenant token is served (so the refusal above is the exemption list, not a broken server)", s == 200 and '"openapi"' in body, f"{s}")
        s, body = get(u + "/api/events", {"X-Tenant-Context": token("t-alpha")})
        check("a normal API route works with a tenant token", s == 200, f"{s} {body[:80]}")
        rc, out, err = sh(["docker", "exec", "luminate-prodcheck", "php", "artisan", "list", "--raw"])
        check("the test fixtures do not exist (no probe commands registered)", rc == 0 and "probe" not in out and "luminate:tenancy:status" in out, out[-200:])
        rc, out, err = sh(["docker", "exec", "luminate-prodcheck", "php", "artisan", "luminate:tenancy:status", "--strict"])
        check("`luminate:tenancy:status --strict` passes in the production-like container", rc == 0 and "OK" in out, (out + err)[-300:])
        for label, env, needle in (("no registry pull url (no reconciliation safety net behind push)", "LUMINATE_REGISTRY_PULL_URL=", "registry pull url"),
                                   ("no registry key (Luminate could not push tenant changes)", "LUMINATE_REGISTRY_KEY=", "registry key"),
                                   ("a static-file replica instead of push + reconcile", "LUMINATE_REPLICA_DRIVER=file", "replica driver")):
            rc, out, err = sh(["docker", "exec", "-e", env, "luminate-prodcheck", "php", "artisan", "luminate:tenancy:status", "--strict"])
            check(f"`--strict` FAILS with {label}", rc != 0 and needle in out, (out + err)[-200:])
finally:
    sh(["docker", "rm", "-f", "luminate-prodcheck"])

rc, out, err = sh(["docker", "exec", "-u", "www-data", "luminate-wellms-api-1", "php", "artisan", "luminate:tenancy:status", "--strict"])
check("...and `--strict` FAILS on the dev stack, so the check can tell the difference", rc != 0 and "exempt paths" in out and "fixtures" in out, (out + err)[-300:])

# ======================================================================================================================
print("--- C1. A process configured with the OWNER role refuses to serve tenant work")
OWNER_ENV = [f"DB_USERNAME={OWNER}", f"DB_PASSWORD={E['DB_OWNER_PASSWORD']}"]
try:
    up = prodlike_server("luminate-ownercheck", 8096, OWNER_ENV)
    check("a server misconfigured with the owner role starts (it is the deployment that is wrong)", up)
    if up:
        u = "http://127.0.0.1:8096"
        s, body = get(u + "/api/events", {"X-Tenant-Context": token("t-alpha")})
        check("HTTP: it refuses every request with 503 forbidden_db_role, even with a valid tenant token", s == 503 and "forbidden_db_role" in body, f"{s} {body[:100]}")
        s, body = get(u + "/docs")
        check("HTTP: it refuses even the paths that would otherwise be exempt", s == 503, f"{s}")
finally:
    sh(["docker", "rm", "-f", "luminate-ownercheck"])

script = ("mkdir -p storage/framework/cache storage/framework/views storage/framework/sessions storage/logs && "
          "php artisan luminate:tenant:provision t-alpha")
rc, out, err = sh(BASE_ONLY + ["run", "--rm", "--no-deps", *sum([["-e", e] for e in OWNER_ENV], []), "api", "sh", "-c", script])
check("provisioning as the owner is refused", rc != 0 and "Refusing" in (out + err), (out + err)[-200:])

# queue: stop the real worker so it cannot take the job first
try:
    sh(["docker", "compose", "stop", "worker"])
    label = f"owner-job-{int(time.time())}"
    sh(["docker", "exec", "-u", "www-data", "luminate-wellms-api-1", "php", "artisan", "luminate:tenancy:probe-job", label, "t-alpha"])
    before = sh(["docker", "exec", "-e", f"PGPASSWORD={E['DB_APP_PASSWORD']}", "luminate-wellms-postgres-1", "psql", "-h", "127.0.0.1", "-U", APP, "-d", DB, "-At", "-c",
                 "select count(*) from failed_jobs"])[1]
    rc, out, err = sh(BASE_ONLY + ["run", "--rm", "--no-deps", *sum([["-e", e] for e in OWNER_ENV], []), "-e", "LUMINATE_TENANCY_TEST_FIXTURES=true", "api", "sh", "-c",
                                   "mkdir -p storage/framework/cache storage/logs && php artisan queue:work redis --once --queue=default --tries=1"])
    q = f"select count(*) from failed_jobs where payload like '%{label}%' and exception like '%Refusing to run tenant work%'"
    refused = sh(["docker", "exec", "-e", f"PGPASSWORD={E['DB_APP_PASSWORD']}", "luminate-wellms-postgres-1", "psql", "-h", "127.0.0.1", "-U", APP, "-d", DB, "-At", "-c", q])[1]
    ran = [l for l in sh(["docker", "exec", "luminate-wellms-redis-1", "redis-cli", "-a", E["REDIS_PASSWORD"], "--no-auth-warning", "lrange", "luminate:probe", "0", "-1"])[1].splitlines() if label in l]
    check("queue: a worker configured with the owner role did NOT run the job", not ran, str(ran)[:120])
    check("queue: the job took the ordinary failure path, naming the reason (retryable once the worker is fixed)", refused == "1", f"failed_jobs rows matching: {refused}")
    sh(["docker", "exec", "-e", f"PGPASSWORD={E['DB_APP_PASSWORD']}", "luminate-wellms-postgres-1", "psql", "-h", "127.0.0.1", "-U", APP, "-d", DB, "-c",
        f"delete from failed_jobs where payload like '%{label}%'"])
finally:
    sh(["docker", "compose", "start", "worker"])

# ======================================================================================================================
print("--- C2. Postgres' own session list, sampled while real work runs, shows only the app role")
seen = {}
stop = threading.Event()


def sampler():
    while not stop.is_set():
        rc, out, _ = sh(["docker", "exec", "-e", f"PGPASSWORD={E['DB_BOOTSTRAP_PASSWORD']}", "-e", "PGAPPNAME=luminate-sampler", "luminate-wellms-postgres-1",
                         "psql", "-h", "127.0.0.1", "-U", BOOT, "-d", DB, "-At", "-c",
                         "select usename from pg_stat_activity where datname = current_database() and backend_type = 'client backend' "
                         "and application_name <> 'luminate-sampler'"], timeout=30)
        for u in out.splitlines():
            seen[u] = seen.get(u, 0) + 1
        time.sleep(0.15)


t = threading.Thread(target=sampler, daemon=True)
t.start()
try:
    tokens = {tid: ct.mint(KEY, tid) for tid in ("t-alpha", "t-beta")}
    base = f"http://{E['LAN_HOST']}:{E.get('API_PORT', '8080')}"
    for i in range(30):  # requests through the real stack
        get(base + "/api/events", {"X-Tenant-Context": tokens["t-alpha" if i % 2 else "t-beta"]})
    for i in range(4):   # queue jobs through the real worker
        sh(["docker", "exec", "-u", "www-data", "luminate-wellms-api-1", "php", "artisan", "luminate:tenancy:probe-job", f"sess-{i}-{int(time.time())}", "t-alpha" if i % 2 else "t-beta"])
    sh(["docker", "exec", "-u", "www-data", "luminate-wellms-api-1", "php", "artisan", "luminate:schedule:work", "--once"])       # scheduler tick
    # --no-deps: without it compose re-runs the `migrate` dependency, which legitimately connects as the owner. That is the
    # migration job, not tenant work; this measures the provisioning work itself.
    sh(["docker", "compose", "run", "--rm", "--no-deps", "provision", "t-alpha"])                                                   # provisioning
    time.sleep(3)
finally:
    stop.set()
    t.join(timeout=10)

print(f"INFO  sessions observed by role: {seen}")
check("the sampler actually saw application sessions (the check is not vacuous)", seen.get(APP, 0) >= 5, str(seen))
check("every observed session belonged to the app role", set(seen) == {APP}, str(seen))
check("no session was ever the owner or the bootstrap superuser", OWNER not in seen and BOOT not in seen and "postgres" not in seen, str(seen))

print(f"\n{sum(results)}/{len(results)} passed")
sys.exit(0 if all(results) else 1)
