"""Batch 6 - the release gate: a GENERATED cross-tenant isolation suite over every API operation.

    python -I luminate/tests/gate/isolation_gate.py [--attacker t-alpha --victim t-beta] [--only REGEX] [--limit N] [--no-seed]

Operations = the 439 in evidence/openapi.json UNION every route the application actually serves (the spec omits ~260: xAPI/Trax,
Horizon, OAuth, payment callbacks, /api/admin/hh5p, ...). For each operation, as the ATTACKER tenant's admin:

  V1  no tenant context                      -> must be refused 401 tenant_context_* before anything runs
  V2  attacker's bearer under VICTIM context -> must be refused 403 (token binding, contract D.5)
  V3  the attack: real VICTIM ids in the path, query and body, with the attacker's own valid context and token
        - nothing that exists only in the victim may appear in the response (values taken from the victim's rows via the OWNER role)
        - the victim's data must be byte-identical afterwards (fingerprint of every tenant table, owner role)
        - a 2xx that ACCEPTED a victim id in the body is reported (a cross-tenant reference)
  C   control (second phase): the same request as the VICTIM on its own ids. Proves the attack was not blocked merely because
        the id was wrong ("PROVEN" = control found the resource, attack did not).

The oracle is independent of the code under test: it reads the database as the table owner. Coverage is reported honestly:
every operation is PROVEN, BLOCKED (attack stopped, control inconclusive), LIST (no id to attack; leak scan only), SKIPPED (with
reason) or a VIOLATION. Exit code 1 on any violation.
"""
import argparse
import json
import pathlib
import random
import re
import subprocess
import sys
import time
from collections import Counter, defaultdict

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
import gatelib as g  # noqa: E402
import seed_recipes  # noqa: E402

LUM = g.LUMINATE
INFRA = LUM / "infra"
OPENAPI = LUM.parents[1] / "evidence" / "openapi.json"

ap = argparse.ArgumentParser()
ap.add_argument("--attacker", default="t-alpha")
ap.add_argument("--victim", default="t-beta")
ap.add_argument("--only", default="")
ap.add_argument("--limit", type=int, default=0)
ap.add_argument("--no-seed", action="store_true")
ap.add_argument("--seed-only", action="store_true")
ap.add_argument("--no-controls", action="store_true")
ap.add_argument("--out", default=str(pathlib.Path(__file__).resolve().parent / "report"))
args = ap.parse_args()
A, B = args.attacker, args.victim
RUN = time.strftime("%m%d%H%M")
rng = random.Random(RUN)
# paths the DEV override serves without a tenant context (Swagger, oauth callback); production refuses them (prod_posture.py proves it)
DEV_EXEMPT = [x.strip() for x in (subprocess.run(["docker", "exec", g.API, "printenv", "LUMINATE_TENANCY_EXEMPT_PATHS"], capture_output=True, text=True).stdout or "").split(",") if x.strip()]
SELF_AUTHED = re.compile(r"^/(docs(/|$)|api/documentation|api/luminate/registry)")
NO_SEED = re.compile(r"auth/|logout|password|impersonate|import|registry|oauth|payment|cart|checkout|webhook|register|login|reset|verify|activate|delete|destroy|refresh|horizon")


def sh(a, **kw):
    return subprocess.run(a, capture_output=True, text=True, **kw)


def log(*a):
    print(*a, flush=True)


# ---- inventory ------------------------------------------------------------------------------------------------------------------
routes = sh(["docker", "exec", "-u", "www-data", g.API, "php", "artisan", "tinker", "--execute=foreach (app('router')->getRoutes() as $r) echo implode('|',$r->methods()).' '.$r->uri().' '.$r->getActionName().\"\\n\";"]).stdout
ops, comp = g.load_ops(OPENAPI, routes)
spec_total = sum(1 for o in ops if o.in_spec)
log(f"operations: {len(ops)} ({spec_total} in the OpenAPI spec; {sum(1 for o in ops if o.in_spec and not o.routed)} of those have no route; {sum(1 for o in ops if not o.in_spec)} routed but not in the spec)")
tables = g.tenant_tables()

state = {"tok": {}}


def token(tid, force=False):
    if force or tid not in state["tok"]:
        state["tok"][tid] = g.login(tid)
    return state["tok"][tid]


def token_ok(tid):
    r = g.http_call("GET", "/api/profile/me", tid=tid, bearer=state["tok"].get(tid))
    if r.status != 200:
        token(tid, True)


