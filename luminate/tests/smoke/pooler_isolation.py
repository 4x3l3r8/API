"""Batch "PgBouncer live run", points 1 and 2 of the acceptance: tenant isolation through a TRANSACTION-mode pooler.

    python -I luminate/tests/smoke/pooler_isolation.py

Needs the stack started with docker-compose.pgbouncer.yml (pool of 2 backends shared by every client). It proves, and does not assume:
  - the pooler really is in transaction mode, the app really goes through it, and many clients really share few backends;
  - connection reuse across tenants: a backend handed to a new transaction carries NO tenant setting from its previous user;
  - statements outside any explicit transaction (the connection's BEGIN + set_config wrapper) scope correctly although each may land
    on a different backend;
  - the probe is capable of seeing a leak (a session-level setting MUST leak: if it does not, a pass above would mean nothing).
"""
import json
import pathlib
import subprocess
import sys
import threading
import time

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[2] / "tools"))
import context_token as ct  # noqa: E402
from pooler_admin import admin, pool, stats  # noqa: E402

E = ct.read_env()
API = "luminate-wellms-api-1"
PROBES = pathlib.Path(__file__).resolve().parents[1] / "pooler"
results = []
POOL_SIZE = int({r["key"]: r["value"] for r in admin("SHOW CONFIG")[1]}.get("default_pool_size", "2"))   # what the pooler is really running with


def check(name, ok, detail=""):
    results.append(ok)
    print(f"{'PASS' if ok else 'FAIL'}  {name}" + (f"  [{detail}]" if detail and not ok else ""))


def sh(args):
    p = subprocess.run(args, capture_output=True, text=True)
    return p.returncode, p.stdout.strip(), p.stderr.strip()


def exec_php(script, *args, env=None, wait=True):
    cmd = ["docker", "exec"]
    for k, v in (env or {}).items():
        cmd += ["-e", f"{k}={v}"]
    cmd += ["-u", "www-data", API, "php", f"/tmp/{script}", *map(str, args)]
    if wait:
        return sh(cmd)
    return subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)


def last_json(text):
    for line in reversed(text.splitlines()):
        if line.startswith("{"):
            return json.loads(line)
    return None


def psql(sql, guc=None):
    pre = f"select set_config('app.tenant_id','{guc}',false);" if guc else ""
    p = subprocess.run(["docker", "exec", "-i", "-e", f"PGPASSWORD={E['DB_APP_PASSWORD']}", "luminate-wellms-postgres-1", "psql", "-h", "127.0.0.1",
                        "-U", E["DB_APP_USER"], "-d", E.get("DB_NAME", "wellms"), "-At", "-q"], input=pre + sql, capture_output=True, text=True)
    lines = p.stdout.splitlines()
    return [l for l in (lines[1:] if guc else lines) if l]


# ---- 0. preconditions: it really is the pooler, in transaction mode -----------------------------------------------------------------
print("--- 0. is this really transaction pooling, and does the app really use it?")
cfg = {r["key"]: r["value"] for r in admin("SHOW CONFIG")[1]}
check("pool_mode is TRANSACTION", cfg.get("pool_mode") == "transaction", cfg.get("pool_mode"))
check(f"the pool is small ({POOL_SIZE} backends) so clients are forced to share", cfg.get("default_pool_size") == str(POOL_SIZE) and cfg.get("max_db_connections") == str(POOL_SIZE), f"{cfg.get('default_pool_size')}/{cfg.get('max_db_connections')}")
check("no DISCARD ALL between clients (the transaction-mode default: a session setting WOULD leak)", cfg.get("server_reset_query", "") == "", repr(cfg.get("server_reset_query")))
for svc in ("api", "worker", "scheduler"):
    rc, out, _ = sh(["docker", "exec", f"luminate-wellms-{svc}-1", "printenv", "DB_HOST"])
    check(f"{svc} connects through the pooler (DB_HOST=pgbouncer)", out == "pgbouncer", out)
for f in ("pooler_leak_probe.php", "pooler_statement_probe.php"):
    sh(["docker", "cp", str(PROBES / f), f"{API}:/tmp/{f}"])
admin("RECONNECT wellms")
time.sleep(1)
xact0 = int(stats().get("total_xact_count", 0))

