"""Support code for the generated cross-tenant isolation gate (Batch 6). No test logic here: HTTP, SQL, OpenAPI, id and body generation."""
import difflib
import http.client
import json
import os
import pathlib
import random
import re
import subprocess
import sys
import time
import uuid
from urllib.parse import quote, urlencode

HERE = pathlib.Path(__file__).resolve()
LUMINATE = HERE.parents[2]
sys.path.insert(0, str(LUMINATE / "tools"))
import context_token as ct  # noqa: E402

E = ct.read_env()
HOST = os.environ.get("LUMINATE_TEST_HOST", E["LAN_HOST"])
PORT = int(E.get("API_PORT", "8080"))
CTX_KEY = E["TENANT_CONTEXT_KEY"]
ADMIN, PASSWORD = E["INITIAL_USER_EMAIL"], E["INITIAL_USER_PASSWORD"]
DB = E.get("DB_NAME", "wellms")
API = "luminate-wellms-api-1"
METHODS = ("get", "post", "put", "patch", "delete")
TRACE = os.environ.get("GATE_TRACE")


# ---- HTTP ---------------------------------------------------------------------------------------------------------------
class Resp:
    def __init__(self, status, text, ms, headers=None):
        self.status, self.text, self.ms, self.headers = status, text, ms, headers or {}
        try:
            self.json = json.loads(text) if text and text.lstrip()[:1] in "[{" else None
        except Exception:
            self.json = None

    @property
    def code(self):
        return self.json.get("code") if isinstance(self.json, dict) else None


def http_call(method, path, query=None, body=None, headers=None, tid=None, bearer=None, ctx_token=None, multipart=None, form=None, timeout=40):
    h = {"Accept": "application/json"}
    if ctx_token is not None:
        h["X-Tenant-Context"] = ctx_token
    elif tid:
        h["X-Tenant-Context"] = ct.mint(CTX_KEY, tid)
    if bearer:
        h["Authorization"] = "Bearer " + bearer
    data = None
    if multipart is not None:
        boundary = "----gate" + uuid.uuid4().hex
        parts = []
        for k, v in multipart:
            if isinstance(v, tuple):  # (filename, bytes, content-type)
                parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"; filename="{v[0]}"\r\nContent-Type: {v[2]}\r\n\r\n'.encode() + v[1] + b"\r\n")
            else:
                parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode())
        data = b"".join(parts) + f"--{boundary}--\r\n".encode()
        h["Content-Type"] = f"multipart/form-data; boundary={boundary}"
    elif form is not None:
        data = urlencode(form).encode()
        h["Content-Type"] = "application/x-www-form-urlencoded"
    elif body is not None:
        data = json.dumps(body).encode()
        h["Content-Type"] = "application/json"
    h.update(headers or {})
    url = path + (("?" + urlencode(query, doseq=True)) if query else "")
    if TRACE:
        claim = ""
        if bearer:
            try:
                import base64
                pl = bearer.split(".")[1]
                claim = json.loads(base64.urlsafe_b64decode(pl + "=" * (-len(pl) % 4))).get("tenant", "?")
            except Exception:
                claim = "?"
        ctxt = ""
        if "X-Tenant-Context" in h:
            try:
                pl = h["X-Tenant-Context"].split(".")[1]
                ctxt = json.loads(base64.urlsafe_b64decode(pl + "=" * (-len(pl) % 4))).get("tid", "?")
            except Exception:
                ctxt = "?"
        open(TRACE, "a").write(f"{time.strftime('%H:%M:%S')} {method.upper()} {url} ctx={ctxt} bearer_tenant={claim}\n")
    t0 = time.time()
    try:
        c = http.client.HTTPConnection(HOST, PORT, timeout=timeout)
        c.request(method.upper(), url, body=data, headers=h)
        r = c.getresponse()
        raw = r.read().decode("utf-8", "replace")
        out = Resp(r.status, raw, int((time.time() - t0) * 1000), dict(r.getheaders()))
        c.close()
        return out
    except Exception as e:  # timeout, reset: reported, never swallowed silently
        return Resp(0, f"{type(e).__name__}: {e}", int((time.time() - t0) * 1000))


def login(tid, email=ADMIN, password=PASSWORD):
    r = http_call("POST", "/api/auth/login", tid=tid, body={"email": email, "password": password})
    if r.status != 200 or not isinstance(r.json, dict):
        raise RuntimeError(f"login {tid}: {r.status} {r.text[:150]}")
    return r.json["data"]["token"]


