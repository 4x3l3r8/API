"""Batch 4c.2: contract Â§D.5 token binding, against the live stack.

    python -I luminate/tests/smoke/token_binding.py

Wellms-issued user tokens carry a `tenant` claim (Passport access-token entity; no core patch). The request path rejects a
token whose claim differs from the verified context token's `tid` with an explicit 403, before authentication, IN ADDITION to
row-level security. The claim is never trusted on its own: Passport still verifies the signature.
"""
import base64
import json
import pathlib
import subprocess
import sys
import time
import urllib.error
import urllib.request

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[2] / "tools"))
import context_token as ct  # noqa: E402
import luminate_client as lc  # noqa: E402  (per-tenant machine-admin secrets, contract A.2.2)

E = ct.read_env()
BASE = (sys.argv[1] if len(sys.argv) > 1 else f"http://{E['LAN_HOST']}:{E.get('API_PORT', '8080')}").rstrip("/")
KEY = E["TENANT_CONTEXT_KEY"]
ADMIN = E["INITIAL_USER_EMAIL"]  # the password is per tenant: lc.password(tid)
RUN = str(int(time.time()))
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
            return e.code, raw[:200]


def check(name, ok, detail=""):
    results.append(ok)
    print(f"{'PASS' if ok else 'FAIL'}  {name}" + (f"  [{detail}]" if detail and not ok else ""))


def claims(jwt):
    p = jwt.split(".")[1]
    return json.loads(base64.urlsafe_b64decode(p + "=" * (-len(p) % 4)))


def code(r):
    return r.get("code", "") if isinstance(r, dict) else ""


def psql(sql, guc):
    p = subprocess.run(["docker", "exec", "-i", "-e", f"PGPASSWORD={E['DB_APP_PASSWORD']}", "luminate-wellms-postgres-1", "psql", "-h", "127.0.0.1",
                        "-U", E["DB_APP_USER"], "-d", E.get("DB_NAME", "wellms"), "-At", "-q", "-v", "ON_ERROR_STOP=1"],
                       input=f"select set_config('app.tenant_id','{guc}',false);" + sql, capture_output=True, text=True)
    return (p.stdout.strip().splitlines() or [""])[-1]


# --- tokens carry the claim ---------------------------------------------------------------------------------------
tok = {}
for t in ("t-alpha", "t-beta"):
    s, r = call("POST", "/api/auth/login", t, body={"email": ADMIN, "password": lc.password(t)})
    tok[t] = r["data"]["token"] if s == 200 else ""
    c = claims(tok[t]) if tok[t] else {}
    check(f"{t}: the login token carries tenant='{t}'", c.get("tenant") == t, str(c))
    check(f"{t}: the re-signed token still has Passport's own claims (aud/jti/sub/scopes/exp)", all(k in c for k in ("aud", "jti", "sub", "scopes", "exp", "iat", "nbf")), str(sorted(c)))

# --- explicit binding: 403, in addition to RLS ------------------------------------------------------------------------
s, r = call("GET", "/api/profile/me", "t-alpha", tok["t-alpha"])
check("own tenant: alpha's token under alpha's context works (200)", s == 200, str(s))
s, r = call("GET", "/api/profile/me", "t-beta", tok["t-alpha"])
check("alpha's token under beta's context is an explicit 403 token_tenant_mismatch (not 401, not 200)", s == 403 and code(r) == "tenant_context_token_tenant_mismatch", f"{s} {r}")
s, r = call("GET", "/api/profile/me", "t-alpha", tok["t-beta"])
check("beta's token under alpha's context is an explicit 403 token_tenant_mismatch", s == 403 and code(r) == "tenant_context_token_tenant_mismatch", f"{s} {r}")
s, r = call("GET", "/api/profile/me", None, tok["t-alpha"])
check("a token with no tenant context is still 401 (context is required first)", s == 401 and code(r) == "tenant_context_missing", f"{s} {r}")
s, r = call("POST", "/api/admin/users", "t-beta", tok["t-alpha"], {"first_name": "x", "last_name": "x", "email": f"x{RUN}@x.test", "password": "Fake-Passw0rd!x", "return_url": "http://localhost/"})
check("a cross-tenant WRITE attempt is rejected before it reaches the controller (403)", s == 403, f"{s}")

# --- the claim is never trusted on its own --------------------------------------------------------------------------
p = tok["t-alpha"].split(".")
payload = claims(tok["t-alpha"])
payload["tenant"] = "t-beta"
forged_payload = base64.urlsafe_b64encode(json.dumps(payload, separators=(",", ":")).encode()).rstrip(b"=").decode()
tampered = f"{p[0]}.{forged_payload}.{p[2]}"
s, r = call("GET", "/api/profile/me", "t-beta", tampered)
check("alpha's token with its claim edited to t-beta (signature untouched) does NOT authenticate under beta (401)", s == 401, f"{s} {str(r)[:80]}")

rc = subprocess.run(["docker", "cp", str(pathlib.Path(__file__).with_name("forge_tokens.php")), "luminate-wellms-api-1:/tmp/forge_tokens.php"], capture_output=True, text=True)
out = subprocess.run(["docker", "exec", "-u", "www-data", "luminate-wellms-api-1", "php", "/tmp/forge_tokens.php", "t-alpha"], capture_output=True, text=True).stdout
forged = json.loads(out)
s, r = call("GET", "/api/profile/me", "t-alpha", forged["unbound"])
check("a validly signed token with NO tenant claim is refused (403 token_unbound)", s == 403 and code(r) == "tenant_context_token_unbound", f"{s} {r}")
s, r = call("GET", "/api/profile/me", "t-alpha", forged["attacker"])
check("an attacker-signed token carrying tenant=t-alpha passes the claim check but NOT authentication (401)", s == 401, f"{s} {str(r)[:80]}")

# --- defence in depth: the token row itself is tenant-owned --------------------------------------------------------------
jti = claims(tok["t-alpha"])["jti"]
check("alpha's token row is stamped tenant_id='t-alpha' in oauth_access_tokens", psql(f"select tenant_id from oauth_access_tokens where id='{jti}'", "t-alpha") == "t-alpha")
check("...and is invisible to tenant beta at the database level", psql(f"select count(*) from oauth_access_tokens where id='{jti}'", "t-beta") == "0")

# --- contract Â§D.3: impersonation returns a tenant-bound token -------------------------------------------------------------
email = f"imp-{RUN}@alpha.test"
s, r = call("POST", "/api/admin/users", "t-alpha", tok["t-alpha"], {"first_name": "Imp", "last_name": "User", "email": email, "password": "Fake-Passw0rd!x", "return_url": "http://localhost/"})
uid = r["data"]["id"] if s in (200, 201) else None
s, r = call("POST", "/api/admin/auth/impersonate", "t-alpha", tok["t-alpha"], {"user_id": uid})
imp = (r.get("data") or {}).get("token") if isinstance(r, dict) else None
check("impersonation returns a token", s == 200 and bool(imp), f"{s} {str(r)[:120]}")
if imp:
    check("the impersonation token carries tenant='t-alpha'", claims(imp).get("tenant") == "t-alpha", str(claims(imp)))
    s, r = call("GET", "/api/profile/me", "t-alpha", imp)
    check("it authenticates as the impersonated user under alpha", s == 200 and r["data"]["email"] == email, f"{s} {str(r)[:100]}")
    s, r = call("GET", "/api/profile/me", "t-beta", imp)
    check("it is refused under beta (403)", s == 403, f"{s}")

print(f"\n{sum(results)}/{len(results)} passed")
sys.exit(0 if all(results) else 1)
