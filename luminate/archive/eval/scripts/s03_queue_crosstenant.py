"""S3 follow-up: shared Redis queue + id collision => can tenant B's worker act on tenant A's job?
Real job used: EscolaLms\\TopicTypeGift\\Jobs\\MarkAttemptAsEnded(attemptId), delayed until the attempt's end_at."""
import sys, os, json, time, subprocess
sys.path.insert(0, os.path.dirname(__file__))
from wl import W

BS = chr(92)
GIFT = BS.join(["EscolaLms", "TopicTypeGift", "Models", "GiftQuiz"])
PW = "Passw0rd!eval"
DC = "docker compose -f api/docker-compose.yml -f docker-compose.override.yml -f docker-compose.tenants.yml --project-directory api -p wellms".split()


def sh(args, **kw):
    return subprocess.run(DC + args, capture_output=True, text=True, **kw)


def sql(db, q):
    return sh(["exec", "-T", "postgres", "psql", "-U", "default", "-d", db, "-tAc", q]).stdout.strip()


def make_quiz(w, minutes, attempts):
    c, js = w.call("POST", "/api/admin/courses", {"title": "Q course", "status": "published"}, quiet=True); cid = js["data"]["id"]
    c, js = w.call("POST", "/api/admin/lessons", {"title": "L", "order": 1, "course_id": cid}, quiet=True); lid = js["data"]["id"]
    c, js = w.call("POST", "/api/admin/topics", {"title": "Q", "lesson_id": lid, "order": 1, "active": 1, "topicable_type": GIFT, "value": "Q"}, form=True, quiet=True)
    qid = js["data"]["topicable_id"]
    w.call("PUT", f"/api/admin/gift-quizes/{qid}", {"value": "Q", "max_attempts": attempts, "max_execution_time": minutes}, quiet=True)
    w.call("POST", "/api/admin/gift-questions", {"topic_gift_quiz_id": qid, "score": 1, "order": 1, "value": "1+1? {=2}"}, quiet=True)
    return cid, qid


A = W("tenant-a.localhost"); A.login("admin@tenant-a.test", "alpha-secret")
B = W("tenant-b.localhost"); B.login("admin@tenant-b.test", "beta-secret")

# Tenant B: long quiz (600 min), student takes attempts until id 3 is open
cidB, qidB = make_quiz(B, 600, 10)
B.call("POST", f"/api/admin/courses/{cidB}/access/add", {"users": [2]}, quiet=True)   # bob = user 2
sB = W("tenant-b.localhost"); sB.login("bob@tenant-b.test", PW)
ids = []
TARGET = int(sql("tenant_a", "select coalesce(max(id),0)+3 from topic_gift_quiz_attempts"))
while True:
    c, js = sB.call("POST", "/api/quiz-attempts", {"topic_gift_quiz_id": qidB}, quiet=True)
    aid = js["data"]["id"]; ids.append(aid)
    if aid >= TARGET:
        break
    sB.call("POST", f"/api/quiz-attempts/{aid}/end", quiet=True)
print("tenant B open attempt id:", ids[-1], "| ended flag in DB (end_at far in future => open):", sql("tenant_b", f"select id, end_at from topic_gift_quiz_attempts where id={ids[-1]}"))
openB = ids[-1]

# Tenant A: 1-minute quiz, attempt whose id == B's open attempt id
cidA, qidA = make_quiz(A, 1, 10)
A.call("POST", f"/api/admin/courses/{cidA}/access/add", {"users": [3]}, quiet=True)   # carol = user 3
sA = W("tenant-a.localhost"); sA.login("carol@tenant-a.test", PW)
aidA = None
for _ in range(60):
    c, js = sA.call("POST", "/api/quiz-attempts", {"topic_gift_quiz_id": qidA}, quiet=True)
    aidA = js["data"]["id"]
    if aidA == openB:
        break
    sA.call("POST", f"/api/quiz-attempts/{aidA}/end", quiet=True)
print("tenant A attempt id:", aidA, "(same id as B's open attempt)" if aidA == openB else "(no id match; abort)")
if aidA != openB:
    sys.exit("could not align ids")

before = sql("tenant_b", f"select id, end_at from topic_gift_quiz_attempts where id={openB}")
print("B attempt BEFORE:", before)
sh(["exec", "-T", "api", "sh", "-c", "supervisorctl stop laravel-multidomain-queue:* >/dev/null 2>&1"])
print("workers stopped; waiting for A's 1-minute attempt deadline (the delayed job)...")
time.sleep(75)
r = sh(["exec", "-T", "api", "php", "artisan", "queue:work", "--queue=default", "--domain=tenant-b.localhost", "--stop-when-empty", "--tries=1"])
out = [l for l in r.stdout.splitlines() if "MarkAttemptAsEnded" in l or "Quiz" in l]
print("TENANT B's worker ran:", out[:4])
after = sql("tenant_b", f"select id, end_at from topic_gift_quiz_attempts where id={openB}")
print("B attempt AFTER :", after)
print("A attempt in A's DB:", sql("tenant_a", f"select id, end_at from topic_gift_quiz_attempts where id={aidA}"))
sh(["exec", "-T", "api", "sh", "-c", "supervisorctl start laravel-multidomain-queue:* >/dev/null 2>&1"])
print("workers restarted")