# ---- SQL (owner role: sees every tenant, so the oracle does not depend on the thing being tested) --------------------
def psql(sql, owner=True, timeout=300):
    user, pw = (E["DB_OWNER_USER"], E["DB_OWNER_PASSWORD"]) if owner else (E["DB_APP_USER"], E["DB_APP_PASSWORD"])
    p = subprocess.run(["docker", "exec", "-i", "-e", f"PGPASSWORD={pw}", "luminate-wellms-postgres-1", "psql", "-h", "127.0.0.1", "-U", user, "-d", DB, "-At", "-q", "-v", "ON_ERROR_STOP=1"],
                       input=sql, capture_output=True, text=True, timeout=timeout)
    if p.returncode != 0:
        raise RuntimeError(p.stderr[-400:])
    return p.stdout


def tenant_tables():
    out = psql("select c.table_name from information_schema.columns c join information_schema.tables t on t.table_name=c.table_name and t.table_schema=c.table_schema "
               "where c.table_schema='public' and c.column_name='tenant_id' and t.table_type='BASE TABLE' and c.table_name not like 'luminate\\_%registry%' and c.table_name<>'luminate_tenant_registry' order by 1")
    return [l for l in out.splitlines() if l]


def fingerprint(tables, tid):
    sql = " union all ".join(f"select '{t}', count(*)::text, md5(coalesce(string_agg(x::text, '|' order by x::text), '')) from \"{t}\" x where x.tenant_id = '{tid}'" for t in tables)
    return {l.split("|")[0]: tuple(l.split("|")[1:]) for l in psql(sql).splitlines() if l}


def text_values(tables, tid, min_len=8, max_len=120):
    """{value: 'table.column'} for every text-like column of the tenant's rows."""
    cols = psql("select table_name||'.'||column_name from information_schema.columns where table_schema='public' and column_name<>'tenant_id' and "
                "data_type in ('text','character varying','uuid','citext') and table_name = any(string_to_array('" + ",".join(tables) + "', ',')) order by 1")
    stmts = []
    for tc in cols.splitlines():
        t, c = tc.split(".", 1)
        stmts.append(f"select '{t}.{c}' || '|' || v from (select distinct \"{c}\"::text v from \"{t}\" where tenant_id='{tid}' and \"{c}\" is not null "
                     f"and length(\"{c}\"::text) between {min_len} and {max_len}) s;")
    out = {}
    for l in psql("\n".join(stmts)).splitlines():
        src, _, v = l.partition("|")
        out.setdefault(v, src)
    return out


def id_pools(tables, tid, per=8):
    has_id = set(psql("select table_name from information_schema.columns where table_schema='public' and column_name='id'").split())
    stmts = [f"select '{t}|' || id::text from (select id from \"{t}\" where tenant_id='{tid}' order by id limit {per}) s;"
             for t in tables if t in has_id and t not in NO_ID_TABLES]
    pools = {}
    for l in psql(chr(10).join(stmts)).splitlines():
        t, _, v = l.partition("|")
        pools.setdefault(t, []).append(v)
    return pools


NO_ID_TABLES = {"migrations", "failed_jobs"}


# ---- operations ---------------------------------------------------------------------------------------------------------
def norm_path(p):
    return re.sub(r"\{[^}]+\}", "{}", "/" + p.strip("/").replace("?", ""))


class Op:
    def __init__(self, method, template, spec=None, action=None):
        self.method = method.upper()
        self.template = "/" + template.strip("/")          # real route (Laravel) template when routed, else the spec path
        self.spec = spec                                    # openapi operation object or None
        self.spec_path = None
        self.action = action                                # controller@method when routed
        self.routed = action is not None
        self.key = f"{self.method} {norm_path(self.template)}"

    @property
    def params(self):
        return [m.rstrip("?") for m in re.findall(r"\{([^}]+)\}", self.template)]

    @property
    def in_spec(self):
        return self.spec is not None

    @property
    def mutating(self):
        return self.method != "GET"


def load_ops(openapi_path, routes_text):
    spec = json.load(open(openapi_path, encoding="utf-8"))
    comp = spec.get("components", {})
    routes = {}
    for l in routes_text.splitlines():
        if not l.strip():
            continue
        m, u, a = l.split(" ", 2)
        for x in m.split("|"):
            if x in ("GET", "POST", "PUT", "PATCH", "DELETE"):
                routes.setdefault((x, norm_path(u)), (u, a))
    ops = {}
    for p, item in spec["paths"].items():
        for m in METHODS:
            if m in item:
                o = item[m]
                o = dict(o)
                o["_params"] = list(item.get("parameters", [])) + list(o.get("parameters", []))
                key = (m.upper(), norm_path(p))
                r = routes.get(key)
                op = Op(m, r[0] if r else p, o, r[1] if r else None)
                op.spec_path = p
                ops[key] = op
    for key, (u, a) in routes.items():
        if key not in ops:
            ops[key] = Op(key[0], u, None, a)
    return sorted(ops.values(), key=lambda o: (o.template, o.method)), comp


