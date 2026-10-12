"""S4: model CSC101 x 2025/2026 x Semester 1, two cohort groups, two lecturers, cross-listed student."""
import sys, os, json
sys.path.insert(0, os.path.dirname(__file__))
from wl import W

w = W("api.localhost")
w.login("admin@escolalms.com", "secret")
PW = "Passw0rd!eval"


def user(email, first, role):
    c, js = w.call("POST", "/api/admin/users", {
        "email": email, "first_name": first, "last_name": "Eval", "password": PW, "password_confirmation": PW,
        "roles": [role], "is_active": True, "verified": True, "return_url": "http://localhost/verify"})
    return js["data"]["id"] if c == 201 else None


if os.path.exists(os.path.join(os.path.dirname(__file__),"..","evidence",".s04pre")): pass
print("roles:", [r["name"] for r in w.call("GET", "/api/admin/roles", quiet=True)[1]["data"]])
print("-- lecturers")
L1 = user("lect.one@example.test", "LecturerOne", "tutor")
L2 = user("lect.two@example.test", "LecturerTwo", "tutor")
print("-- students")
S1 = user("stud.a@example.test", "StudA", "student")
S2 = user("stud.b@example.test", "StudB", "student")
S3 = user("stud.ab@example.test", "StudAB", "student")
print("ids", L1, L2, S1, S2, S3)

print("-- group hierarchy: 2025/2026 S1 > programme offerings")
c, js = w.call("POST", "/api/admin/user-groups/", {"name": "2025/2026 S1", "registerable": False})
root = js["data"]["id"]
c, js = w.call("POST", "/api/admin/user-groups/", {"name": "BSc Computer Science Y1 (2025/26)", "parent_id": root, "registerable": False})
gA = js["data"]["id"]
c, js = w.call("POST", "/api/admin/user-groups/", {"name": "BSc Information Technology Y1 (2025/26)", "parent_id": root, "registerable": False})
gB = js["data"]["id"]
for g, us in ((gA, [S1, S3]), (gB, [S2, S3])):
    for u in us:
        w.call("POST", f"/api/admin/user-groups/{g}/members", {"user_id": u})
c, js = w.call("GET", "/api/admin/user-groups/tree/")
print("tree:", json.dumps(js.get("data"))[:400])

print("-- course with 2 authors + academic fields")
c, js = w.call("POST", "/api/admin/courses", {
    "title": "CSC101 Introduction to Computing", "summary": "2025/2026 Semester 1", "status": "published",
    "authors": [L1, L2],
    "active_from": "2025-09-15 00:00:00", "active_to": "2026-02-15 23:59:59",
    "fields": {"academic_session": "2025/2026", "academic_term": "Semester 1", "course_code": "CSC101", "sis_course_run_id": "CSC101-2025-S1"},
})
cid = js["data"]["id"]
print("course", cid, "authors:", [a["email"] for a in js["data"].get("authors", [])], "fields:", js["data"].get("fields"))
open(os.path.join(os.path.dirname(__file__), "..", "evidence", ".s04"), "w").write(json.dumps(
    {"course": cid, "L1": L1, "L2": L2, "S1": S1, "S2": S2, "S3": S3, "gA": gA, "gB": gB, "root": root}))

print("-- add a lesson+topic so enrolled learners have something to see")
w.call("POST", "/api/admin/lessons", {"title": "Week 1", "order": 1, "course_id": cid})

print("-- enrol BOTH groups")
c, js = w.call("POST", f"/api/admin/courses/{cid}/access/add", {"groups": [gA, gB]})
print("access:", json.dumps(js.get("data"))[:300])