# ---- A. which PDO mode survives a transaction-mode pooler (two findings) ----------------------------------------------------------------------
print("--- A. prepared statements vs a transaction-mode pooler")
rc, out, err = exec_php("pooler_leak_probe.php", "prepare-control")
via_pool = (last_json(out) or {}).get("matrix", {})
rc, out, err = exec_php("pooler_leak_probe.php", "prepare-control", env={"DB_HOST": "postgres", "DB_PORT": "5432"})
direct = (last_json(out) or {}).get("matrix", {})
DESTROY, INTBOOL = "destroyed statement, then another statement in the same transaction", "integer bound to a boolean column (what Laravel does for every bool)"
names = {"default": "PDO default (named server-side prepares)", "emulated": "emulated (client-side) prepares", "app": "PGSQL_ATTR_DISABLE_PREPARES (what the app uses)"}
for mode in ("default", "emulated", "app"):
    for case, result in via_pool.get(mode, {}).items():
        print(f"INFO  {names[mode]:50} | {case[:44]:44} | pooler: {result[:11]:11} direct: {direct.get(mode, {}).get(case, '?')[:11]}")
check("the mode the application uses passes ALL four cases through the pooler", bool(via_pool.get("app")) and all(v == "ok" for v in via_pool["app"].values()), str(via_pool.get("app")))
check("...and over a direct connection (it changes nothing about how queries behave)", bool(direct.get("app")) and all(v == "ok" for v in direct["app"].values()), str(direct.get("app")))
check("FINDING 1 reproduced: the PDO default aborts the transaction through the pooler when a statement is destroyed (pdo_pgsql sends DEALLOCATE)",
      via_pool.get("default", {}).get(DESTROY, "").startswith("FAILED") and direct.get("default", {}).get(DESTROY) == "ok", str(via_pool.get("default", {}).get(DESTROY)))
check("FINDING 2 reproduced: emulated prepares are NOT a valid fix (an integer bound to a boolean column fails, even over a direct connection)",
      via_pool.get("emulated", {}).get(INTBOOL, "").startswith("FAILED") and direct.get("emulated", {}).get(INTBOOL, "").startswith("FAILED"), str(via_pool.get("emulated", {}).get(INTBOOL)))
# ---- B. connection reuse across tenants (the SQL-level leak test) -------------------------------------------------------------------------
print("--- B. interleaved tenants over shared backends: no residue")
N, PROCS, CLIENTS = 2500, 4, 4
samples = {"cl_active": 0, "cl_waiting": 0, "busy_clients": 0, "servers": 0}
stop = threading.Event()


def sampler():
    while not stop.is_set():
        try:
            p = pool()
            ca, cw, sa, si, su = (int(p.get(k, 0)) for k in ("cl_active", "cl_waiting", "sv_active", "sv_idle", "sv_used"))
            samples["cl_active"] = max(samples["cl_active"], ca)
            samples["cl_waiting"] = max(samples["cl_waiting"], cw)
            samples["busy_clients"] = max(samples["busy_clients"], ca + cw)
            samples["servers"] = max(samples["servers"], sa + si + su)
        except Exception:
            pass
        time.sleep(0.2)


th = threading.Thread(target=sampler, daemon=True)
th.start()
t0 = time.time()
procs = [exec_php("pooler_leak_probe.php", N, CLIENTS, 100 + i, wait=False) for i in range(PROCS)]
outs = [p.communicate(timeout=600) for p in procs]
elapsed = time.time() - t0
stop.set()
th.join(timeout=3)
runs = [last_json(o[0]) for o in outs]
check("every probe process finished and reported", all(r and r.get("mode") == "interleave" for r in runs), str([o[1][-200:] for o in outs if not last_json(o[0])]))
runs = [r for r in runs if r]
total = sum(r["stat"]["transactions"] for r in runs)
viol = sum(r["violation_count"] for r in runs)
residual = sum(r["stat"]["pre_residual"] for r in runs)
pre_empty = sum(r["stat"]["pre_empty"] for r in runs)
tenantless_after_tenant = sum(r["stat"]["tenantless_after_tenant"] for r in runs)
tenant_after_other = sum(r["stat"]["tenant_after_other_tenant"] for r in runs)
pid_tenants = {}
for r in runs:
    for pid, per in r["pid_tenants"].items():
        for lab, n in per.items():
            pid_tenants.setdefault(pid, {})[lab] = pid_tenants.get(pid, {}).get(lab, 0) + n