# ---- seeding ----------------------------------------------------------------------------------------------------------------------
def pools(tid):
    return g.id_pools(tables, tid)


SEED_FAIL = {}


def seed(tid, rounds=3):
    """Create what the API lets an admin create, schema-driven, with markers. Round N can use the ids made by round N-1."""
    created = Counter()
    for rnd in range(rounds):
        ids = g.Ids(pools(tid), tables)
        for op in ops:
            if op.method != "POST" or op.params or not op.spec or NO_SEED.search(op.template) or not op.routed:
                continue
            kind, body = g.body_for(op, comp, ids, rng, tag=f"GM{tid.replace('-', '')}{RUN}")
            if kind is None:
                continue
            tokn = token(tid)
            kw = {"body": body} if kind == "json" else {"multipart": body}
            r = g.http_call("POST", op.template, tid=tid, bearer=tokn, **kw)
            if r.status in (200, 201):
                created[op.key] += 1
            elif rnd == rounds - 1:
                SEED_FAIL[op.key] = f"{r.status} {r.text[:160]}".replace(chr(10), " ")
            if r.status == 401:
                token(tid, True)
        log(f"  seed {tid} round {rnd + 1}: {sum(created.values())} created so far, {len(pools(tid))} tables with rows")
    return created


if not args.no_seed:
    sh(["docker", "compose", "stop", "scheduler"], cwd=INFRA)
    log("seeding both tenants through the API (recipes first: the victim gets 3 of each kind, the attacker 1, so the victim owns ids the attacker lacks)")
    for t, n in ((B, 3), (A, 1)):
        token(t)
        made = seed_recipes.run(t, lambda t=t: token(t), f"GM{t.replace('-', '')}{RUN}", n, log)
        log(f"  recipes {t}: {sum(len(v) for v in made.values())} objects of {len(made)} kinds")
        seed(t, rounds=2)
else:
    sh(["docker", "compose", "stop", "scheduler"], cwd=INFRA)

token(A)
token(B)
time.sleep(2)
if args.seed_only:
    for k, v in sorted(SEED_FAIL.items()):
        log(f"SEEDFAIL {k}: {v}")
    sh(["docker", "compose", "start", "scheduler"], cwd=INFRA)
    sys.exit(0)

# ---- oracle material (owner role) ---------------------------------------------------------------------------------------------------
log("collecting the victim's unique values and fingerprints (owner role)")
vals_b = g.text_values(tables, B)
vals_a = g.text_values(tables, A)
# PHP class names (morph types such as EscolaLms\Courses\Models\Course) are code constants every deployment serves (e.g. GET /api/admin/topics/types lists them),
# not tenant data: one tenant simply happens to have rows of a type the other has not used yet. They cannot carry a leak, so they are not "victim-only".
CODE_CONSTANT = re.compile(r"^[A-Za-z_][A-Za-z0-9_]*(?:\\[A-Za-z_][A-Za-z0-9_]*)+$")
UNIQUE = {v: src for v, src in vals_b.items() if v not in vals_a and not CODE_CONSTANT.match(v)}
MARKERS = [B + ".test", f"GM{B.replace('-', '')}"]
log(f"  {len(UNIQUE)} values exist only in {B}; {len(vals_a)} in {A}")
POOL_B, POOL_A = pools(B), pools(A)
# an id that also exists in the attacker's same table cannot prove anything: the attacker may legitimately own it
for t, ids_ in list(POOL_B.items()):
    a_ids = set(POOL_A.get(t, []))
    POOL_B[t] = [i for i in ids_ if i not in a_ids]
IDS_B = g.Ids(POOL_B, tables)
IDS_A = g.Ids(POOL_A, tables)


def course_topic_pairs(tid):
    """(course, topic) pairs that belong together, file topics first: a path with both parameters must address ONE real topic or it proves nothing."""
    rows = g.psql(f"select l.course_id || '|' || t.id from topics t join lessons l on l.id = t.lesson_id and l.tenant_id = t.tenant_id where t.tenant_id = '{tid}' "
                  f"order by (t.topicable_type like '%PDF') desc, t.id limit 12").split()
    return [tuple(r.split("|")) for r in rows if r]


