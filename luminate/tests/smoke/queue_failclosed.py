"""Batch 4c.1: queue jobs are tenant-tagged and workers FAIL CLOSED, against the live stack (Horizon in the worker container).

    python -I luminate/tests/smoke/queue_failclosed.py

Valid jobs for two tenants are interleaved with every kind of bad job. Proves: each valid job ran as exactly its tenant (DB
setting, RLS-visible rows, cache prefix, DB role); no bad job ran; every bad job was dead-lettered in failed_jobs with the
right reason; and nothing bled from one job into the next. Needs LUMINATE_TENANCY_TEST_FIXTURES=true (docker-compose.dev.yml).
"""
import json
import pathlib
import subprocess
import sys
import time
import uuid

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[2] / "tools"))
import context_token as ct  # noqa: E402

E = ct.read_env()
APP, DB = E["DB_APP_USER"], E.get("DB_NAME", "wellms")
RUN = uuid.uuid4().hex[:8]
results = []


def sh(args, stdin=None):
    p = subprocess.run(args, input=stdin, capture_output=True, text=True)
    return p.returncode, p.stdout.strip(), p.stderr.strip()


def artisan(*a):
    # as www-data, never root: root-run artisan creates root-owned files in storage/ that php-fpm then cannot write
    return sh(["docker", "exec", "-u", "www-data", "luminate-wellms-api-1", "php", "artisan", *a])


def psql(sql, guc=None):
    pre = f"select set_config('app.tenant_id','{guc}',false);" if guc else ""
    rc, out, err = sh(["docker", "exec", "-i", "-e", f"PGPASSWORD={E['DB_APP_PASSWORD']}", "luminate-wellms-postgres-1",
                       "psql", "-h", "127.0.0.1", "-U", APP, "-d", DB, "-At", "-q", "-v", "ON_ERROR_STOP=1"], stdin=pre + sql)
    if rc != 0:
        raise RuntimeError(err)
    return out.splitlines()[-1] if out else ""


def redis(*a):
    return sh(["docker", "exec", "luminate-wellms-redis-1", "redis-cli", "-a", E["REDIS_PASSWORD"], "--no-auth-warning", *a])[1]


def check(name, ok, detail=""):
    results.append(ok)
    print(f"{'PASS' if ok else 'FAIL'}  {name}" + (f"  [{detail}]" if detail and not ok else ""))


# Wellms sets no Redis key prefix, but do not assume: Horizon's `queues:default:notify` always exists, so derive it from that.
_notify = redis("--scan", "--pattern", "*queues:default:notify").splitlines()
KEY_PREFIX = _notify[0][: -len("queues:default:notify")] if _notify else ""
probe_key = KEY_PREFIX + "luminate:probe"
QUEUE = KEY_PREFIX + "queues:default"
redis("del", probe_key)


def log_counts():
    """(guard refusals logged, Redis pop-script errors logged) in the worker container's log output so far."""
    p = subprocess.run(["docker", "logs", "luminate-wellms-worker-1"], capture_output=True, text=True)
    lines = (p.stdout + "\n" + p.stderr).splitlines()
    return (sum("queue job refused:" in l for l in lines),
            sum("attempt to perform arithmetic on field 'attempts'" in l for l in lines))


LOG0 = log_counts()  # baseline: earlier runs left lines in the same log

# (label, tid or None, forge, expected outcome)  -- outcome: tenant id it must run as, or the refusal reason it must get
plan = []
for i in range(1, 5):
    plan.append((f"v-alpha-{i}", "t-alpha", "none", "t-alpha"))
    plan.append((f"v-beta-{i}", "t-beta", "none", "t-beta"))
bad = [
    ("bad-notenant", None, "none", "missing"),          # dispatched with NO active tenant: untagged
    ("bad-notag", None, "no-tag", "missing"),
    ("bad-garbage", None, "garbage-tag", "malformed"),
    ("bad-badsig", "t-alpha", "bad-sig", "bad_signature"),
    ("bad-swap", None, "swap-tid", "bad_signature"),     # alpha's signature relabelled as beta
    ("bad-replay", "t-beta", "replay", "bad_signature"),  # genuine tag copied from another job
    ("bad-unknown", None, "unknown", "unknown_tenant"),   # signed, tenant not in the replica
    ("bad-inactive", None, "inactive", "tenant_inactive"),  # signed, tenant suspended
]
# interleave: valid, bad, valid, bad ... so a bad job sits between valid jobs of DIFFERENT tenants
merged = []
vi, bi = iter(plan), iter(bad)
for v in vi:
    merged.append(v)
    b = next(bi, None)
    if b:
        merged.append(b)