print(f"INFO  {total} transactions by {PROCS * CLIENTS} client connections in {elapsed:.1f}s ({total / elapsed:.0f}/s) over {len(pid_tenants)} backend connection(s)")
print(f"INFO  backends -> tenants served: { {pid: sorted(per) for pid, per in pid_tenants.items()} }")
print(f"INFO  pooler during the run: max clients in a transaction or waiting = {samples['busy_clients']} (waiting {samples['cl_waiting']}), max server connections = {samples['servers']}")
check(f"ZERO violations over {total} interleaved transactions (no residual setting, no wrong setting, no other tenant's rows)", viol == 0, json.dumps([r["violations"][:3] for r in runs if r["violation_count"]])[:500])
check("no transaction EVER found a tenant setting left on its backend before setting its own", residual == 0, str(residual))
check("non-vacuous: backends really carried an expired setting from earlier use (SET LOCAL existed there and died with its transaction)", pre_empty > 0, str(pre_empty))
check(f"non-vacuous: {PROCS * CLIENTS} clients really shared at most {POOL_SIZE} backends", 0 < len(pid_tenants) <= POOL_SIZE, str(len(pid_tenants)))
check("non-vacuous: every backend served several different tenants, including tenant-LESS transactions, in turn",
      all(len(per) >= 3 and "NONE" in per for per in pid_tenants.values()), str({pid: sorted(per) for pid, per in pid_tenants.items()}))
check("non-vacuous: a tenant-less transaction followed a tenant's on the same backend, and one tenant followed another's, many times",
      tenantless_after_tenant >= 50 and tenant_after_other >= 50, f"tenantless-after-tenant={tenantless_after_tenant} tenant-after-other={tenant_after_other}")
check("non-vacuous: transactions genuinely overlapped (several clients active or queued for a backend at once)", samples["busy_clients"] >= 3, str(samples))
check(f"the pool never exceeded {POOL_SIZE} server connections", samples["servers"] <= POOL_SIZE, str(samples))

# ---- C. the control: a session-level setting MUST leak, or the test above is blind --------------------------------------------------------
print("--- C. control: the probe can see a leak")
rc, out, err = exec_php("pooler_leak_probe.php", "control")
ctl = last_json(out) or {}
check("a SESSION-level setting leaks to the next client on the same backend (so the isolation result above would have caught one)", ctl.get("leak_observed") is True, out + err[-200:])
admin("RECONNECT wellms")
time.sleep(1)
rc, out, err = exec_php("pooler_leak_probe.php", 400, 3, 999)
after = last_json(out) or {}
check("after cleanup the canary is gone and the interleaved probe is clean again", after.get("violation_count") == 0 and after.get("stat", {}).get("pre_residual") == 0, out[-300:])

# ---- D. statements outside an explicit transaction, through the REAL connection class -----------------------------------------------------
print("--- D. statements outside a transaction, each possibly on a different backend")
expect = {t: int(psql("select count(*) from users", guc=t)[0]) for t in ("t-alpha", "t-beta", "t-delta")}
print(f"INFO  users per tenant (measured over a direct connection): {expect}")
M = 1500
order = ["t-alpha", "t-beta", "t-delta", "t-alpha", "t-beta", "t-delta"]
t0 = time.time()
procs = [exec_php("pooler_statement_probe.php", M, json.dumps(expect), 500 + i, env={"LUMINATE_TENANT": t}, wait=False) for i, t in enumerate(order)]
outs = [p.communicate(timeout=600) for p in procs]
elapsed = time.time() - t0
runs = [last_json(o[0]) for o in outs]
check("every application-path process finished and reported", all(runs), str([o[1][-250:] for o in outs if not last_json(o[0])]))
runs = [r for r in runs if r]
viol = sum(r["violation_count"] for r in runs)
kinds = [sum(r["kinds"][k] for r in runs) for k in range(7)]
print(f"INFO  {sum(r['iterations'] for r in runs)} operations by 6 concurrent tenants in {elapsed:.1f}s; per kind {kinds}; distinct backends seen per process {[r['distinct_backends'] for r in runs]}")
check("ZERO violations through the real TenantPostgresConnection (statements outside a transaction, inside DB::transaction, Eloquent, bound parameters)", viol == 0, json.dumps([r["violations"][:3] for r in runs if r["violation_count"]])[:600])
check("every kind of operation was exercised", all(k > 100 for k in kinds), str(kinds))
check("statements outside a transaction really did land on DIFFERENT backends over time (the path under test)", max(r["distinct_backends"] for r in runs) >= 2, str([r["distinct_backends"] for r in runs]))
check("raw reads that BYPASS the wrapper never saw a lingering tenant setting, and saw ZERO tenant rows (fails closed)", viol == 0 and kinds[5] > 100 and kinds[6] > 100)

# ---- E. what the pooler did -------------------------------------------------------------------------------------------------------------------
s = stats()
xact = int(s.get("total_xact_count", 0)) - xact0
print(f"INFO  the pooler served {xact} transactions during this test over at most {POOL_SIZE} backend connections")
check("the pooler really carried the traffic (thousands of transactions)", xact > 10000, str(xact))

print(f"\n{sum(results)}/{len(results)} passed")
sys.exit(0 if all(results) else 1)