def resolve(schema, comp, depth=0):
    if not isinstance(schema, dict) or depth > 6:
        return {}
    if "$ref" in schema:
        node = comp
        for part in schema["$ref"].split("/")[2:]:
            node = node.get(part, {}) if isinstance(node, dict) else {}
        return resolve(node, comp, depth + 1)
    if "allOf" in schema:
        merged = {"type": "object", "properties": {}, "required": []}
        for s in schema["allOf"]:
            s = resolve(s, comp, depth + 1)
            merged["properties"].update(s.get("properties", {}))
            merged["required"] += s.get("required", [])
        return merged
    for k in ("oneOf", "anyOf"):
        if k in schema and schema[k]:
            return resolve(schema[k][0], comp, depth + 1)
    return schema


# ---- table inference for ids ----------------------------------------------------------------------------------------------
def stem(s):
    s = re.sub(r"[^a-z]", "", s.lower())
    if s.endswith("ies"):
        return s[:-3] + "y"
    if s.endswith(("sses", "xes", "ches", "shes")):
        return s[:-2]
    if s.endswith("s") and not s.endswith("ss"):
        return s[:-1]
    return s


def snake(s):
    return re.sub(r"(?<!^)(?=[A-Z])", "_", s).lower()


def rank_tables(name, tables):
    """Tables ranked by how well they match a path/param name. [(score, table)] best first."""
    n = stem(snake(name).replace("-", "_"))
    if not n:
        return []
    out = []
    for t in tables:
        s = stem(t)
        if s == n:
            out.append((3.0, t))
        elif s.endswith(n) or n.endswith(s):
            out.append((2.0 - abs(len(s) - len(n)) / 100, t))
        elif n in s:
            out.append((1.0 - len(s) / 1000, t))
        else:
            r = difflib.SequenceMatcher(None, n, s).ratio()
            if r >= 0.75:
                out.append((r - 0.2, t))
    return sorted(out, key=lambda x: -x[0])


OVERRIDES = {  # path-segment / param name -> table, where the name does not say it
    "me": "users", "user": "users", "author": "users", "student": "users", "tutor": "users", "owner": "users",
    "role": "roles", "permission": "permissions", "client": "oauth_clients", "token": "oauth_access_tokens",
    "report": "reports", "order": "orders",
}


class Ids:
    """Real row ids of a tenant, keyed by table; picks the table a path parameter most likely names."""

    def __init__(self, pools, tables):
        self.pools, self.tables = pools, tables
        self.used = []   # (field, table, id) picked for query/body fields, so a caller can tell which victim ids went into a request

    def pick(self, op, param, previous_segment):
        name = param if param not in ("id", "uuid", "slug") else previous_segment
        names = [name] if name not in OVERRIDES else [OVERRIDES[name], name]
        for nm in names:
            if nm in self.tables:
                if self.pools.get(nm):
                    return nm, self.pools[nm]
            for score, t in rank_tables(nm, self.tables):
                if self.pools.get(t):
                    return t, self.pools[t]
        return None, []

    def for_field(self, field):
        """Ids for a body/query field such as course_id, user_ids, parent."""
        base = re.sub(r"(_ids?|Ids?)$", "", field)
        if base == field and field not in ("id", "ids"):
            return None, []
        table, pool = self.pick(None, base, base) if base else (None, [])
        if pool:
            self.used.append((field, table, pool[0]))
        return table, pool


def concrete(op, ids, rng, which=0):
    """Fill {params}. Returns (path, [(param, table|None, value)])."""
    path, used = op.template, []
    segs = op.template.strip("/").split("/")
    for i, seg in enumerate(segs):
        m = re.fullmatch(r"\{([^}]+)\}", seg)
        if not m:
            continue
        param = m.group(1).rstrip("?")
        prev = ""
        for j in range(i - 1, -1, -1):
            if not segs[j].startswith("{"):
                prev = segs[j]
                break
        table, pool = ids.pick(op, param, prev)
        if pool:
            val = pool[which % len(pool)]
        else:
            val = "1"
        used.append((param, table, val))
        path = path.replace("{" + m.group(1) + "}", quote(str(val), safe=""), 1)
    return path, used


