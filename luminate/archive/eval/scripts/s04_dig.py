import sys, os, json
sys.path.insert(0, os.path.dirname(__file__))
from wl import W

PW = "Passw0rd!eval"
a = W("api.localhost"); a.login("admin@escolalms.com", "secret")
t = W("api.localhost"); t.login("lect.one@example.test", PW)

print("== (b) tutor 'list users': what do they see? total users in system:")
c, js = a.call("GET", "/api/admin/users?per_page=100", quiet=True)
print("  admin sees", js["meta"]["total"], "users")
c, js = t.call("GET", "/api/admin/users?per_page=100", quiet=True)
print("  tutor sees", js["meta"]["total"], [u["email"] for u in js["data"]])
c, js = t.call("GET", "/api/admin/users/1", quiet=True)
print("  tutor reads admin user id 1 ->", c, (js.get("message") or js.get("error") or "")[:60])
c, js = t.call("GET", "/api/admin/users/4", quiet=True)
print("  tutor reads student NOT in their course (id 4) ->", c, (js.get("message") or js.get("error") or "")[:60])
c, js = t.call("GET", "/api/admin/users/10", quiet=True)
print("  tutor reads student IN their course (id 10) ->", c)
c, js = t.call("GET", "/api/admin/quiz-attempts?course_id=1", quiet=True)
print("  tutor reads quiz attempts of course 1 (not theirs) ->", c, "rows:", len(js.get("data", [])) if isinstance(js.get("data"), list) else js.get("message"))
c, js = t.call("GET", "/api/admin/stats/course/1", quiet=True)
print("  tutor reads stats of course 1 (not theirs) ->", c)

print("== (a) program 404 for group-enrolled learner: add a topic and retry")
c, js = a.call("GET", "/api/admin/courses/2", quiet=True)
print("  course 2 lessons:", [(l["id"], l["title"], len(l.get("topics", []))) for l in js["data"].get("lessons", [])])
lesson_id = js["data"]["lessons"][0]["id"]
c, js = a.call("POST", "/api/admin/topics", {"title": "Welcome", "lesson_id": lesson_id, "order": 1, "active": 1, "can_skip": 0,
               "topicable_type": chr(92).join(["EscolaLms", "TopicTypes", "Models", "TopicContent", "RichText"]), "value": "<p>CSC101</p>"}, form=True)
s = W("api.localhost"); s.login("stud.ab@example.test", PW)
c, js = s.call("GET", "/api/courses/2/program", quiet=True)
print("  stud.ab program ->", c, (js.get("message") or js.get("error") or "")[:60], "| lessons:", len(js.get("data", {}).get("lessons", [])) if c == 200 else "")

print("== progress is per user, not per cohort. Mark S3 complete and read via admin:")
c, js = s.call("GET", "/api/courses/progress/2", quiet=True)
tid = js["data"][0]["topic_id"]
s.call("PATCH", "/api/courses/progress/2", {"progress": [{"topic_id": tid, "status": 1}]}, quiet=True)
c, js = a.call("GET", "/api/admin/stats/course/2", quiet=True)
d = {k.split(chr(92))[-1]: v for k, v in js["data"].items()}
print("  PeopleBought", d.get("PeopleBought"), "PeopleStarted", d.get("PeopleStarted"), "PeopleFinished", d.get("PeopleFinished"))
print("  FinishedCourse:", json.dumps(d.get("FinishedCourse"))[:300])
print("  AttendanceList:", json.dumps(d.get("AttendanceList"))[:200])
print("  FinishedTopics rows:", [(x.get("email")) for x in d.get("FinishedTopics", [])])

print("== (c) cohort-level view: users per group endpoint")
c, js = a.call("GET", "/api/admin/user-groups/users", quiet=True)
print("  groups with users:", [(g["name"][:20], len(g.get("users", []))) for g in js.get("data", [])][:5])
