"""S6 part 3: CSV-imported user state, error semantics, idempotency, headers/versioning, rate limiting."""
import sys, os, json, time, urllib.request, urllib.error
sys.path.insert(0, os.path.dirname(__file__))
from wl import W, BASE

HOST = "tenant-b.localhost"
a = W(HOST); a.login("admin@tenant-b.test", "beta-secret")
PW = "Passw0rd!eval"


def raw(method, path, body=None, token="__admin__", headers=None, host=HOST):
    h = {"Host": host, "Accept": "application/json"}
    if token == "__admin__":
        token = a.token
    if token:
        h["Authorization"] = "Bearer " + token
    data = None
    if body is not None:
        data = body if isinstance(body, bytes) else json.dumps(body).encode()
        h["Content-Type"] = "application/json"
    h.update(headers or {})
    req = urllib.request.Request(BASE + path, data=data, headers=h, method=method)
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            return r.status, dict(r.headers), r.read().decode()
    except urllib.error.HTTPError as e:
        return e.code, dict(e.headers), e.read().decode()


print("== CSV-imported user: roles / verified / can log in?")
c, js = a.call("GET", "/api/admin/users?search=bulk000", quiet=True)
u = js["data"][0]
print("  roles:", u.get("roles"), "| email_verified:", u.get("email_verified"), "| is_active:", u.get("is_active"))
c, js = a.call("POST", "/api/auth/login", {"email": "bulk000@tenant-b.test", "password": PW}, quiet=True)
print("  login ->", c, js.get("message"))

print("== ERROR SEMANTICS (status | body shape)")
tests = [
    ("no token", "GET", "/api/admin/users", None, None),
    ("garbage token", "GET", "/api/admin/users", None, "abc.def.ghi"),
    ("student token on admin route", "GET", "/api/admin/users", None, "STUDENT"),
    ("missing resource", "GET", "/api/admin/courses/999999", None, "__admin__"),
    ("missing route", "GET", "/api/does/not/exist", None, "__admin__"),
    ("wrong method", "DELETE", "/api/profile/me", None, "__admin__"),
    ("validation error", "POST", "/api/admin/courses", {"summary": "no title"}, "__admin__"),
    ("malformed JSON", "POST", "/api/admin/courses", b"{not json", "__admin__"),
    ("wrong type", "GET", "/api/admin/users/abc", None, "__admin__"),
]
st = W(HOST); st.login("bob@tenant-b.test", PW)
for label, m, p, b, tok in tests:
    tok = st.token if tok == "STUDENT" else tok
    code, hd, txt = raw(m, p, b, token=tok)
    try:
        js = json.loads(txt); keys = sorted(js.keys())
    except Exception:
        js, keys = {}, ["<non-json>"]
    leak = "TRACE LEAK" if ("trace" in js or "exception" in js or "/var/www" in txt) else ""
    print(f"  {label:30s} {code} keys={keys} {leak}")

print("== IDEMPOTENCY")
code, _, t = raw("POST", "/api/admin/users", {"email": "bob@tenant-b.test", "first_name": "x", "last_name": "y", "password": PW, "password_confirmation": PW, "roles": ["student"], "return_url": "http://localhost/v"})
print("  duplicate email ->", code, json.loads(t).get("errors"))
n = []
for i in range(2):
    code, _, t = raw("POST", "/api/admin/courses", {"title": "IdemTest", "status": "draft"}, headers={"Idempotency-Key": "abc-123"})
    n.append(json.loads(t)["data"]["id"])
print("  POST course twice with same Idempotency-Key -> ids", n, "(header honoured)" if n[0] == n[1] else "(header ignored: two records)")

print("== VERSIONING / HEADERS")
code, hd, _ = raw("GET", "/api/courses?per_page=1", token=None)
print("  response headers:", {k: v for k, v in hd.items() if k.lower() in ("x-ratelimit-limit", "x-ratelimit-remaining", "retry-after", "api-version", "x-api-version", "etag", "cache-control", "server", "x-powered-by", "access-control-allow-origin", "vary")})
print("  path versioning: any '/v1' prefix? ->", raw("GET", "/api/v1/courses", token=None)[0])

print("== RATE LIMIT on login (15 rapid bad logins)")
codes = []
for i in range(15):
    code, hd, _ = raw("POST", "/api/auth/login", {"email": "nobody@tenant-b.test", "password": "x"}, token=None)
    codes.append(code)
print("  statuses:", codes)
