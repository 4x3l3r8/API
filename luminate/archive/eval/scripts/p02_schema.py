"""Pooled follow-up, test 2: schema census on the migrated shared DB (read-only SQL)."""
import subprocess, json, collections

DC = "docker compose -f api/docker-compose.yml -f docker-compose.override.yml -f docker-compose.tenants.yml --project-directory api -p wellms".split()


def q(sql):
    r = subprocess.run(DC + ["exec", "-T", "postgres", "psql", "-U", "default", "-d", "pooled", "-At", "-F", "|", "-c", sql], capture_output=True, text=True)
    return [l.split("|") for l in r.stdout.strip().split("\n") if l]


tables = [r[0] for r in q("select table_name from information_schema.tables where table_schema='public' and table_type='BASE TABLE' order by 1")]
print("base tables:", len(tables))
cols = collections.defaultdict(list)
for t, c in q("select table_name, column_name from information_schema.columns where table_schema='public'"):
    cols[t].append(c)
fks = q("""select tc.table_name, ccu.table_name from information_schema.table_constraints tc
 join information_schema.constraint_column_usage ccu on ccu.constraint_name=tc.constraint_name
 where tc.constraint_type='FOREIGN KEY' and tc.table_schema='public'""")
fkto = collections.defaultdict(set)
for a, b in fks:
    fkto[a].add(b)
print("tables with at least one FK:", len(fkto), "| FK constraints:", len(fks))

FRAMEWORK = {"migrations", "failed_jobs", "jobs", "job_batches", "password_resets", "password_reset_tokens", "sessions", "cache", "cache_locks", "personal_access_tokens", "telescope_entries", "horizon_jobs"}
PASSPORT = {t for t in tables if t.startswith("oauth_")}
TRAX = {t for t in tables if t.startswith("trax_")}
H5P = {t for t in tables if t.startswith("h5p_") or t.startswith("hh5p")}
SPATIE = {"roles", "permissions", "model_has_roles", "model_has_permissions", "role_has_permissions"}
# Anchors that are inherently tenant-owned in a pooled model
ANCHORS = {"users", "courses", "groups", "lessons", "topics"}
# closure: a table is "tenant-owned by reachability" if it has an FK path to an anchor
owned = set(ANCHORS & set(tables))
changed = True
while changed:
    changed = False
    for t in tables:
        if t not in owned and (fkto[t] & owned):
            owned.add(t); changed = True
direct_users = {t for t in tables if "user_id" in cols[t] or "author_id" in cols[t] or "created_by" in cols[t]}
rest = [t for t in tables if t not in owned and t not in FRAMEWORK and t not in PASSPORT and t not in TRAX and t not in SPATIE]
print("\nClassification of", len(tables), "tables")
print("  reachable by FK from users/courses/groups/lessons/topics (tenant-owned by structure):", len(owned & set(tables)))
print("  of which have no FK path but a user_id/author_id/created_by column:", len((direct_users - owned) & set(rest)))
print("  Laravel framework tables:", len(FRAMEWORK & set(tables)), sorted(FRAMEWORK & set(tables)))
print("  Passport oauth_*:", len(PASSPORT), sorted(PASSPORT))
print("  Spatie roles/permissions:", len(SPATIE & set(tables)), sorted(SPATIE & set(tables)))
print("  TRAX (xAPI LRS) trax_*:", len(TRAX))
print("  H5P tables:", len(H5P), sorted(H5P))
other = [t for t in rest if t not in owned]
print("  remaining (neither FK-reachable nor framework/passport/spatie/trax): ", len(other))
print("   ", other)

print("\n== UNIQUE constraints/indexes that would collide across tenants in one DB")
uq = q("""select t.relname, i.relname, array_to_string(array(select a.attname from pg_attribute a where a.attrelid=t.oid and a.attnum = any(ix.indkey) order by a.attnum),',')
 from pg_index ix join pg_class t on t.oid=ix.indrelid join pg_class i on i.oid=ix.indexrelid join pg_namespace n on n.oid=t.relnamespace
 where n.nspname='public' and ix.indisunique and not ix.indisprimary order by 1""")
print("unique (non-primary) indexes:", len(uq), "across", len({r[0] for r in uq}), "tables")
for t, i, cs in uq:
    if not any(k in cs for k in ("uuid", "token", "id,")) or t in ("users", "roles", "permissions", "categories", "tags", "pages", "settings", "model_fields_metadata", "groups"):
        pass
natural = [(t, cs) for t, i, cs in uq if "uuid" not in cs.split(",") and cs not in ("id",)]
print("unique indexes on natural keys (non-uuid):", len(natural))
for t, cs in natural:
    print(f"   {t}({cs})")
json.dump({"tables": tables, "owned": sorted(owned), "other": other, "unique_natural": natural}, open("evidence/p02_schema.json", "w"), indent=1)