IDS_B.pairs = course_topic_pairs(B)
IDS_A.pairs = course_topic_pairs(A)
log(f"  victim rows by table (tables with data): {len([t for t in POOL_B if POOL_B[t]])}; attacker: {len([t for t in POOL_A if POOL_A[t]])}")

fp0 = g.fingerprint(tables, B)
time.sleep(3)
fp1 = g.fingerprint(tables, B)
time.sleep(3)
fp2 = g.fingerprint(tables, B)
VOLATILE = {t for t in fp0 if not (fp0[t] == fp1[t] == fp2[t])}
log(f"  tables that change on their own (excluded from the write check): {sorted(VOLATILE) or 'none'}")
BASE_FP = {"v": fp2}


def changed(before, after):
    return sorted(t for t in after if t not in VOLATILE and before.get(t) != after.get(t))


# ---- the attack ------------------------------------------------------------------------------------------------------------------------
results = []
order = {"GET": 0, "POST": 1, "PUT": 2, "PATCH": 2, "DELETE": 3}
todo = [o for o in sorted(ops, key=lambda o: (order[o.method], o.template)) if not args.only or re.search(args.only, o.key)]
if args.limit:
    todo = todo[: args.limit]


def build(op, ids, tag):
    path, used = g.concrete(op, ids, rng)
    ids.used = []
    q = g.query_params(op, comp, ids, rng)
    kind, body = g.body_for(op, comp, ids, rng, tag=tag)
    return path, used, q, kind, body, list(ids.used)


def send(op, tid, bearer, ctx_token, path, q, kind, body, use_ctx=True):
    kw = {}
    if kind == "json":
        kw["body"] = body
    elif kind == "multipart":
        kw["multipart"] = body
    if not use_ctx:
        return g.http_call(op.method, path, query=q, bearer=bearer, **kw)
    return g.http_call(op.method, path, query=q, tid=None if ctx_token else tid, ctx_token=ctx_token, bearer=bearer, **kw)


def sent_strings(path, q, body, kind):
    out = set(re.split(r"[/?&=]", path)) | {str(v) for v in (q or {}).values()}
    if kind == "json":
        g.strings_in(body, out)
    elif kind == "multipart":
        out |= {str(v) for _, v in body if isinstance(v, str)}
    return out


def attack(op):
    row = {"key": op.key, "method": op.method, "path": op.template, "in_spec": op.in_spec, "routed": op.routed, "action": op.action}
    if SELF_AUTHED.search(op.template):
        row.update(outcome="SKIPPED", reason="authenticates itself (docs / registry sync: covered by registry_sync.py)")
        return row
    token_ok(A)
    bearer = state["tok"][A]
    IDS_B.used = []
    path, used, q, kind, body, ids_used = build(op, IDS_B, f"ATK{RUN}")
    row["id_source"] = [(p, t) for p, t, v in used]
    row["has_victim_id"] = bool(ids_used) or any(t for _, t, _ in used)
    row["weak_ids"] = [p for p, t, v in used if t is None]

    # V1: no tenant context
    r1 = send(op, A, bearer, None, path, q, kind, body, use_ctx=False)
    row["v1"] = r1.status
    exempt = op.method == "GET" and any(re.fullmatch(re.escape(x).replace(r"\*", ".*"), op.template.strip("/")) for x in DEV_EXEMPT)
    row["dev_exempt"] = exempt
    row["v1_ok"] = exempt or (r1.status == 401 and str(r1.code or "").startswith("tenant_context"))
    # V2: attacker's bearer, victim's context
    r2 = send(op, B, bearer, None, path, q, kind, body)
    row["v2"] = r2.status
    row["v2_ok"] = exempt or r2.status == 403
    # V3: the attack
    r3 = send(op, A, bearer, None, path, q, kind, body)
    row["v3"], row["v3_ms"] = r3.status, r3.ms
    ignore = sent_strings(path, q, body, kind)
    leaks = g.leak_scan(r3, UNIQUE, MARKERS, ignore)
    row["leaks"] = dict(list(leaks.items())[:5])
    # anything the V1/V2 variants might have changed is attributed here too
    after = g.fingerprint(tables, B) if op.mutating or r3.status in (200, 201, 202, 204) and op.method != "GET" else None
    row["b_changed"] = changed(BASE_FP["v"], after) if after else []
    if after:
        BASE_FP["v"] = after
    row["foreign_ref_accepted"] = bool(op.mutating and 200 <= r3.status < 300 and [x for x in ids_used if x[0] != "path"])
    row["server_error"] = r3.status >= 500 or r3.status == 0
    if row["server_error"] and row["has_victim_id"]:
        # an id that exists nowhere must fail the same way, or the difference would tell the attacker the victim's id exists
        # same SHAPE as a real id (a uuid column rejects an integer with a different error, which would fake a difference)
        import uuid as _uuid
        ghost = g.Ids({tb: [str(_uuid.uuid4()) if (POOL_B.get(tb) and re.fullmatch(r"[0-9a-f-]{36}", str(POOL_B[tb][0]))) else "999999999"] for tb in tables}, tables)
        gp, _ = g.concrete(op, ghost, rng)
        rg = send(op, A, bearer, None, gp, q, kind, body)
        row["ghost_status"] = rg.status
    row["v3_snippet"] = r3.text[:120].replace("\n", " ") if (leaks or row["b_changed"] or row["server_error"]) else ""
    return row


