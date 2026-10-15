"""DEV stand-in for the Luminate proxy's side of contract A.2.2: ask Wellms for a tenant's machine-admin secret over the signed registry channel.

Wellms keeps no copy of the secret and there is no shared default, so every test that logs in as a tenant's admin needs the tenant's own secret.
In production Luminate holds these in its secret store. Here a git-ignored file (infra/.dev-admin-secrets.json) plays that store:

    password(tid)   the tenant's secret; verified with a real login, and re-issued (rotated) only if that fails
    rotate(tid)     issue a new secret now (invalidates the old one and its tokens)

    python -I luminate/tools/luminate_client.py password t-alpha
    python -I luminate/tools/luminate_client.py rotate t-alpha

Signing is exactly the registry wire shape: X-Luminate-Timestamp + X-Luminate-Signature = sha256=hex(HMAC(key, "{ts}.{METHOD}.{path}.{sha256hex(body)}")).
"""
import hashlib
import hmac
import json
import os
import pathlib
import sys
import time
import urllib.error
import urllib.request

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
import context_token as ct  # noqa: E402

E = ct.read_env()
HOST = os.environ.get("LUMINATE_TEST_HOST") or E["LAN_HOST"]
BASE = f"http://{HOST}:{E.get('API_PORT', '8080')}"
EMAIL = E["INITIAL_USER_EMAIL"]
STORE = pathlib.Path(__file__).resolve().parents[1] / "infra" / ".dev-admin-secrets.json"
_verified = {}


def sign(key, ts, method, path, body):
    return "sha256=" + hmac.new(key.encode(), f"{ts}.{method}.{path}.{hashlib.sha256(body).hexdigest()}".encode(), hashlib.sha256).hexdigest()


def registry_call(method, path, body=b"", key=None, ts_off=0):
    key = key or E["LUMINATE_REGISTRY_KEY"]
    ts = str(int(time.time()) + ts_off)
    req = urllib.request.Request(BASE + path, data=body or None, method=method, headers={
        "Accept": "application/json", "Content-Type": "application/json", "X-Luminate-Timestamp": ts, "X-Luminate-Signature": sign(key, ts, method, path, body)})
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            return r.status, json.loads(r.read() or b"null"), dict(r.headers)
    except urllib.error.HTTPError as e:
        raw = e.read().decode()
        try:
            return e.code, json.loads(raw), dict(e.headers)
        except Exception:
            return e.code, raw[:200], dict(e.headers)


def _load():
    try:
        return json.loads(STORE.read_text())
    except Exception:
        return {}


def _save(d):
    STORE.write_text(json.dumps(d, indent=1))


def _login_ok(tid, secret):
    req = urllib.request.Request(BASE + "/api/auth/login", data=json.dumps({"email": EMAIL, "password": secret}).encode(), method="POST",
                                 headers={"Content-Type": "application/json", "Accept": "application/json", "X-Tenant-Context": ct.mint(E["TENANT_CONTEXT_KEY"], tid)})
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            return r.status == 200
    except urllib.error.HTTPError:
        return False


def rotate(tid, secret=None):
    """Issue a new machine-admin secret (Wellms generates it unless `secret` is given). Returns the secret now in force."""
    body = json.dumps({"secret": secret}).encode() if secret else b""
    s, r, _ = registry_call("POST", f"/api/luminate/registry/tenants/{tid}/admin-credential", body)
    if s == 409 and isinstance(r, dict) and r.get("code") == "registry_replayed":
        # two identical requests inside one second sign identically, and a signature is single-use: wait for the next second
        time.sleep(1.1)
        s, r, _ = registry_call("POST", f"/api/luminate/registry/tenants/{tid}/admin-credential", body)
    if s != 200:
        raise RuntimeError(f"admin-credential {tid}: {s} {str(r)[:160]}")
    new = secret or r["secret"]
    d = _load()
    d[tid] = new
    _save(d)
    _verified[tid] = new
    return new


def password(tid):
    if tid in _verified:
        return _verified[tid]
    s = _load().get(tid)
    if s and _login_ok(tid, s):
        _verified[tid] = s
        return s
    return rotate(tid)


if __name__ == "__main__":
    if len(sys.argv) != 3 or sys.argv[1] not in ("password", "rotate"):
        sys.exit(__doc__)
    print(password(sys.argv[2]) if sys.argv[1] == "password" else rotate(sys.argv[2]))
