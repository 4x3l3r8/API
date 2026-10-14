"""Batch 5 operational guarantees, against the live dev stack:

    python -I luminate/tests/smoke/ops_guards.py

 1. contract section H: Wellms' own cart/subscription scheduling is switched off, and nothing else is;
 2. a money-moving job that is dispatched anyway is refused by the worker and never runs;
 3. an event that fires with NO tenant is dropped (never recorded, never sent);
 4. failed_jobs retention deletes old rows, keeps recent ones, and runs outside any tenant;
 5. the production-like compose config has the webhook module off, http refused and no secret baked in.
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
INFRA = pathlib.Path(__file__).resolve().parents[2] / "infra"
PROBE = pathlib.Path(__file__).resolve().parents[1] / "support" / "ops_probe.php"
RUN = uuid.uuid4().hex[:8]
results = []


def check(name, ok, detail=""):
    results.append(ok)
    print(f"{'PASS' if ok else 'FAIL'}  {name}" + (f"  [{detail}]" if detail and not ok else ""))


def sh(args, stdin=None, cwd=None):
    p = subprocess.run(args, input=stdin, capture_output=True, text=True, cwd=cwd)
    return p.returncode, p.stdout.strip(), p.stderr.strip()


def psql(sql, role="app", guc=None):
    user, pw = (E["DB_APP_USER"], E["DB_APP_PASSWORD"]) if role == "app" else (E["DB_OWNER_USER"], E["DB_OWNER_PASSWORD"])
    pre = f"select set_config('app.tenant_id','{guc}',false);" if guc else ""
    rc, out, err = sh(["docker", "exec", "-i", "-e", f"PGPASSWORD={pw}", "luminate-wellms-postgres-1", "psql", "-h", "127.0.0.1", "-U", user,
                       "-d", E.get("DB_NAME", "wellms"), "-At", "-q", "-F", "|"], stdin=pre + sql)
    lines = out.splitlines()
    return [l for l in (lines[1:] if guc else lines) if l], err


def probe(mode, tenant=None):
    env = ["-e", f"LUMINATE_TENANT={tenant}"] if tenant else []
    return sh(["docker", "exec", *env, "-u", "www-data", "luminate-wellms-api-1", "php", "/tmp/ops_probe.php", mode])


def wait_for(pred, timeout, interval=1.0):
    end = time.time() + timeout
    while time.time() < end:
        v = pred()
        if v:
            return v
        time.sleep(interval)
    return pred()


sh(["docker", "cp", str(PROBE), "luminate-wellms-api-1:/tmp/ops_probe.php"])

# ---- 1. cart / subscription scheduling is off, and only that --------------------------------------------------------------------------
print("--- 1. commerce scheduling")
rc, out, err = probe("schedule")
events = json.loads(out.splitlines()[-1])["events"] if rc == 0 and out else []
by = {e["name"]: e["runs"] for e in events}
is_cart = lambda e: "Cart" in e["name"] or "cart:abandoned-event" in (e.get("command") or "")   # a command may carry a custom description
cart = {e["name"]: e["runs"] for e in events if is_cart(e)}
check("the cart events are still REGISTERED (so an upgrade that renames one is visible)", len(cart) == 3, str(cart) + err[:150])
check("...and none of the three will run: RenewRecursiveProduct, ExpireRecursiveProduct, cart:abandoned-event", cart and not any(cart.values()), str(cart))
others = {e["name"]: e["runs"] for e in events if not is_cart(e)}
check("every OTHER scheduled event still runs (the guard disabled exactly three, nothing more)", len(others) >= 8 and all(others.values()), str({n: r for n, r in others.items() if not r}))
check("the webhook sweeper and prune are scheduled and enabled", any("webhooks:dispatch-due" in n and r for n, r in by.items()) and any("webhooks:prune" in n and r for n, r in by.items()), str(sorted(by)))
check("RenewRecursiveProductUser is not scheduled by anyone (it is only ever dispatched by the job above)", not any(n.endswith("RenewRecursiveProductUser") for n in by))

# ---- 2. a denied job that is dispatched anyway never runs ---------------------------------------------------------------------------------
print("--- 2. denied job class")
before = int(psql("select count(*) from failed_jobs where payload like '%RenewRecursiveProduct%'")[0][0])
rc, out, err = probe("dispatch-denied", tenant="t-alpha")
check("the money-moving job could be dispatched as a tenant (so this tests the worker, not the dispatcher)", rc == 0 and "RenewRecursiveProduct" in out, out + err[:200])


def refused():
    rows, _ = psql("select count(*) from failed_jobs where payload like '%RenewRecursiveProduct%' and exception like '%job_denied%'")
    return int(rows[0]) > before and int(rows[0]) or None


n = wait_for(refused, 40)
check("the worker dead-lettered it with reason job_denied", n is not None, f"before={before}")
rc, logs, logerr = sh(["docker", "logs", "--since", "3m", "luminate-wellms-worker-1"])
ran = [l for l in (logs + "\n" + logerr).splitlines() if "RenewRecursiveProduct" in l and ("RUNNING" in l or "DONE" in l)]
check("the worker never started it (no RUNNING/DONE line for it)", not ran, "\n".join(ran[:3]))
psql("delete from failed_jobs where payload like '%RenewRecursiveProduct%' and exception like '%job_denied%'")

# ---- 3. an event with no tenant is dropped ---------------------------------------------------------------------------------------------------
print("--- 3. event with no tenant")
rows_before = psql("select count(*) from luminate_webhook_deliveries", role="owner")[0][0]
rc, out, err = probe("untenanted-event")
rows_after = psql("select count(*) from luminate_webhook_deliveries", role="owner")[0][0]
fired = next((json.loads(l) for l in out.splitlines() if l.startswith("{") and '"fired"' in l), {})
check("the event was fired with no tenant active", fired.get("fired") is True and fired.get("tenant") is None, f"rc={rc} {out[:200]} {err[:300]}")
if fired.get("other_listener_error"):
    print(f"INFO  (another Wellms listener choked on the artificial unsaved models, after ours had already dropped the event: {fired['other_listener_error']})")
check("nothing was recorded (owner view, which sees every tenant)", rows_before == rows_after, f"{rows_before} -> {rows_after}")
check("and it was reported as a critical log line, not swallowed silently", "event dropped, no active tenant" in err + out, err[:300])

# ---- 4. failed_jobs retention ------------------------------------------------------------------------------------------------------------------
print("--- 4. failed_jobs retention")
ids = {"old": f"{RUN}-old", "edge": f"{RUN}-edge", "new": f"{RUN}-new"}
for key, days in (("old", 15), ("edge", 13), ("new", 1)):
    psql(f"insert into failed_jobs (uuid, connection, queue, payload, exception, failed_at) values ('{uuid.uuid4()}', 'redis', 'probe-{ids[key]}', '{{}}', 'probe', now() - interval '{days} days')")
count = lambda key: int(psql(f"select count(*) from failed_jobs where queue = 'probe-{ids[key]}'")[0][0])
check("setup: rows aged 15, 13 and 1 days exist (retention is 14 days)", (count("old"), count("edge"), count("new")) == (1, 1, 1))
rc, out, err = sh(["docker", "exec", "-u", "www-data", "luminate-wellms-api-1", "php", "artisan", "luminate:maintenance"])
line = json.loads(out.splitlines()[-1]) if out else {}
check("the maintenance command reports what it deleted", rc == 0 and line.get("retention_hours") == 336 and line.get("deleted", 0) >= 1, f"rc={rc} {out} {err[:200]}")
check("the 15-day-old row is gone", count("old") == 0)
check("the 13-day-old and the 1-day-old rows are kept", count("edge") == 1 and count("new") == 1)
rc, out, err = sh(["docker", "exec", "luminate-wellms-scheduler-1", "php", "artisan", "luminate:schedule:work", "--once"])
check("the scheduler itself runs the maintenance step (every tick with --once, hourly in service)", "maintenance.failed_jobs" in out, out[-300:] + err[:200])
psql(f"delete from failed_jobs where queue like 'probe-{RUN}%'")
check("probe rows cleaned up", count("edge") == 0 and count("new") == 0)

# ---- 5. production-like posture ---------------------------------------------------------------------------------------------------------------------
print("--- 5. production-like config")
rc, base, err = sh(["docker", "compose", "-f", "docker-compose.yml", "config"], cwd=str(INFRA))
check("base-only config renders", rc == 0, err[:200])
check("with the base file alone the webhook module is OFF (no URL), http is refused, and the retry schedule is the contract's",
      'LUMINATE_WEBHOOK_URL: ""' in base and 'LUMINATE_WEBHOOK_ALLOW_HTTP: "false"' in base and "60,300,1800,7200,21600" in base and 'LUMINATE_WEBHOOK_MAX_AGE: "86400"' in base)
check("the webhook secret is passed by reference to .env, never present in the file (the compose file contains no secret)", E["LUMINATE_WEBHOOK_SECRET"] not in (INFRA / "docker-compose.yml").read_text(encoding="utf-8") + (INFRA / "docker-compose.dev.yml").read_text(encoding="utf-8"))
check("the dev receiver and its fast retry timings exist ONLY in the dev override", "webhook-receiver" not in base and "3,5,8,10" not in base)

print(f"\n{sum(results)}/{len(results)} passed")
sys.exit(0 if all(results) else 1)
