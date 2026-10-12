import sys, os, json, subprocess
sys.path.insert(0, os.path.dirname(__file__))
from wl import W

PW = "Passw0rd!eval"
DC = "docker compose -f api/docker-compose.yml -f docker-compose.override.yml -f docker-compose.tenants.yml --project-directory api -p wellms".split()


def sql(q):
    r = subprocess.run(DC + ["exec", "-T", "postgres", "psql", "-U", "default", "-d", "default", "-tAc", q], capture_output=True, text=True)
    return r.stdout.strip().replace("\n", " | ")


a = W("api.localhost"); a.login("admin@escolalms.com", "secret")
s = W("api.localhost"); s.login("stud.ab@example.test", PW)   # group-enrolled only (no course_user row)
d = W("api.localhost"); d.login("student.one@example.test", PW)  # directly enrolled on course 1 for comparison

print("course 2 active topics:", sql("select count(*) from topics t join lessons l on l.id=t.lesson_id where l.course_id=2 and t.active"))
c, js = s.call("GET", "/api/courses/progress/2", quiet=True)
tids = [p["topic_id"] for p in js["data"]]
print("group-enrolled learner topics:", tids)
c, js = s.call("PATCH", "/api/courses/progress/2", {"progress": [{"topic_id": t, "status": 1} for t in tids]}, quiet=True)
print("PATCH progress ->", c, [(p["topic_id"], p["status"], bool(p["finished_at"])) for p in js["data"]])
c, js = s.call("GET", "/api/courses/progress", quiet=True)
row = [x for x in js["data"] if x["course"]["id"] == 2][0]
print("learner course view: start_date", row["start_date"], "finish_date", row["finish_date"])
print("course_user row for user 12:", sql("select count(*) from course_user where user_id=12 and course_id=2"), "| finished flag:", sql("select finished from course_user where user_id=12 and course_id=2") or "no row")
c, js = a.call("GET", "/api/admin/stats/course/2", quiet=True)
st = {k.split(chr(92))[-1]: v for k, v in js["data"].items()}
print("admin stats: Started", st["PeopleStarted"], "Finished", st["PeopleFinished"], "| FinishedCourse for 12:", [x for x in st["FinishedCourse"] if x["id"] == 12])

print("-- control: direct enrolment on course 1 (student.one, all topics already complete earlier)")
print("course_user flag for user 4/course 1:", sql("select finished from course_user where user_id=4 and course_id=1"))

print("-- what if we ALSO add a direct row for the group learner, then re-complete?")
a.call("POST", "/api/admin/courses/2/access/add", {"users": [12]}, quiet=True)
s.call("PATCH", "/api/courses/progress/2", {"progress": [{"topic_id": t, "status": 0} for t in tids]}, quiet=True)
s.call("PATCH", "/api/courses/progress/2", {"progress": [{"topic_id": t, "status": 1} for t in tids]}, quiet=True)
print("course_user finished flag with direct row:", sql("select finished from course_user where user_id=12 and course_id=2"))
c, js = a.call("GET", "/api/admin/stats/course/2", quiet=True)
st = {k.split(chr(92))[-1]: v for k, v in js["data"].items()}
print("admin stats: Finished", st["PeopleFinished"], [x for x in st["FinishedCourse"] if x["id"] == 12])
a.call("POST", "/api/admin/courses/2/access/remove", {"users": [12]}, quiet=True)
