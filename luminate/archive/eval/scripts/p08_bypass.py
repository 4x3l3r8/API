"""Pooled spike (extended scope set): hunt for the paths a model-level global scope does NOT cover."""
import sys, os, json, subprocess
sys.path.insert(0, os.path.dirname(__file__))
from wl import W

ids = json.load(open(os.path.join(os.path.dirname(__file__), "..", "evidence", ".pooled_ids.json")))
DC = "docker compose -f api/docker-compose.yml -f docker-compose.override.yml -f docker-compose.tenants.yml --project-directory api -p wellms".split()
PW = "Passw0rd!eval"


def sql(q):
    r = subprocess.run(DC + ["exec", "-T", "postgres", "psql", "-U", "default", "-d", "pooled", "-At", "-F", "|", "-c", q], capture_output=True, text=True)
    return r.stdout.strip().replace("\n", " ; ")


d = W("pooled-d.localhost"); d.login("admin@d.test", "d-secret")
c = W("pooled-c.localhost"); c.login("admin@pooled.test", "pooled-secret")

print("1) Validation-rule bypass: D linked C's user (id 2) to D's course (id 3) via exists: rule")
print("   course_user rows:", sql("select user_id, course_id from course_user order by 1,2"))
print("   course 3 tenant:", sql("select tenant_id from courses where id=3"), "| user 2 tenant:", sql("select tenant_id from users where id=2"))

print("2) Cross-tenant pivot consequences: what can carol (tenant C) now do?")
s = W("pooled-c.localhost"); code, js = s.call("POST", "/api/auth/login", {"email": "carol@c.test", "password": PW}, quiet=True); s.token = js["data"]["token"]
print("   carol on C /api/courses/my:", s.call("GET", "/api/courses/my", quiet=True)[1].get("data"))
print("   carol on C reads D's course 3:", s.call("GET", f"/api/courses/{ids['d_course']}", quiet=True)[0])
sd = W("pooled-d.localhost"); sd.token = s.token
for path in ("/api/profile/me", "/api/courses/my", f"/api/courses/{ids['d_course']}"):
    code, js = sd.call("GET", path, quiet=True)
    print(f"   carol's C-token on D host {path}: {code} {(js.get('message') or js.get('error') or '')[:60]}")

print("3) Reports/metrics (raw DB:: joins) from D: do they include C's data?")
for m in ("CoursesPopularityMetric", "CoursesMoneySpentMetric", "CoursesSecondsSpentMetric", "TutorsPopularityMetric", "CoursesBestRatedMetric", "CoursesTopSellingMetric"):
    metric = "EscolaLms" + chr(92) + "Reports" + chr(92) + "Metrics" + chr(92) + m
    for name, w in (("C", c), ("D", d)):
        code, js = w.call("GET", "/api/admin/reports/report?metric=" + metric.replace(chr(92), "%5C") + "&limit=10", quiet=True)
        body = json.dumps(js)
        print(f"   {m:28s} as {name}: {code} {body[:150] if code != 200 else ('C-CONFIDENTIAL' in body and 'CONTAINS C-CONFIDENTIAL' or 'no C marker') + ' | ' + body[40:140]}")

print("4) Course stats of D's own course after the foreign link (does D now see C's user data?)")
code, js = d.call("GET", f"/api/admin/stats/course/{ids['d_course']}", quiet=True)
print("  ", code, "carol@c.test in D's stats:", "carol@c.test" in json.dumps(js))

print("5) Aggregates & dashboards")
for p in ("/api/admin/stats/available", "/api/admin/orders?per_page=5", "/api/admin/users?per_page=5&role=student"):
    code, js = d.call("GET", p, quiet=True); print(f"   {p}: {code} {'C marker' if 'carol' in json.dumps(js).lower() else 'no C marker'}")
