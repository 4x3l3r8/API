"""Batch 4b: database-level tenant isolation checks against the LIVE stack, as the real owner and app roles.

    python -I luminate/tests/sql/rls_checks.py

Proves (not assumes): the app role cannot bypass RLS or do DDL; every tenant-owned table is enrolled; reads/writes are
confined to the transaction-local tenant; the DDL event trigger enrols tables created later; unique keys are composite.
"""
import pathlib
import subprocess
import sys

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[2] / "tools"))
import context_token as ct  # noqa: E402

E = ct.read_env()
DB = E.get("DB_NAME", "wellms")
APP, OWNER = E["DB_APP_USER"], E["DB_OWNER_USER"]
PW = {APP: E["DB_APP_PASSWORD"], OWNER: E["DB_OWNER_PASSWORD"]}
CONTAINER = "luminate-wellms-postgres-1"
results = []


def psql(role, sql):
    """Run a script in ONE session as `role`; return (rc, stdout, stderr)."""
    p = subprocess.run(
        ["docker", "exec", "-i", "-e", f"PGPASSWORD={PW[role]}", CONTAINER,
         "psql", "-h", "127.0.0.1", "-U", role, "-d", DB, "-At", "-F", "|", "-q", "-v", "ON_ERROR_STOP=1"],
        input=sql, capture_output=True, text=True)
    return p.returncode, p.stdout.strip(), p.stderr.strip()


def scalar(role, sql):
    rc, out, err = psql(role, sql)
    if rc != 0:
        raise RuntimeError(err)
    return out.splitlines()[-1] if out else ""


def check(name, ok, detail=""):
    results.append(ok)
    print(f"{'PASS' if ok else 'FAIL'}  {name}" + (f"  [{detail}]" if detail and not ok else ""))


def tenant(t):
    return f"select set_config('app.tenant_id', '{t}', false);"


# --- roles -------------------------------------------------------------------------------------------------------
for role in (APP, OWNER):
    flags = scalar(role, f"select rolsuper::text||','||rolbypassrls::text from pg_roles where rolname='{role}'")
    check(f"role {role} is not superuser / BYPASSRLS", flags == "false,false", flags)

# --- coverage ----------------------------------------------------------------------------------------------------
drift = psql(OWNER, "select kind||':'||object from luminate_tenancy_drift() where kind <> 'null_tenant_rows'")[1]
check("no drift: every tenant-owned table has tenant_id + RLS + policy + default; all uniques composite", drift == "", drift[:300])
# Rows that Wellms migrations insert with no tenant are invisible to every tenant. Provisioning recreates them per tenant
# (Passport clients, roles/permissions via the seeder, questionnaire types via config). Any OTHER table showing up here
# is new migration-seeded data from an upgrade that no tenant can see: fail so it gets handled.
EXPECTED_NULL_ROWS = {"roles", "permissions", "role_has_permissions", "oauth_clients", "oauth_personal_access_clients", "questionnaire_model_types"}
nulls = {l.split("=")[0] for l in psql(OWNER, "select object||'='||detail from luminate_tenancy_drift() where kind = 'null_tenant_rows'")[1].splitlines() if l}
check("NULL-tenant rows exist only in the known migration-seeded tables", nulls <= EXPECTED_NULL_ROWS, str(sorted(nulls - EXPECTED_NULL_ROWS)))
print(f"INFO  tables with NULL-tenant (template) rows: {sorted(nulls)}")
for t in ("t-alpha", "t-beta"):
    miss = []
    for table, key in (("questionnaire_model_types", "model_class"), ("oauth_clients", "name")):
        missing = scalar(OWNER, f"select count(*)::text from (select {key} from {table} where tenant_id is null "
                                f"except select {key} from {table} where tenant_id = '{t}') m")
        if missing != "0":
            miss.append(f"{table}: {missing} template item(s) missing for {t}")
    check(f"{t}: has its own copy of every migration-seeded {'/'.join(['questionnaire type', 'Passport client'])}", not miss, "; ".join(miss))
total, exempt = scalar(OWNER, "select count(*)::text||','||count(*) filter (where luminate_is_exempt(relname))::text from pg_class c join pg_namespace n on n.oid=c.relnamespace where n.nspname='public' and c.relkind in ('r','p')").split(",")
enrolled = scalar(OWNER, "select count(*)::text from pg_class c join pg_namespace n on n.oid=c.relnamespace join pg_policy p on p.polrelid=c.oid and p.polname='tenant_isolation' where n.nspname='public' and c.relrowsecurity")
check(f"all {int(total) - int(exempt)} non-exempt tables are enrolled ({total} tables, {exempt} exempt)", int(enrolled) == int(total) - int(exempt), f"enrolled={enrolled}")
check("DDL event trigger is installed and enabled", scalar(OWNER, "select count(*)::text from pg_event_trigger where evtname='luminate_tenancy_ddl' and evtenabled<>'D'") == "1")
composite = scalar(OWNER, "select count(*)::text from luminate_tenancy_audit where kind='unique_rewritten'")
print(f"INFO  unique keys rewritten to (tenant_id, ...): {composite}")

# --- app role cannot escape ------------------------------------------------------------------------------------
for label, sql in [("create a table", "create table zz_app_probe(id int)"),
                   ("disable RLS on users", "alter table users disable row level security"),
                   ("drop the policy", "drop policy tenant_isolation on users"),
                   ("force-set its own bypass", "alter role current_user bypassrls"),
                   ("drop the event trigger", "drop event trigger luminate_tenancy_ddl")]:
    rc, _, err = psql(APP, sql)
    check(f"app role cannot {label}", rc != 0 and ("permission denied" in err or "must be" in err or "owner" in err), err[:120])