# ---- request bodies ---------------------------------------------------------------------------------------------------------
def sample(schema, comp, ids, rng, name="", depth=0, tag="gate"):
    s = resolve(schema, comp)
    if not s or depth > 4:
        return None
    t = s.get("type") or ("object" if "properties" in s else None)
    if "enum" in s and s["enum"]:
        return s["enum"][0]
    if t == "object" or "properties" in s:
        out = {}
        for k, v in list(s.get("properties", {}).items())[:30]:
            val = sample(v, comp, ids, rng, k, depth + 1, tag)
            if val is not None:
                out[k] = val
        return out
    if t == "array":
        item = resolve(s.get("items", {}), comp)
        if re.search(r"ids?$", name, re.I) or item.get("type") in ("integer", "string") and name.endswith("s"):
            table, pool = ids.for_field(name if name.endswith(("id", "ids", "Id", "Ids")) else name.rstrip("s") + "_ids")
            if pool:
                return pool[:2] if item.get("type") != "string" else [str(x) for x in pool[:2]]
        v = sample(item, comp, ids, rng, name.rstrip("s"), depth + 1, tag)
        return [v] if v is not None else []
    if t in ("integer", "number"):
        if re.search(r"(^|_)ids?$|Id$", name):
            table, pool = ids.for_field(name)
            if pool:
                try:
                    return int(pool[0])
                except ValueError:
                    return pool[0]
        return 1
    if t == "boolean":
        return False
    # strings
    f = s.get("format", "")
    if re.search(r"(^|_)ids?$|Id$", name):
        table, pool = ids.for_field(name)
        if pool:
            return pool[0]
    if "email" in name or f == "email":
        return f"{tag}-{rng.randrange(10**8)}@gate.test"
    if "password" in name:
        return "Gate-Passw0rd!x1"
    if f in ("date-time",) or name.endswith("_at"):
        return "2030-01-01T00:00:00Z"
    if f == "date":
        return "2030-01-01"
    if f in ("uri", "url") or name in ("url", "link", "return_url", "redirect"):
        return "http://localhost/"
    if f == "uuid":
        return str(uuid.uuid4())
    return f"{tag}_{name or 'v'}_{rng.randrange(10**6)}"


def query_params(op, comp, ids, rng):
    q = {}
    if op.spec:
        for p in op.spec.get("_params", []):
            p = resolve(p, comp) if "$ref" in p else p
            if p.get("in") != "query":
                continue
            name = p["name"]
            sc = resolve(p.get("schema", {}), comp)
            table, pool = ids.for_field(name.rstrip("[]"))
            if pool:
                q[name] = pool[0]
            elif "enum" in sc and sc["enum"]:
                q[name] = sc["enum"][0]
            elif name in ("per_page",):
                q[name] = 100
            elif sc.get("type") in ("integer", "number"):
                q[name] = 1
            elif sc.get("type") == "boolean":
                q[name] = "false"
    return q


def body_for(op, comp, ids, rng, tag="gate"):
    """('json', obj) | ('multipart', [(k,v)...]) | (None, None)"""
    if not op.mutating or not op.spec:
        return None, None
    rb = resolve(op.spec.get("requestBody", {}), comp)
    content = rb.get("content", {}) if rb else {}
    for ctype in ("application/json", "multipart/json", "application/x-www-form-urlencoded", "multipart/form-data"):
        if ctype in content:
            obj = sample(content[ctype].get("schema", {}), comp, ids, rng, "", 0, tag)
            if ctype == "application/json" or ctype == "multipart/json":
                return "json", obj or {}
            parts = []
            for k, v in (obj or {}).items():
                if isinstance(v, (dict, list)):
                    parts.append((k, json.dumps(v)))
                else:
                    parts.append((k, str(v)))
            sch = resolve(content[ctype].get("schema", {}), comp)
            for k, v in sch.get("properties", {}).items():
                if resolve(v, comp).get("format") == "binary":
                    parts.append((k, (f"{tag}.txt", b"gate", "text/plain")))
            return "multipart", parts
    return None, None


# ---- leak detection ------------------------------------------------------------------------------------------------------------
TOKEN_RE = re.compile(r"[A-Za-z0-9@._:/+=\-]{8,}")


def strings_in(obj, out):
    if isinstance(obj, str):
        out.add(obj)
    elif isinstance(obj, dict):
        for v in obj.values():
            strings_in(v, out)
    elif isinstance(obj, list):
        for v in obj:
            strings_in(v, out)


def leak_scan(resp, unique, markers, ignore):
    """Strings in the response that exist only in the victim tenant. `ignore` = strings the attacker itself sent (an echo is no leak)."""
    found = {}
    if resp.status == 0 or not resp.text:
        return found
    cand = set()
    if resp.json is not None:
        strings_in(resp.json, cand)
    cand |= set(TOKEN_RE.findall(resp.text))
    for c in cand:
        if c in unique and c not in ignore:
            found[c] = unique[c]
    low = resp.text
    for m in markers:
        if m in low and not any(m in i for i in ignore):
            found.setdefault(m, "marker")
    return found