def victim_admin_alive():
    return g.psql(f"select count(*) from users where tenant_id = '{B}' and email = '{g.ADMIN}' and deleted_at is null").strip() == "1"


def restore_admins(tid=None):
    """An operation run AS a tenant's own admin may legitimately delete that admin (delete-my-account). Put it back so the rest of the
    run is not vacuous. Attack phase: only the ATTACKER's admin, after the victim's fingerprint was compared. Control phase: the victim's."""
    g.psql(f"update users set email = split_part(email, '+deleted', 1), deleted_at = null where tenant_id = '{tid or A}' and email like '{g.ADMIN}+deleted%'")


log(f"attack phase: {len(todo)} operations")
t0 = time.time()
try:
    for i, op in enumerate(todo, 1):
        row = attack(op)
        results.append(row)
        if row.get("leaks") or row.get("b_changed") or row.get("v1_ok") is False or row.get("v2_ok") is False:
            log(f"  !! {op.key}: v1={row.get('v1')} v2={row.get('v2')} v3={row.get('v3')} leaks={list(row.get('leaks', {}).values())[:3]} b_changed={row.get('b_changed')} ids={row.get('id_source')} {row.get('v3_snippet', '')}")
        if not victim_admin_alive():
            row["b_changed"] = sorted(set(row.get("b_changed", [])) | {"users(victim admin deleted)"})
            log(f"  !! VICTIM ADMIN GONE after {op.key} (v3={row.get('v3')}) {row.get('v3_snippet', '')}")
            g.psql(f"update users set email = split_part(email, '+deleted', 1), deleted_at = null where tenant_id = '{B}' and email like '{g.ADMIN}+deleted%'")
        restore_admins()
        if i % 25 == 0:
            bad = sum(1 for r in results if r.get("leaks") or r.get("b_changed") or r.get("v1_ok") is False or r.get("v2_ok") is False)
            log(f"  {i}/{len(todo)}  ({int(time.time() - t0)}s)  violations so far: {bad}")
except KeyboardInterrupt:
    log("interrupted")

def write_report():
    pass


json.dump({"attacker": A, "victim": B, "run": RUN, "phase": "attack-only", "results": results}, open(pathlib.Path(args.out) / f"{A}_to_{B}.attack.json", "w") if pathlib.Path(args.out).mkdir(exist_ok=True) is None else None, indent=1, default=list)

# ---- controls (phase 2): the same request as the victim, on its own ids -----------------------------------------------------------------
if not args.no_controls:
    log("control phase: the same requests as the victim on its own data")
    POOL_B2 = {k: v for k, v in pools(B).items()}
    IDS_C = g.Ids(POOL_B2, tables)
    IDS_C.pairs = course_topic_pairs(B)
    btok = token(B, True)
    byk = {r["key"]: r for r in results}
    for i, op in enumerate(todo, 1):
        row = byk.get(op.key)
        if not row or row.get("outcome") == "SKIPPED":
            continue
        if op.method == "DELETE" and i % 10 == 0:
            IDS_C.pools = pools(B)
        IDS_C.used = []
        path, used = g.concrete(op, IDS_C, rng)
        q = g.query_params(op, comp, IDS_C, rng)
        kind, body = g.body_for(op, comp, IDS_C, rng, tag=f"CTL{RUN}")
        rc = send(op, B, btok, None, path, q, kind, body)
        restore_admins(B)           # the victim acting on itself may delete its own admin; that is not an isolation event
        if rc.status == 401:
            btok = token(B, True)
            rc = send(op, B, btok, None, path, q, kind, body)
            restore_admins(B)
        row["control"] = rc.status
        row["control_found"] = 200 <= rc.status < 300