merged.extend(bi)
merged = [(f"{RUN}-{l}", t, f, o) for (l, t, f, o) in merged]

for label, tid, forge, _ in merged:
    args = ["luminate:tenancy:probe-job", label] + ([tid] if tid else []) + ([f"--forge={forge}"] if forge != "none" else [])
    rc, out, err = artisan(*args)
    if rc != 0:
        print(f"FAIL  could not dispatch {label}: {err or out}")
        sys.exit(2)

valid = {l: o for l, t, f, o in merged if f == "none" and t}
refused = {l: o for l, t, f, o in merged if not (f == "none" and t)}

deadline = time.time() + 120
seen = []
while time.time() < deadline:
    rows = [json.loads(x) for x in redis("lrange", probe_key, "0", "-1").splitlines() if x.startswith("{")]
    seen = [r for r in rows if r["label"].startswith(RUN)]
    dead = int(psql(f"select count(*) from failed_jobs where payload like '%{RUN}-bad-%'") or 0)
    if len(seen) >= len(valid) and dead >= len(refused):
        break
    time.sleep(2)
time.sleep(4)  # give a (wrongly) admitted bad job a chance to show up
rows = [json.loads(x) for x in redis("lrange", probe_key, "0", "-1").splitlines() if x.startswith("{")]
seen = {r["label"]: r for r in rows if r["label"].startswith(RUN)}

check(f"all {len(valid)} valid jobs ran", all(l in seen for l in valid), str(sorted(set(valid) - set(seen))))
check("NO refused job ran (not one of the bad jobs produced a result)", not (set(seen) & set(refused)), str(sorted(set(seen) & set(refused))))

for label, want in sorted(valid.items()):
    r = seen.get(label)
    if not r:
        continue
    users = psql("select count(*) from users", guc=want)
    ok = (r["tenant"] == want and r["guc"] == want and r["db_user"] == APP
          and r["cache_prefix"] == f"{want}:" and str(r["users_visible"]) == users)
    check(f"{label}: ran as {want} (tenant, DB setting, RLS rows={users}, cache prefix, app role)", ok, json.dumps(r))

by_tenant = {}
for l, r in seen.items():
    by_tenant.setdefault(r["tenant"], set()).add(r["guc"])
check("no job saw another job's tenant setting (each tenant's jobs saw only its own)", all(g == {t} for t, g in by_tenant.items()), str(by_tenant))

for label, reason in sorted(refused.items()):
    # the exception column is a multi-line stack trace; the reason is on its first line
    row = psql(f"select split_part(exception, E'\\n', 1) from failed_jobs where payload like '%{label}%' order by id desc limit 1")
    n = psql(f"select count(*) from failed_jobs where payload like '%{label}%'")
    check(f"{label}: dead-lettered exactly once with reason '{reason}'", f"[tenant_job_{reason}]" in row and n == "1", f"rows={n} {row[:140]}")

# A refused job is only proof of fail-closed if the GUARD refused it. A job lost to a Redis/Lua error would also "never run",
# so require the guard's own CRITICAL log line for every refusal, and no pop errors during this run.
guard_lines, lua = log_counts()
guard_new, lua_new = guard_lines - LOG0[0], lua - LOG0[1]
check("the guard itself refused every bad job (one CRITICAL 'queue job refused' line each)", guard_new >= len(refused), f"new={guard_new} expected>={len(refused)}")
check("no job was destroyed by a Redis pop-script error (malformed payload)", lua_new == 0, f"{lua_new} new errors")

stuck = redis("llen", QUEUE)
reserved = redis("zcard", QUEUE + ":reserved")
# only the harness's OWN jobs: other legitimate delayed work (e.g. a quiz timer) may share the queue and is not a refused job
delayed = str(sum(1 for m in redis("zrange", QUEUE + ":delayed", "0", "-1").splitlines() if m.startswith("{") and "ProbeJob" in json.loads(m).get("displayName", "")))
check("no refused job is left in the queue (not reserved, not retrying)", stuck == "0" and reserved == "0" and delayed == "0",
      f"pending={stuck} reserved={reserved} delayed={delayed}")

# real Wellms jobs created while the HTTP smoke tests ran must have been tagged, never refused as untagged
untagged = psql("select count(*) from failed_jobs where exception like '%[tenant_job_missing]%' and payload not like '%ProbeJob%'")
check("no real (non-probe) Wellms job was ever refused for lacking a tenant tag", untagged == "0", untagged)

rc, out, _ = sh(["docker", "inspect", "luminate-wellms-worker-1", "--format", "{{.State.Health.Status}}"])
check("the worker is still healthy after the refusals", out == "healthy", out)

print(f"\n{sum(results)}/{len(results)} passed")
sys.exit(0 if all(results) else 1)
