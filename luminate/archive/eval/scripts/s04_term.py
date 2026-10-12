import sys, os, json
sys.path.insert(0, os.path.dirname(__file__))
from wl import W

PW = "Passw0rd!eval"
a = W("api.localhost"); a.login("admin@escolalms.com", "secret")
s = W("api.localhost"); s.login("stud.ab@example.test", PW)


def prog():
    c, js = s.call("GET", "/api/courses/2/program", quiet=True)
    return c, (js.get("message") or "")[:45], len(js.get("data", {}).get("lessons", [])) if c == 200 else ""


def window(f, t):
    a.call("POST", "/api/admin/courses/2", {"title": "CSC101 Introduction to Computing", "active_from": f, "active_to": t}, quiet=True)


print("== active window enforcement")
window("2025-09-15 00:00:00", "2026-02-15 23:59:59"); print("  window ended in past  ->", prog())
window("2027-09-15 00:00:00", "2028-02-15 23:59:59"); print("  window starts future  ->", prog())
window("2026-09-01 00:00:00", "2027-02-15 23:59:59"); print("  window includes today ->", prog())

print("== per-user deadline/end_date through the API? (course_user.deadline/end_date columns exist)")
for body in ({"users": [12], "deadline": "2027-01-31 23:59:59"}, {"users": [12], "end_date": "2027-01-31 23:59:59"}):
    c, js = a.call("POST", "/api/admin/courses/2/access/add", body, quiet=True)
    print("  access/add", list(body)[1:], "->", c, (js.get("message") or "")[:50])
c, js = s.call("GET", "/api/courses/progress", quiet=True)
row = [x for x in js["data"] if x["course"]["id"] == 2]
print("  learner progress row deadline/end_date:", {k: row[0].get(k) for k in ("deadline", "end_date")} if row else None)

print("== next term: clone the course (copies content, not enrolment?)")
c, js = a.call("GET", "/api/admin/courses/2/clone", quiet=True)
d = js.get("data", {})
print("  clone ->", c, "new id", d.get("id"), "title:", d.get("title"), "| fields:", d.get("fields"), "| authors:", [x["email"] for x in d.get("authors", [])], "| lessons:", len(d.get("lessons", [])), "| status:", d.get("status"))
if d.get("id"):
    c, acc = a.call("GET", f"/api/admin/courses/{d['id']}/access", quiet=True)
    print("  clone access:", acc.get("data"))
    a.call("POST", f"/api/admin/courses/{d['id']}", {"title": "CSC101 Introduction to Computing", "fields": {"academic_session": "2026/2027", "academic_term": "Semester 1", "course_code": "CSC101", "sis_course_run_id": "CSC101-2026-S1"}}, quiet=True)
    c, js = a.call("GET", f"/api/admin/courses/{d['id']}", quiet=True)
    print("  clone fields now:", js["data"].get("fields"))

print("== cohort granularity: can one cohort get different dates/content than the other?")
c, js = a.call("POST", "/api/admin/courses/2/access/add", {"groups": [2], "deadline": "2027-01-01"}, quiet=True)
print("  per-group deadline accepted? ->", c, "(course_group has only course_id, group_id, timestamps: no per-cohort columns)")

print("== remove one cohort: does the cross-listed student keep access via the other group?")
a.call("POST", "/api/admin/courses/2/access/remove", {"groups": [2]}, quiet=True)
c, js = s.call("GET", "/api/courses/my", quiet=True); print("  after removing CS group, stud.ab sees:", js["data"]["ids"])
sA = W("api.localhost"); sA.login("stud.a@example.test", PW)
c, js = sA.call("GET", "/api/courses/my", quiet=True); print("  stud.a (CS only) sees:", js["data"]["ids"])
a.call("POST", "/api/admin/courses/2/access/add", {"groups": [2]}, quiet=True)
c, js = sA.call("GET", "/api/courses/my", quiet=True); print("  after re-adding, stud.a sees:", js["data"]["ids"])

print("== group membership change takes effect immediately?")
a.call("DELETE", "/api/admin/user-groups/3/members/12", quiet=True)
a.call("POST", "/api/admin/courses/2/access/remove", {"groups": [2]}, quiet=True)
c, js = s.call("GET", "/api/courses/my", quiet=True); print("  stud.ab removed from IT group + CS group access removed ->", js["data"]["ids"])
a.call("POST", "/api/admin/courses/2/access/add", {"groups": [2]}, quiet=True)
a.call("POST", "/api/admin/user-groups/3/members", {"user_id": 12}, quiet=True)
