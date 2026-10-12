import sys, os, json
sys.path.insert(0, os.path.dirname(__file__))
from wl import W

PW = "Passw0rd!eval"
CID = 2


def as_user(email):
    w = W("api.localhost"); w.login(email, PW); return w


print("== learners: which courses do they see? (course 2 = CSC101)")
for email in ["stud.a@example.test", "stud.b@example.test", "stud.ab@example.test", "student.one@example.test"]:
    w = as_user(email)
    c, js = w.call("GET", "/api/courses/my", quiet=True)
    ids = js.get("data", {}).get("ids")
    c2, p = w.call("GET", f"/api/courses/{CID}/program", quiet=True)
    print(f"  {email:30s} my={ids} program(2)->{c2}")

print("== lecturers")
for email in ["lect.one@example.test", "lect.two@example.test"]:
    w = as_user(email)
    c, js = w.call("GET", "/api/courses/authored", quiet=True)
    titles = [x["title"] for x in js.get("data", [])] if isinstance(js.get("data"), list) else js
    print(f"  {email}: authored={titles}")
    c, js = w.call("POST", f"/api/admin/courses/{CID}", {"title": "CSC101 Introduction to Computing", "summary": f"edited by {email}"}, quiet=True)
    print(f"    update own course -> {c} {js.get('message') or js.get('error')}")
    c, js = w.call("POST", "/api/admin/lessons", {"title": f"Lesson by {email}", "order": 2, "course_id": CID}, quiet=True)
    print(f"    add lesson to own course -> {c} {js.get('message') or js.get('error')}")
    c, js = w.call("POST", f"/api/admin/courses/1", {"title": "hijack"}, quiet=True)
    print(f"    update someone else's course 1 -> {c} {js.get('message') or js.get('error')}")
    c, js = w.call("GET", f"/api/admin/quiz-attempts?course_id={CID}", quiet=True)
    print(f"    read quiz attempts of own course -> {c}")
    c, js = w.call("GET", "/api/admin/users?per_page=3", quiet=True)
    print(f"    list all users -> {c} {(js.get('message') or js.get('error') or '')[:50]}")

print("== admin: who has access (users via groups?)")
a = W("api.localhost"); a.login("admin@escolalms.com", "secret")
c, js = a.call("GET", f"/api/admin/courses/{CID}/access", quiet=True)
print("  access groups:", [g["name"] for g in js["data"]["groups"]], "users:", js["data"]["users"])
c, js = a.call("GET", f"/api/admin/stats/course/{CID}", quiet=True)
print("  stats keys:", [k.split(chr(92))[-1] for k in js.get("data", {})])
c, js = a.call("GET", f"/api/admin/courses?search=CSC101&per_page=5", quiet=True)
print("  search CSC101 ->", [(x["id"], x["title"]) for x in js.get("data", [])])
for q in ["fields[academic_term]=Semester%201", "academic_term=Semester%201", "tag=2025%2F2026"]:
    c, js = a.call("GET", f"/api/admin/courses?{q}&per_page=5", quiet=True)
    print(f"  filter {q} ->", c, [(x['id']) for x in js.get('data', [])] if isinstance(js.get('data'), list) else js.get('message'))
