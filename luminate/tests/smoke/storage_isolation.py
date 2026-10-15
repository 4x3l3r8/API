"""Batch 4c.4: per-tenant file storage, against the live stack. Ground truth is MinIO's own object listing, not config values.

    python -I luminate/tests/smoke/storage_isolation.py

Covers: the S3 key prefix and the local scratch directories for CLI, queue-worker and HTTP paths; the real media endpoints
(/api/admin/file/{upload,list,find,move,delete}) with the SAME filename in two tenants; and cross-tenant attempts through those
endpoints (another tenant's URL, '..' traversal). Needs the dev fixtures (docker-compose.dev.yml).
"""
import json
import pathlib
import re
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[2] / "tools"))
import context_token as ct  # noqa: E402
import luminate_client as lc  # noqa: E402  (per-tenant machine-admin secrets, contract A.2.2)

E = ct.read_env()
BASE = (sys.argv[1] if len(sys.argv) > 1 else f"http://{E['LAN_HOST']}:{E.get('API_PORT', '8080')}").rstrip("/")
KEY = E["TENANT_CONTEXT_KEY"]
ADMIN = E["INITIAL_USER_EMAIL"]  # the password is per tenant: lc.password(tid)
BUCKET = E.get("S3_BUCKET", "wellms")
RUN = uuid.uuid4().hex[:8]
MC_IMAGE = "minio/mc@sha256:a7fe349ef4bd8521fb8497f55c6042871b2ae640607cf99d9bede5e9bdf11727"
results = []


def check(name, ok, detail=""):
    results.append(ok)
    print(f"{'PASS' if ok else 'FAIL'}  {name}" + (f"  [{detail}]" if detail and not ok else ""))


def sh(args, stdin=None, timeout=120):
    p = subprocess.run(args, input=stdin, capture_output=True, text=True, timeout=timeout)
    return p.returncode, p.stdout.strip(), p.stderr.strip()


def mc(*cmd):
    """Run mc against the stack's MinIO as root, in a throwaway container on the compose network."""
    script = f"mc alias set l http://minio:9000 '{E['MINIO_ROOT_USER']}' '{E['MINIO_ROOT_PASSWORD']}' >/dev/null && mc {' '.join(cmd)}"
    return sh(["docker", "run", "--rm", "--network", "luminate-wellms_default", "--entrypoint", "sh", MC_IMAGE, "-c", script])[1]


def objects():
    """{key: size} for every object in the bucket."""
    out = {}
    for line in mc("ls", "--recursive", f"l/{BUCKET}/").splitlines():
        m = re.match(r"\[.*?\]\s+(\S+)\s+\S+\s+(.*)$", line.strip())
        if m:
            out[m.group(2)] = m.group(1)
    return out


def cat(key):
    """Object content as BYTES (objects may be binary)."""
    script = f"mc alias set l http://minio:9000 '{E['MINIO_ROOT_USER']}' '{E['MINIO_ROOT_PASSWORD']}' >/dev/null && mc cat l/{BUCKET}/{key}"
    p = subprocess.run(["docker", "run", "--rm", "--network", "luminate-wellms_default", "--entrypoint", "sh", MC_IMAGE, "-c", script], capture_output=True, timeout=120)
    return p.stdout


# a real 1x1 PNG: Wellms only accepts media/office/image types, and sniffs the content, not the extension
PNG = __import__("base64").b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==")


def artisan(*a):
    return sh(["docker", "exec", "-u", "www-data", "luminate-wellms-api-1", "php", "artisan", *a])


