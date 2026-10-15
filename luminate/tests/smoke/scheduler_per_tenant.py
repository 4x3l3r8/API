"""Batch 4c.5: the per-tenant scheduler, against the live stack.

    python -I luminate/tests/smoke/scheduler_per_tenant.py

Runs the real `luminate:schedule:work --once` (one tick) and proves: every ACTIVE tenant in the replica gets its own
`schedule:run` and every scheduled event ran as that tenant, both an in-process closure and a `command()` event that Laravel
runs in a CHILD process; suspended tenants are skipped; Wellms' real scheduled jobs reach the worker tagged (no refusals); an
unreadable replica runs nothing (fail closed); LUMINATE_TENANT for an unknown or inactive tenant aborts a command. Stops the
compose `scheduler` service while it runs (so its own ticks do not pollute the results) and restores it afterwards.
Needs the dev fixtures (docker-compose.dev.yml).
"""
import json
import pathlib
import subprocess
import sys
import time

HERE = pathlib.Path(__file__).resolve()
sys.path.insert(0, str(HERE.parents[2] / "tools"))
import context_token as ct  # noqa: E402

INFRA = HERE.parents[2] / "infra"
E = ct.read_env()
results = []
API, WORKER = "luminate-wellms-api-1", "luminate-wellms-worker-1"


def check(name, ok, detail=""):
    results.append(ok)
    print(f"{'PASS' if ok else 'FAIL'}  {name}" + (f"  [{detail}]" if detail and not ok else ""))


def sh(args, stdin=None, cwd=None, timeout=300):
    p = subprocess.run(args, input=stdin, capture_output=True, text=True, cwd=cwd, timeout=timeout)
    return p.returncode, p.stdout.strip(), p.stderr.strip()


def redis(*a):
    return sh(["docker", "exec", "luminate-wellms-redis-1", "redis-cli", "-a", E["REDIS_PASSWORD"], "--no-auth-warning", *a])[1]


def psql(sql, guc=None):
    pre = f"select set_config('app.tenant_id','{guc}',false);" if guc else ""
    rc, out, err = sh(["docker", "exec", "-i", "-e", f"PGPASSWORD={E['DB_APP_PASSWORD']}", "luminate-wellms-postgres-1", "psql", "-h", "127.0.0.1",
                       "-U", E["DB_APP_USER"], "-d", E.get("DB_NAME", "wellms"), "-At", "-q", "-v", "ON_ERROR_STOP=1"], stdin=pre + sql)
    return (out.splitlines() or [""])[-1]


_notify = redis("--scan", "--pattern", "*queues:default:notify").splitlines()
PROBE = (_notify[0][: -len("queues:default:notify")] if _notify else "") + "luminate:probe"


def tick(env=None):
    """Run ONE scheduler tick inside the api container, as www-data. Returns (exit code, parsed JSON events, raw output)."""
    args = ["docker", "exec", "-u", "www-data"]
    for k, v in (env or {}).items():
        args += ["-e", f"{k}={v}"]
    rc, out, err = sh(args + [API, "php", "artisan", "luminate:schedule:work", "--once"])
    events = [json.loads(l) for l in out.splitlines() if l.startswith("{")]
    return rc, events, out + "\n" + err


def probes(kind):
    rows = []
    for line in redis("lrange", PROBE, "0", "-1").splitlines():
        if line.startswith("{"):
            r = json.loads(line)
            if r.get("kind") == kind:
                rows.append(r)
    return rows


def write_replica(path, tenants):
    body = json.dumps({"version": 1, "tenants": {t: {"status": s, "placement": {"mode": "pooled", "connection": "shard-1", "bucket": "wellms", "cache_prefix": t}} for t, s in tenants.items()}})
    sh(["docker", "exec", "-i", "-u", "www-data", API, "sh", "-c", f"cat > {path}"], stdin=body)