# --- tenant confinement ----------------------------------------------------------------------------------------
tids = [t for t in ("t-alpha", "t-beta")]
for t in tids:
    users = scalar(APP, tenant(t) + "select count(*) from users")
    roles = scalar(APP, tenant(t) + "select count(*) from roles")
    check(f"{t}: sees its own provisioned admin and roles", users == "1" and int(roles) > 0, f"users={users} roles={roles}")
check("no tenant set: app role sees ZERO users / roles / oauth clients",
      scalar(APP, "select (select count(*) from users)+(select count(*) from roles)+(select count(*) from oauth_clients)") == "0")
check("empty-string tenant also sees nothing", scalar(APP, tenant("") + "select count(*) from users") == "0")
check("t-alpha cannot read t-beta's rows by asking for them",
      scalar(APP, tenant("t-alpha") + "select count(*) from users where tenant_id='t-beta'") == "0")
rc, _, err = psql(APP, tenant("t-alpha") + "insert into users(first_name,last_name,email,password,tenant_id) values('x','x','forge@x.test','x','t-beta')")
check("t-alpha cannot insert a row for t-beta (RLS WITH CHECK)", rc != 0 and "row-level security" in err, err[:120])
rc, _, err = psql(APP, tenant("t-alpha") + "update users set tenant_id='t-beta'")
check("t-alpha cannot move its rows to t-beta", rc != 0 and "row-level security" in err, err[:120])
rc, _, err = psql(APP, "insert into users(first_name,last_name,email,password) values('n','n','n@x.test','x')")
check("insert with no tenant is refused", rc != 0 and "row-level security" in err, err[:120])
stamped = scalar(APP, "begin;" + "select set_config('app.tenant_id','t-alpha',true);"
                 "insert into users(first_name,last_name,email,password) values('s','s','stamp@x.test','x');"
                 "select tenant_id from users where email='stamp@x.test'; rollback;")
check("tenant_id is stamped from the transaction-local setting by the column default", stamped == "t-alpha", stamped)
leak = scalar(APP, "begin; select set_config('app.tenant_id','t-alpha',true); commit; select coalesce(nullif(current_setting('app.tenant_id', true), ''), 'UNSET')")
check("SET LOCAL does not outlive its transaction (PgBouncer-safe)", leak == "UNSET", repr(leak))

# --- no cross-tenant links (e.g. a poisoned permission cache assigning another tenant's role) --------------------------
bad_roles = scalar(OWNER, "select count(*) from model_has_roles m join roles r on r.id = m.role_id where m.tenant_id is distinct from r.tenant_id")
bad_perms = scalar(OWNER, "select count(*) from role_has_permissions rp join roles r on r.id = rp.role_id join permissions p on p.id = rp.permission_id "
                          "where rp.tenant_id is distinct from r.tenant_id or rp.tenant_id is distinct from p.tenant_id")
check("no role assignment points at another tenant's role", bad_roles == "0", bad_roles)
check("no role-permission link crosses tenants", bad_perms == "0", bad_perms)

# --- composite uniques -----------------------------------------------------------------------------------------
same = scalar(OWNER, "select count(*) from (select email from users group by email having count(distinct tenant_id) > 1) s")
check("the same e-mail exists in two tenants (users unique is (tenant_id, email))", int(same) >= 1, same)
rc, _, err = psql(APP, tenant("t-alpha") + "insert into users(first_name,last_name,email,password) select first_name,last_name,email,password from users limit 1")
check("the same e-mail twice in ONE tenant is still rejected", rc != 0 and "unique" in err, err[:120])

# --- event trigger: a table created by a future upgrade is enrolled automatically --------------------------------
psql(OWNER, "drop table if exists zz_future_table")
rc, _, err = psql(OWNER, "create table zz_future_table(id serial primary key, code text, constraint zz_code unique (code))")
check("owner can create a table (as a migration would)", rc == 0, err)
row = scalar(OWNER, "select relrowsecurity::text from pg_class where relname='zz_future_table'")
col = scalar(OWNER, "select column_default from information_schema.columns where table_name='zz_future_table' and column_name='tenant_id'")
pol = scalar(OWNER, "select count(*)::text from pg_policies where tablename='zz_future_table' and policyname='tenant_isolation'")
check("future table: tenant_id + default + RLS + policy added by the event trigger", row == "true" and "luminate_tenant_id" in col and pol == "1", f"rls={row} default={col} policy={pol}")
aud = scalar(OWNER, "select count(*)::text from luminate_tenancy_audit where kind='unique_without_tenant' and object like '%zz_code%'")
drift_now = psql(OWNER, "select kind||':'||object from luminate_tenancy_drift() where object like 'zz_future_table%'")[1]
check("future table: its non-composite unique key is recorded and shows up as drift",
      aud == "1" and drift_now == "unique_without_tenant:zz_future_table.zz_code", f"audit={aud} drift={drift_now}")
psql(OWNER, "drop table if exists zz_future_table")
check("probe table cleaned up", scalar(OWNER, "select count(*)::text from pg_class where relname='zz_future_table'") == "0")

print(f"\n{sum(results)}/{len(results)} passed")
sys.exit(0 if all(results) else 1)
