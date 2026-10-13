"""Batch 4b: end-to-end tenant isolation through the real API (Caddy -> php-fpm -> RLS), two provisioned tenants.

    python -I luminate/tests/smoke/isolation_smoke_4b.py

Both tenants have an admin with the SAME e-mail and password, so any leak or token confusion shows up directly.
This is a hand-written smoke test; the generated all-operations suite (Batch 6) is the release gate.
"""
import json
import pathlib
import sys
import time
import urllib.error
import urllib.request

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[2] / "tools"))
import context_token as ct  # noqa: E402

E = ct.read_env()
BASE = (sys.argv[1] if len(sys.argv) > 1 else f"http://{E['LAN_HOST']}:{E.get('API_PORT', '8080')}").rstrip("/")
KEY = E["TENANT_CONTEXT_KEY"]
ADMIN, PASSWORD = E["INITIAL_USER_EMAIL"], E["INITIAL_USER_PASSWORD"]
RUN = str(int(time.time()))
results = []


def call(method, path, tid=None, bearer=None, body=None, token=None):
    h = {"Accept": "application/json"}
    if token is not None:
        h["X-Tenant-Context"] = token
    elif tid:
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


def emails(tid, tok):
    s, r = call("GET", "/api/admin/users?per_page=100", tid, tok)
    return s, {u["email"]: u["id"] for u in (r.get("data") or [])} if isinstance(r, dict) else {}


def new_user(tid, tok, email):
    return call("POST", "/api/admin/users", tid, tok, {
        "first_name": "Fake", "last_name": "User", "email": email, "password": "Fake-Passw0rd!x", "return_url": "http://localhost/"})


# --- login: same credentials, two tenants -----------------------------------------------------------------------
tok = {}
for t in ("t-alpha", "t-beta"):
    s, r = call("POST", "/api/auth/login", t, body={"email": ADMIN, "password": PASSWORD})
    check(f"{t}: admin logs in", s == 200 and isinstance(r, dict) and "token" in r.get("data", {}), f"{s} {str(r)[:100]}")
    tok[t] = r["data"]["token"] if s == 200 else ""

ida = {t: call("GET", "/api/profile/me", t, tok[t])[1]["data"]["id"] for t in tok}
check("the two admins are different rows (different ids) despite identical credentials", ida["t-alpha"] != ida["t-beta"], str(ida))

# --- token confusion --------------------------------------------------------------------------------------------
s, _ = call("GET", "/api/profile/me", "t-beta", tok["t-alpha"])
check("alpha's bearer token under beta's context is rejected (401)", s == 401, str(s))
s, _ = call("GET", "/api/profile/me", "t-alpha", tok["t-beta"])
check("beta's bearer token under alpha's context is rejected (401)", s == 401, str(s))
s, _ = call("GET", "/api/profile/me", None, tok["t-alpha"])
check("a bearer token with NO tenant context is rejected (401)", s == 401, str(s))
s, r = call("GET", "/api/profile/me", "t-gamma", tok["t-alpha"], token=ct.mint(KEY, "t-gamma", status="suspended"))
check("a suspended tenant's context is refused (403) even with a valid-looking token", s == 403, str(s))
s, r = call("GET", "/api/profile/me", "t-alpha", tok["t-alpha"])
check("alpha's token under alpha's context still works", s == 200 and r["data"]["email"] == ADMIN, str(s))

# --- same e-mail in both tenants (composite unique) ---------------------------------------------------------------
shared = f"shared-{RUN}@both.test"
a_only, b_only = f"a-only-{RUN}@alpha.test", f"b-only-{RUN}@beta.test"
sa, ra = new_user("t-alpha", tok["t-alpha"], a_only)
sb, rb = new_user("t-beta", tok["t-beta"], b_only)
check("alpha creates its own user", sa in (200, 201), f"{sa} {str(ra)[:150]}")
check("beta creates its own user", sb in (200, 201), f"{sb} {str(rb)[:150]}")
s1, _ = new_user("t-alpha", tok["t-alpha"], shared)
s2, r2 = new_user("t-beta", tok["t-beta"], shared)
check("the SAME e-mail can be created in both tenants", s1 in (200, 201) and s2 in (200, 201), f"alpha={s1} beta={s2} {str(r2)[:120]}")
s3, r3 = new_user("t-alpha", tok["t-alpha"], shared)
check("the same e-mail twice in ONE tenant is rejected (422)", s3 == 422, f"{s3} {str(r3)[:120]}")
s4, r4 = new_user("t-beta", tok["t-beta"], ADMIN)
check("beta cannot create a duplicate of its own admin's e-mail (422)", s4 == 422, f"{s4}")

# --- reads are confined -----------------------------------------------------------------------------------------
_, la = emails("t-alpha", tok["t-alpha"])
_, lb = emails("t-beta", tok["t-beta"])
check("alpha's list holds alpha's users and not beta's", {ADMIN, a_only, shared} <= set(la) and b_only not in la, str(sorted(la)))
check("beta's list holds beta's users and not alpha's", {ADMIN, b_only, shared} <= set(lb) and a_only not in lb, str(sorted(lb)))
check("neither list is polluted: exactly 3 users each", len(la) == 3 and len(lb) == 3, f"alpha={len(la)} beta={len(lb)}")
s, r = call("GET", f"/api/admin/users/{la[a_only]}", "t-alpha", tok["t-alpha"])
check("alpha can read its own user by id", s == 200, str(s))
s, r = call("GET", f"/api/admin/users/{la[a_only]}", "t-beta", tok["t-beta"])
check("beta reading ALPHA's user by id gets 404", s == 404, f"{s} {str(r)[:100]}")
s, r = call("GET", f"/api/admin/users/{lb[b_only]}", "t-alpha", tok["t-alpha"])
check("alpha reading BETA's user by id gets 404", s == 404, f"{s} {str(r)[:100]}")

# --- writes are confined ----------------------------------------------------------------------------------------
s, r = call("PUT", f"/api/admin/users/{la[a_only]}", "t-beta", tok["t-beta"], {"first_name": "HACKED", "last_name": "x", "email": a_only})
check("beta cannot modify alpha's user (404)", s in (404, 403), f"{s} {str(r)[:100]}")
s, r = call("DELETE", f"/api/admin/users/{la[a_only]}", "t-beta", tok["t-beta"])
check("beta cannot delete alpha's user (404)", s in (404, 403), f"{s} {str(r)[:100]}")
s, r = call("GET", f"/api/admin/users/{la[a_only]}", "t-alpha", tok["t-alpha"])
check("alpha's user is intact afterwards (not modified, not deleted)", s == 200 and r["data"]["first_name"] == "Fake", f"{s} {str(r)[:100]}")

# --- roles / permissions are per tenant -------------------------------------------------------------------------
sr, ra = call("GET", "/api/admin/roles", "t-alpha", tok["t-alpha"])
sb2, rb2 = call("GET", "/api/admin/roles", "t-beta", tok["t-beta"])
check("each tenant sees its own 3 roles (not 6)", sr == 200 and sb2 == 200 and len(ra.get("data", [])) == len(rb2.get("data", [])) and len(ra.get("data", [])) <= 3,
      f"alpha={len(ra.get('data', [])) if isinstance(ra, dict) else ra} beta={len(rb2.get('data', [])) if isinstance(rb2, dict) else rb2}")

print(f"\n{sum(results)}/{len(results)} passed")
sys.exit(0 if all(results) else 1)