try:
    sh(["docker", "compose", "stop", "scheduler"], cwd=INFRA)

    failed0 = int(psql("select count(*) from failed_jobs") or 0)
    missing0 = int(psql("select count(*) from failed_jobs where exception like '%[tenant_job_missing]%'") or 0)

    # ------------------------------------------------------------------------------------------------------------------
    print("--- A. One real tick against the dev replica (alpha, beta, delta active; gamma suspended)")
    redis("del", PROBE)
    t0 = time.strftime("%Y-%m-%dT%H:%M:%S", time.gmtime())
    rc, events, raw = tick()
    ran = {e["tenant"] for e in events if e["event"] == "scheduler.ran"}
    skipped = {e["tenant"]: e["reason"] for e in events if e["event"] == "scheduler.skip"}
    check("the tick exits 0", rc == 0, raw[-300:])
    check("schedule:run ran for exactly the active tenants (alpha, beta, delta)", ran == {"t-alpha", "t-beta", "t-delta"}, str(ran))
    check("the suspended tenant was skipped, with the reason logged", skipped.get("t-gamma") == "status suspended", str(skipped))

    for kind, label in (("schedule-closure", "in-process closure event"), ("schedule-command", "command() event in a CHILD process")):
        rows = probes(kind)
        by = {}
        for r in rows:
            by.setdefault(r["tenant"], []).append(r)
        check(f"{label}: ran exactly once for each active tenant and for no one else", {t: len(v) for t, v in by.items()} == {"t-alpha": 1, "t-beta": 1, "t-delta": 1}, str({t: len(v) for t, v in by.items()}))
        bad = []
        for t, v in by.items():
            r = v[0]
            users = psql("select count(*) from users", guc=t)
            if not (r["guc"] == t and r["cache_prefix"] == f"{t}:" and r["db_user"] == E["DB_APP_USER"] and str(r["users_visible"]) == users):
                bad.append((t, r, users))
        check(f"{label}: each run saw its OWN tenant (DB setting, RLS rows, cache prefix, app role)", not bad, json.dumps(bad)[:300])
        if kind == "schedule-command":
            check("the child-process command inherited its tenant through LUMINATE_TENANT", all(r["env_tenant"] == r["tenant"] for r in rows) and len(rows) == 3, str([(r["tenant"], r["env_tenant"]) for r in rows]))
            check("...and really was a separate process from the schedule:run that spawned it", all(r["pid"] != r["ppid"] for r in rows))
    check("nothing ran with NO tenant (no probe record without a tenant)", not [r for k in ("schedule-closure", "schedule-command") for r in probes(k) if not r["tenant"]])

    # ------------------------------------------------------------------------------------------------------------------
    print("--- B. Wellms' own scheduled jobs reach the worker tagged and run (the stock tenant-less scheduler got every one refused)")
    deadline = time.time() + 90
    done = 0
    while time.time() < deadline:
        _, logs, _ = sh(["docker", "logs", WORKER, "--since", t0 + "Z"])
        p = subprocess.run(["docker", "logs", WORKER, "--since", t0 + "Z"], capture_output=True, text=True)
        text = p.stdout + p.stderr
        done = sum(1 for l in text.splitlines() if "ReminderAboutConsultationJob" in l and "DONE" in l)
        if done >= 3:
            break
        time.sleep(3)
    check("ReminderAboutConsultationJob (a real every-minute Wellms job) ran once per active tenant", done >= 3, f"{done} runs")
    time.sleep(5)
    check("no job was refused for lacking a tenant tag", int(psql("select count(*) from failed_jobs where exception like '%[tenant_job_missing]%'") or 0) == missing0)
    check("no job landed in failed_jobs at all during the tick", int(psql("select count(*) from failed_jobs") or 0) == failed0, f"{int(psql('select count(*) from failed_jobs') or 0) - failed0} new")

    # ------------------------------------------------------------------------------------------------------------------
    print("--- C. The replica drives it, live (a temporary replica file; no restart)")
    TMP = "/tmp/replica-test.json"
    write_replica(TMP, {"t-alpha": "suspended", "t-beta": "active", "t-delta": "provisioning"})
    redis("del", PROBE)
    rc, events, raw = tick({"LUMINATE_REPLICA_DRIVER": "file", "LUMINATE_TENANCY_REPLICA": TMP})
    ran = {e["tenant"] for e in events if e["event"] == "scheduler.ran"}
    skipped = {e["tenant"]: e["reason"] for e in events if e["event"] == "scheduler.skip"}
    check("alpha suspended + delta provisioning + beta active: only beta ran", rc == 0 and ran == {"t-beta"}, f"{rc} {ran}")
    check("the skipped tenants were reported with their status", skipped == {"t-alpha": "status suspended", "t-delta": "status provisioning"}, str(skipped))
    check("only beta produced scheduled-event records", {r["tenant"] for k in ("schedule-closure", "schedule-command") for r in probes(k)} == {"t-beta"})

    write_replica(TMP, {"t-alpha": "suspended", "t-beta": "suspended"})
    redis("del", PROBE)
    rc, events, raw = tick({"LUMINATE_REPLICA_DRIVER": "file", "LUMINATE_TENANCY_REPLICA": TMP})
    check("every tenant suspended: nothing runs and the tick still succeeds", rc == 0 and not [e for e in events if e["event"] == "scheduler.ran"] and not probes("schedule-closure"), f"{rc}")

    # ------------------------------------------------------------------------------------------------------------------
    print("--- D. Fail closed: an unreadable replica runs NOTHING")
    sh(["docker", "exec", "-i", "-u", "www-data", API, "sh", "-c", f"printf '{{not json' > {TMP}"])
    redis("del", PROBE)
    rc, events, raw = tick({"LUMINATE_REPLICA_DRIVER": "file", "LUMINATE_TENANCY_REPLICA": TMP})
    check("corrupt replica: the tick fails, says why, and no tenant ran", rc != 0 and "unreadable" in raw and not probes("schedule-closure") and not probes("schedule-command"), f"{rc} {raw[-200:]}")
    rc, events, raw = tick({"LUMINATE_REPLICA_DRIVER": "file", "LUMINATE_TENANCY_REPLICA": "/nonexistent/tenants.json"})
    check("missing replica: the tick fails, and no tenant ran", rc != 0 and "unreadable" in raw and not probes("schedule-closure"), f"{rc} {raw[-200:]}")
    sh(["docker", "exec", "-u", "www-data", API, "rm", "-f", TMP])

    # ------------------------------------------------------------------------------------------------------------------
    print("--- E. LUMINATE_TENANT is fail-closed too")
    def status(env):
        args = ["docker", "exec", "-u", "www-data"] + sum([["-e", f"{k}={v}"] for k, v in env.items()], []) + [API, "php", "artisan", "luminate:tenancy:status"]
        return sh(args)

    rc, out, err = status({"LUMINATE_TENANT": "t-alpha"})
    check("an active tenant works", rc == 0, (out + err)[-200:])
    for tid, why in (("t-gamma", "suspended"), ("t-nobody", "unknown"), ("not a tenant!", "malformed")):
        rc, out, err = status({"LUMINATE_TENANT": tid})
        check(f"LUMINATE_TENANT for a {why} tenant aborts the command", rc != 0 and "refused" in (out + err), (out + err)[-160:])

    # ------------------------------------------------------------------------------------------------------------------
    print("--- F. Deployment")
    rc, out, _ = sh(["docker", "exec", WORKER, "sh", "-c", "ps aux | grep -c '[s]chedule:run'"])
    check("the worker no longer runs the stock tenant-less scheduler loop", out.strip() == "0", out)
finally:
    sh(["docker", "compose", "start", "scheduler"], cwd=INFRA)

deadline = time.time() + 240
health = ""
while time.time() < deadline:
    health = sh(["docker", "inspect", "luminate-wellms-scheduler-1", "--format", "{{.State.Health.Status}}"])[1]
    if health == "healthy":
        break
    time.sleep(5)
check("the scheduler service comes back and is healthy (its heartbeat keeps ticking)", health == "healthy", health)

print(f"\n{sum(results)}/{len(results)} passed")
sys.exit(0 if all(results) else 1)