def call(method, path, tid=None, bearer=None, body=None, raw=None, headers=None):
    h = {"Accept": "application/json", **(headers or {})}
    if tid:
        h["X-Tenant-Context"] = ct.mint(KEY, tid)
    if bearer:
        h["Authorization"] = "Bearer " + bearer
    data = raw
    if body is not None:
        data = json.dumps(body).encode()
        h["Content-Type"] = "application/json"
    req = urllib.request.Request(BASE + path, data=data, method=method, headers=h)
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            b = r.read()
            return r.status, (json.loads(b) if b else None)
    except urllib.error.HTTPError as e:
        b = e.read().decode()
        try:
            return e.code, json.loads(b)
        except Exception:
            return e.code, b[:300]


def multipart(fields, files):
    boundary = "----lum" + uuid.uuid4().hex
    body = b""
    for k, v in fields.items():
        body += f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode()
    for k, (fn, content, ctype) in files.items():
        body += (f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"; filename="{fn}"\r\nContent-Type: {ctype}\r\n\r\n').encode() + content + b"\r\n"
    body += f"--{boundary}--\r\n".encode()
    return body, {"Content-Type": f"multipart/form-data; boundary={boundary}"}


def strings(node):
    if isinstance(node, str):
        yield node
    elif isinstance(node, dict):
        for v in node.values():
            yield from strings(v)
    elif isinstance(node, list):
        for v in node:
            yield from strings(v)


# ======================================================================================================================
print("--- A. CLI: where do the bytes really go? (S3 + local scratch, with and without a tenant)")
probes = {}
for tid in (None, "t-alpha", "t-beta"):
    label = f"{RUN}-cli-{tid or 'none'}"
    rc, out, err = artisan("luminate:tenancy:probe-storage", label, *([tid] if tid else []))
    probes[tid] = json.loads(out.splitlines()[-1])
    probes[tid]["label"] = label
objs = objects()
for tid, want_prefix in ((None, "untenanted/"), ("t-alpha", "tenants/t-alpha/"), ("t-beta", "tenants/t-beta/")):
    p = probes[tid]
    check(f"{tid or 'NO TENANT'}: the object is in MinIO at '{want_prefix}…' and nowhere at the bucket root",
          p["s3_key"] in objs and p["s3_key"].startswith(want_prefix) and f"luminate-probe/{p['label']}.txt" not in objs, p["s3_key"])
    check(f"{tid or 'NO TENANT'}: its content is its own label", cat(p["s3_key"]).strip() == p["label"].encode(), cat(p["s3_key"])[:60])
    check(f"{tid or 'NO TENANT'}: the URL it hands out points inside the same prefix", urllib.parse.urlparse(p["url"]).path.lstrip("/") == f"{BUCKET}/{p['s3_key']}", p["url"])
    rc, _, _ = sh(["docker", "exec", "luminate-wellms-api-1", "test", "-f", p["local_path"]])
    expected_dir = f"/tenants/{tid or 'untenanted'}/"
    check(f"{tid or 'NO TENANT'}: the local scratch file is under {expected_dir}", rc == 0 and expected_dir in p["local_path"], p["local_path"])

# ======================================================================================================================
print("--- B. Queue worker (a separate container, long-lived, switching tenants)")
rc, _, _ = sh(["docker", "exec", "luminate-wellms-redis-1", "redis-cli", "-a", E["REDIS_PASSWORD"], "--no-auth-warning", "ping"])
_notify = sh(["docker", "exec", "luminate-wellms-redis-1", "redis-cli", "-a", E["REDIS_PASSWORD"], "--no-auth-warning", "--scan", "--pattern", "*queues:default:notify"])[1].splitlines()
prefix = _notify[0][: -len("queues:default:notify")] if _notify else ""
probe_key = prefix + "luminate:probe"
sh(["docker", "exec", "luminate-wellms-redis-1", "redis-cli", "-a", E["REDIS_PASSWORD"], "--no-auth-warning", "del", probe_key])
jobs = [f"{RUN}-job-{i}-{t}" for i, t in enumerate(["t-alpha", "t-beta", "t-alpha", "t-beta"])]
for lab in jobs:
    artisan("luminate:tenancy:probe-job", lab, lab.rsplit("-", 2)[-2] + "-" + lab.rsplit("-", 1)[-1])
deadline, got = time.time() + 120, {}
while time.time() < deadline and len(got) < len(jobs):
    for line in sh(["docker", "exec", "luminate-wellms-redis-1", "redis-cli", "-a", E["REDIS_PASSWORD"], "--no-auth-warning", "lrange", probe_key, "0", "-1"])[1].splitlines():
        if line.startswith("{") and json.loads(line)["label"].startswith(RUN):
            got[json.loads(line)["label"]] = json.loads(line)
    time.sleep(2)
check("all worker jobs ran", len(got) == len(jobs), str(len(got)))
objs = objects()
for lab, r in sorted(got.items()):
    want = lab.rsplit("-", 2)[-2] + "-" + lab.rsplit("-", 1)[-1]
    ok = r["tenant"] == want and r["s3_key"].startswith(f"tenants/{want}/") and r["s3_key"] in objs and r["s3_bucket"] == BUCKET
    rc, _, _ = sh(["docker", "exec", "luminate-wellms-worker-1", "test", "-f", r["local_path"]])
    check(f"{lab}: wrote under tenants/{want}/ in MinIO and under /tenants/{want}/ locally (worker container)", ok and rc == 0 and f"/tenants/{want}/" in r["local_path"], json.dumps(r)[:200])

# ======================================================================================================================
print("--- C. The real media API, same filename in two tenants")
tok = {}
for t in ("t-alpha", "t-beta"):
    s, r = call("POST", "/api/auth/login", t, body={"email": ADMIN, "password": lc.password(t)})
    tok[t] = r["data"]["token"] if s == 200 else ""
DIR, NAME = f"docs-{RUN}", f"same-{RUN}.png"
content = {"t-alpha": f"ALPHA-{RUN}", "t-beta": f"BETA-{RUN}"}  # markers appended after the PNG's IEND chunk
uploaded = {}
for t in ("t-alpha", "t-beta"):
    body, hdr = multipart({"target": DIR}, {"file[]": (NAME, PNG + b"\n" + content[t].encode(), "image/png")})
    s, r = call("POST", "/api/admin/file/upload", t, tok[t], raw=body, headers=hdr)
    uploaded[t] = r
    check(f"{t}: uploads '{DIR}/{NAME}' (200)", s == 200, f"{s} {json.dumps(r)[:240]}")

objs = objects()
for t in ("t-alpha", "t-beta"):
    key = f"tenants/{t}/{DIR}/{NAME}"
    check(f"{t}: MinIO holds '{key}' with {t}'s own content", key in objs and content[t].encode() in cat(key), f"present={key in objs}")
    other = "t-beta" if t == "t-alpha" else "t-alpha"
    check(f"{t}: ...and that object contains none of {other}'s content", content[other].encode() not in cat(key))
check("nothing was written at the bucket root or outside a tenant prefix for this directory",
      not [k for k in objs if DIR in k and not k.startswith("tenants/t-")], str([k for k in objs if DIR in k]))
check("the same name in two tenants produced two distinct objects", f"tenants/t-alpha/{DIR}/{NAME}" in objs and f"tenants/t-beta/{DIR}/{NAME}" in objs)

url = {t: next((x for x in strings(uploaded[t]) if DIR in x and x.startswith("http")), None) for t in tok}
print(f"INFO  upload returned URLs: {url}")
check("each tenant is handed a URL inside its own prefix", all(url[t] and f"/tenants/{t}/" in url[t] for t in tok), str(url))

for t, other in (("t-alpha", "t-beta"), ("t-beta", "t-alpha")):
    s, r = call("GET", f"/api/admin/file/list?directory={DIR}", t, tok[t])
    seen = json.dumps(r)
    check(f"{t}: listing '{DIR}' shows its own file and nothing of {other}'s", s == 200 and NAME in seen and f"/tenants/{other}/" not in seen and f"tenants/{other}" not in seen, f"{s} {seen[:200]}")
    s, r = call("GET", f"/api/admin/file/find?directory={DIR}&name={NAME}", t, tok[t])
    seen = json.dumps(r)
    check(f"{t}: find-by-name shows only its own", s == 200 and f"tenants/{other}" not in seen, f"{s} {seen[:200]}")

# ======================================================================================================================
print("--- D. Cross-tenant attempts through the media API (beta attacks alpha's file)")
alpha_key = f"tenants/t-alpha/{DIR}/{NAME}"
alpha_url = url["t-alpha"] or f"http://x/{BUCKET}/{alpha_key}"
attempts = {
    "delete with alpha's full URL": ("DELETE", "/api/admin/file/delete?url=" + urllib.parse.quote(alpha_url, safe=""), None),
    "delete with alpha's key path": ("DELETE", "/api/admin/file/delete?url=" + urllib.parse.quote(alpha_key, safe=""), None),
    "delete with '..' traversal": ("DELETE", "/api/admin/file/delete?url=" + urllib.parse.quote(f"../t-alpha/{DIR}/{NAME}", safe=""), None),
    "delete with encoded traversal": ("DELETE", "/api/admin/file/delete?url=" + urllib.parse.quote(f"{DIR}/../../t-alpha/{DIR}/{NAME}", safe=""), None),
}
for label, (m, path, body) in attempts.items():
    s, r = call(m, path, "t-beta", tok["t-beta"], body=body)
    still = alpha_key in objects() and content["t-alpha"].encode() in cat(alpha_key)
    check(f"beta: {label} -> alpha's object is intact (response {s})", still, f"{s} {str(r)[:100]}")

# move: beta tries to pull alpha's file into its own area, and to push its file over alpha's
for label, (src, dst) in {
    "move alpha's file to beta's area": (alpha_url, f"{DIR}/stolen-{RUN}.txt"),
    "move alpha's key path to beta's area": (alpha_key, f"{DIR}/stolen2-{RUN}.txt"),
    "overwrite alpha's file with beta's": (f"{DIR}/{NAME}", alpha_url),
    "overwrite via '..' path": (f"{DIR}/{NAME}", f"../t-alpha/{DIR}/{NAME}"),
}.items():
    body, hdr = multipart({"source_url": src, "destination_url": dst}, {})
    s, r = call("POST", "/api/admin/file/move", "t-beta", tok["t-beta"], raw=body, headers=hdr)
    objs = objects()
    intact = alpha_key in objs and content["t-alpha"].encode() in cat(alpha_key)
    leaked = any(content["t-alpha"].encode() in cat(k) for k in objs if k.startswith("tenants/t-beta/") and "stolen" in k)
    check(f"beta: {label} -> alpha's object unchanged, no copy in beta's area (response {s})", intact and not leaked, f"intact={intact} leaked={leaked} {str(r)[:80]}")

s, r = call("DELETE", "/api/admin/file/delete?url=" + urllib.parse.quote(url["t-alpha"] or alpha_key, safe=""), "t-alpha", tok["t-alpha"])
check("sanity: alpha CAN delete its own file with its own URL", s == 200 and alpha_key not in objects(), f"{s} {str(r)[:100]}")

# ======================================================================================================================
print("--- E. Direct access to an object URL")
beta_url = url["t-beta"]
try:
    with urllib.request.urlopen(urllib.request.Request(beta_url or f"http://{E['LAN_HOST']}:{E['S3_PORT']}/{BUCKET}/tenants/t-beta/{DIR}/{NAME}"), timeout=20) as resp:
        anon = resp.status
except urllib.error.HTTPError as e:
    anon = e.code
check("an anonymous GET of a stored object URL is refused (bucket is private)", anon in (401, 403), str(anon))

print(f"\n{sum(results)}/{len(results)} passed")
sys.exit(0 if all(results) else 1)
