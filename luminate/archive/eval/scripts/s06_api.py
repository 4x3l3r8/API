"""S6 part 2: pagination/filtering/bulk/errors/idempotency/versioning on a Wellms tenant (tenant B, a clean one)."""
import sys, os, json, time, io, urllib.request, urllib.error
sys.path.insert(0, os.path.dirname(__file__))
from wl import W, BASE

HOST = "tenant-b.localhost"
a = W(HOST); a.login("admin@tenant-b.test", "beta-secret")
PW = "Passw0rd!eval"

print("== BULK: CSV user import (POST /api/admin/csv/users), 60 fake users")
rows = ["email,first_name,last_name,password"] + [f"bulk{i:03d}@tenant-b.test,Bulk{i:03d},User,{PW}" for i in range(60)]
csv = "\n".join(rows).encode()
boundary = "----eval"
CRLF = bytes([13, 10])
def part(name, value, fname=None, ctype=None):
    head = ("--" + boundary).encode() + CRLF
    disp = 'Content-Disposition: form-data; name="%s"' % name + ('; filename="%s"' % fname if fname else "")
    head += disp.encode() + CRLF
    if ctype:
        head += ("Content-Type: " + ctype).encode() + CRLF
    return head + CRLF + (value if isinstance(value, bytes) else value.encode()) + CRLF
body = part("file", csv, "u.csv", "text/csv") + part("return_url", "http://localhost/verify") + ("--" + boundary + "--").encode() + CRLF
req = urllib.request.Request(BASE + "/api/admin/csv/users", data=body, method="POST", headers={
    "Host": HOST, "Authorization": "Bearer " + a.token, "Accept": "application/json", "Content-Type": f"multipart/form-data; boundary={boundary}"})
t0 = time.time()
try:
    with urllib.request.urlopen(req, timeout=120) as r:
        code, txt = r.status, r.read().decode()
except urllib.error.HTTPError as e:
    code, txt = e.code, e.read().decode()
print("  ->", code, txt[:240].replace("\n", " "), f"({time.time()-t0:.1f}s)")
time.sleep(8)
c, js = a.call("GET", "/api/admin/users?per_page=1", quiet=True)
print("  users now:", js.get("meta", {}).get("total"))

print("== PAGINATION shape")
c, js = a.call("GET", "/api/admin/users?per_page=10&page=2", quiet=True)
print("  keys:", list(js.keys()), "| meta keys:", list(js["meta"].keys()))
print("  page2 count", len(js["data"]), "from", js["meta"].get("from"), "to", js["meta"].get("to"), "total", js["meta"].get("total"), "last_page", js["meta"].get("last_page"))
for pp in (0, 1000, 100000, -5, "abc"):
    c, js = a.call("GET", f"/api/admin/users?per_page={pp}", quiet=True)
    print(f"  per_page={pp!r:8} -> {c} rows={len(js['data']) if isinstance(js.get('data'), list) else js.get('message')}")
c, js = a.call("GET", "/api/admin/users?page=9999&per_page=10", quiet=True)
print("  page beyond last ->", c, "rows", len(js["data"]))

print("== FILTER / SORT")
c, js = a.call("GET", "/api/admin/users?search=bulk01&per_page=50", quiet=True)
print("  search=bulk01 ->", js["meta"]["total"], "rows; first", js["data"][0]["email"] if js["data"] else None)
c, js = a.call("GET", "/api/admin/users?role=student&per_page=1", quiet=True); print("  role=student total", js["meta"]["total"])
c, js = a.call("GET", "/api/admin/users?role=admin&per_page=1", quiet=True); print("  role=admin total", js["meta"]["total"])
c, js = a.call("GET", "/api/admin/users?status=1&per_page=1", quiet=True); print("  status=1 total", js["meta"]["total"], "(", c, ")")
c, js = a.call("GET", "/api/admin/users?order_by=id&order=desc&per_page=3", quiet=True); print("  order_by=id desc ->", [u["id"] for u in js["data"]])
c, js = a.call("GET", "/api/admin/users?order_by=email&order=asc&per_page=3", quiet=True); print("  order_by=email asc ->", [u["email"] for u in js["data"]], c)
c, js = a.call("GET", "/api/admin/users?from=2020-01-01&to=2020-01-02&per_page=3", quiet=True); print("  created from/to 2020 ->", js["meta"]["total"])
c, js = a.call("GET", "/api/admin/users?bogus_filter=1&per_page=1", quiet=True); print("  unknown filter ->", c, "(silently ignored)" if c == 200 else "")

print("== BULK enrol: 50 users in one call")
c, js = a.call("GET", "/api/admin/users?search=bulk0&per_page=50", quiet=True)
ids = [u["id"] for u in js["data"]][:50]
c, js = a.call("POST", "/api/admin/courses", {"title": "Bulk target", "status": "published"}, quiet=True); cid = js["data"]["id"]
t0 = time.time(); c, js = a.call("POST", f"/api/admin/courses/{cid}/access/add", {"users": ids}, quiet=True)
print(f"  access/add {len(ids)} users -> {c} in {time.time()-t0:.1f}s, now enrolled:", len(js["data"]["users"]))
t0 = time.time(); c, js = a.call("POST", f"/api/admin/courses/{cid}/access/add", {"users": ids}, quiet=True)
print(f"  repeat same call -> {c} (idempotent? enrolled still {len(js['data']['users'])})")
c, js = a.call("POST", f"/api/admin/courses/{cid}/access/add", {"users": [999999]}, quiet=True)
print("  unknown user id in list ->", c, (js.get("message") or js.get("error") or "")[:70], json.dumps(js.get("errors"))[:120] if js.get("errors") else "")
open(os.path.join(os.path.dirname(__file__), "..", "evidence", ".s06b"), "w").write(json.dumps({"cid": cid}))