# ---- verdicts ---------------------------------------------------------------------------------------------------------------------------
for r in results:
    if r.get("outcome") == "SKIPPED":
        continue
    viol = []
    if not r["v1_ok"]:
        viol.append(f"V1 no-context answered {r['v1']} (want 401 tenant_context_*)")
    if not r["v2_ok"]:
        viol.append(f"V2 token-binding answered {r['v2']} (want 403)")
    if r["leaks"]:
        viol.append(f"V3 response contained victim-only values: {list(r['leaks'].values())[:3]}")
    if r["b_changed"]:
        viol.append(f"V3 victim data changed in {r['b_changed']}")
    r["violations"] = viol
    if not r.get("routed"):
        r["outcome"], r["reason"] = ("NO-ROUTE", "in the spec but the application serves no such route (the contract's wrong-paths list)")
    elif viol:
        r["outcome"] = "VIOLATION"
    elif r["server_error"] and r.get("control", 0) >= 500:
        r["outcome"], r["reason"] = "STOCK-DEFECT", "5xx for the victim's own request too: broken in stock Wellms, independent of tenancy"
    elif r["server_error"] and r.get("ghost_status") == r["v3"]:
        r["outcome"], r["reason"] = "NOT-FOUND-500", "unhandled not-found in stock Wellms: a nonexistent id fails identically, so it reveals nothing"
    elif r["server_error"]:
        r["outcome"] = "ERROR5XX"
    elif r["has_victim_id"] and r.get("control_found"):
        r["outcome"] = "PROVEN"
    elif r["has_victim_id"]:
        r["outcome"] = "BLOCKED"
    else:
        r["outcome"] = "LIST"

by = Counter(r["outcome"] for r in results)
refs = [r for r in results if r.get("foreign_ref_accepted")]
out = pathlib.Path(args.out)
out.mkdir(exist_ok=True)
name = f"{A}_to_{B}"
json.dump({"attacker": A, "victim": B, "run": RUN, "summary": by, "results": results}, open(out / f"{name}.json", "w"), indent=1, default=list)

md = [f"# Isolation gate: {A} attacking {B}  (run {RUN})", "",
      f"Operations: {len(results)} ({spec_total} in the OpenAPI spec; the rest are routes the spec omits).", "",
      "| outcome | count |", "|---|---|"] + [f"| {k} | {v} |" for k, v in sorted(by.items())]
md += ["", "PROVEN = the victim's control request found the resource and the attack did not. BLOCKED = attack stopped, control inconclusive. "
       "LIST = no id to attack (leak scan only). NO-ROUTE = spec operation the application does not serve.", ""]
for title, sel in (("Violations", lambda r: r["outcome"] == "VIOLATION"), ("Server errors on the attack (5xx / no answer)", lambda r: r["outcome"] == "ERROR5XX"),
                   ("2xx that accepted a victim id in the body (cross-tenant reference)", lambda r: r.get("foreign_ref_accepted"))):
    rows = [r for r in results if sel(r)]
    md += [f"## {title} ({len(rows)})", ""]
    for r in rows:
        md.append(f"- `{r['key']}` -> {r['v3']}  {'; '.join(r.get('violations', []))} {r.get('v3_snippet', '')}")
    md.append("")
md += ["## Not proven (BLOCKED / LIST): what a human should know", ""]
for r in results:
    if r["outcome"] in ("BLOCKED", "LIST"):
        md.append(f"- {r['outcome']} `{r['key']}` attack {r['v3']} control {r.get('control')}  ids: {r.get('id_source')}")
md.append("")
(out / f"{name}.md").write_text("\n".join(md), encoding="utf-8")

log("\n" + "\n".join(f"{k:10} {v}" for k, v in sorted(by.items())))
log(f"2xx that accepted a victim id in the body: {len(refs)}")
sh(["docker", "compose", "start", "scheduler"], cwd=INFRA)
viol = [r for r in results if r["outcome"] == "VIOLATION"]
for r in viol[:40]:
    log("VIOLATION", r["key"], r["violations"])
log(f"report: {out / (name + '.md')}")
sys.exit(1 if viol else 0)
