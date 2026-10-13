"""Batch 4a smoke test: the live API must fail closed without a valid X-Tenant-Context (contract v3 §A.1).

    python -I luminate/tests/smoke/context_token_smoke.py [base_url]
"""
import json
import pathlib
import sys
import time
import urllib.error
import urllib.request

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[2] / "tools"))
import context_token as ct  # noqa: E402

env = ct.read_env()
BASE = (sys.argv[1] if len(sys.argv) > 1 else f"http://{env['LAN_HOST']}:{env.get('API_PORT', '8080')}").rstrip("/")
KEY = env["TENANT_CONTEXT_KEY"]
ROUTE = "/api/events"  # an unauthenticated JSON route that does not need a tenant-specific row


def call(path, token=None, method="GET", headers=None, body=None):
    h = dict(headers or {})
    if token is not None:
        h["X-Tenant-Context"] = token
    data = None
    if body is not None:
        data = json.dumps(body).encode()
        h["Content-Type"] = "application/json"
    req = urllib.request.Request(BASE + path, data=data, method=method, headers=h)
    try:
        with urllib.request.urlopen(req, timeout=20) as r:
            return r.status, r.read().decode()
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode()


def code_of(body):
    try:
        return json.loads(body).get("code", "")
    except Exception:
        return ""


good = ct.mint(KEY, "t-alpha")
now = int(time.time())
cases = [
    # name, path, token, method, expected status, expected code
    ("no header", ROUTE, None, "GET", 401, "tenant_context_missing"),
    ("empty header", ROUTE, "", "GET", 401, "tenant_context_missing"),
    ("garbage", ROUTE, "not-a-token", "GET", 401, "tenant_context_malformed"),
    ("alg none", ROUTE, ct.mint(KEY, "t-alpha", alg="none"), "GET", 401, "tenant_context_bad_algorithm"),
    ("wrong key", ROUTE, ct.mint("x" * 48, "t-alpha"), "GET", 401, "tenant_context_bad_signature"),
    ("expired", ROUTE, ct.mint(KEY, "t-alpha", iat=now - 400, ttl=300), "GET", 401, "tenant_context_expired"),
    ("lifetime > 300s", ROUTE, ct.mint(KEY, "t-alpha", ttl=900), "GET", 401, "tenant_context_invalid_claims"),
    ("tid with quote", ROUTE, ct.mint(KEY, "x'--"), "GET", 401, "tenant_context_invalid_claims"),
    ("DSN as connection", ROUTE, ct.mint(KEY, "t-alpha", connection="pgsql://u:p@h/db"), "GET", 401, "tenant_context_invalid_claims"),
    ("suspended tenant", ROUTE, ct.mint(KEY, "t-alpha", status="suspended"), "GET", 403, "tenant_context_tenant_inactive"),
    ("provisioning tenant", ROUTE, ct.mint(KEY, "t-alpha", status="provisioning"), "GET", 403, "tenant_context_tenant_inactive"),
    ("unmapped connection", ROUTE, ct.mint(KEY, "t-alpha", connection="shard-9"), "GET", 403, "tenant_context_placement_unsupported"),
    ("foreign bucket", ROUTE, ct.mint(KEY, "t-alpha", bucket="other-bucket"), "GET", 403, "tenant_context_placement_unsupported"),
    ("write without header", "/api/auth/login", None, "POST", 401, "tenant_context_missing"),
    ("valid token", ROUTE, good, "GET", 200, ""),
    # exemptions: GET/HEAD only, and only the configured paths (dev: Swagger)
    ("swagger UI exempt", "/api/documentation", None, "GET", 200, ""),
    ("spec exempt", "/docs", None, "GET", 200, ""),
    ("exempt path, POST", "/docs", None, "POST", None, "tenant_context_missing"),
]

failed = 0
for name, path, token, method, want_status, want_code in cases:
    status, body = call(path, token, method)
    ok_status = want_status is None or status == want_status
    ok_code = (want_code == "" and status < 400) or code_of(body) == want_code
    ok = ok_status and ok_code
    failed += not ok
    print(f"{'PASS' if ok else 'FAIL'}  {name:<24} -> {status} {code_of(body)}")

print(f"\n{len(cases) - failed}/{len(cases)} passed")
sys.exit(1 if failed else 0)
