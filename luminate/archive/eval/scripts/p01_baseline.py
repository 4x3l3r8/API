"""Pooled follow-up, test 1: two domains -> ONE database, no tenant logic. What happens?"""
import sys, os, json
sys.path.insert(0, os.path.dirname(__file__))
from wl import W

c = W("pooled-c.localhost"); d = W("pooled-d.localhost")
print("name C:", c.call("GET", "/api/name", quiet=True)[1].get("_raw"), "| name D:", d.call("GET", "/api/name", quiet=True)[1].get("_raw"))
c.login("admin@pooled.test", "pooled-secret")
print("admin login on C ok; same credentials on D:", end=" ")
code, js = d.call("POST", "/api/auth/login", {"email": "admin@pooled.test", "password": "pooled-secret"}, quiet=True)
print(code, "(shared users table => same admin exists on both)")
d.login("admin@pooled.test", "pooled-secret")

print("== C creates a course + a user; D (different 'tenant', same DB) reads")
code, js = c.call("POST", "/api/admin/courses", {"title": "SECRET course of tenant C", "status": "published"}, quiet=True)
cid = js["data"]["id"]
code, js = c.call("POST", "/api/admin/users", {"email": "carol@c.test", "first_name": "Carol", "last_name": "C", "password": "Passw0rd!eval", "password_confirmation": "Passw0rd!eval", "roles": ["student"], "verified": True, "return_url": "http://localhost/v"}, quiet=True)
print("  create user on C ->", code)
code, js = d.call("GET", "/api/admin/courses", quiet=True)
print("  D lists courses:", [x["title"] for x in js["data"]])
code, js = d.call("GET", "/api/admin/users?per_page=50", quiet=True)
print("  D lists users:", [x["email"] for x in js["data"]])
code, js = c.call("POST", "/api/auth/login", {"email": "carol@c.test", "password": "Passw0rd!eval"}, quiet=True)
print("  carol (created on C) logs in on D:", d.call("POST", "/api/auth/login", {"email": "carol@c.test", "password": "Passw0rd!eval"}, quiet=True)[0])
print("  C's token accepted on D (shared keys + shared users):", end=" ")
d2 = W("pooled-d.localhost"); d2.token = c.token
print(d2.call("GET", "/api/profile/me", quiet=True)[0])
code, js = d.call("DELETE", f"/api/admin/courses/{cid}", quiet=True)
print("  D deletes C's course ->", code)
open(os.path.join(os.path.dirname(__file__), "..", "evidence", ".pooled"), "w").write(json.dumps({"c_token_len": len(c.token)}))
