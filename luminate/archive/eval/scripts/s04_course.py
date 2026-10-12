import sys, os, json
sys.path.insert(0, os.path.dirname(__file__))
from wl import W
w = W("api.localhost"); w.login("admin@escolalms.com", "secret")
L1, L2, gA, gB = 8, 9, 2, 3
c, js = w.call("POST", "/api/admin/courses", {
    "title": "CSC101 Introduction to Computing", "summary": "2025/2026 Semester 1", "status": "published",
    "authors": [L1, L2], "active_from": "2025-09-15 00:00:00", "active_to": "2026-02-15 23:59:59",
    "fields": {"academic_session": "2025/2026", "academic_term": "Semester 1", "course_code": "CSC101", "sis_course_run_id": "CSC101-2025-S1"}})
d = js.get("data", {})
print("course", d.get("id"), "authors:", [a["email"] for a in d.get("authors", [])], "\n fields:", json.dumps(d.get("fields")), "\n active:", d.get("active_from"), d.get("active_to"))
cid = d["id"]
w.call("POST", "/api/admin/lessons", {"title": "Week 1", "order": 1, "course_id": cid})
c, js = w.call("POST", f"/api/admin/courses/{cid}/access/add", {"groups": [gA, gB]})
print("access:", json.dumps(js.get("data"))[:400])
open("evidence/.s04", "w").write(json.dumps({"course": cid}))
