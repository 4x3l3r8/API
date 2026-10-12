"""Pooled spike: tenant-tagged jobs. Real Wellms job: MarkAttemptAsEnded(attemptId), delayed to the attempt's end_at.
Usage: p17_queue.py <label>   (env flags are set outside)"""
import sys, os, json, time, subprocess
sys.path.insert(0, os.path.dirname(__file__))
from wl import W

label = sys.argv[1]
ids = json.load(open(os.path.join(os.path.dirname(__file__), "..", "evidence", ".pooled_ids.json")))
DC = ["docker", "exec", "wellms-api-1"]
PW = "Passw0rd!eval"


def sql(q):
    r = subprocess.run(["docker", "exec", "wellms-postgres-1", "psql", "-U", "default", "-d", "pooled", "-At", "-c", q], capture_output=True, text=True)
    return r.stdout.strip()


c = W("pooled-c.localhost"); c.login("admin@pooled.test", "pooled-secret")
# restore a usable student role (an earlier probe deliberately narrowed it) from the default tenant's student role
a0 = W("api.localhost"); a0.login("admin@escolalms.com", "secret")
perms = [p["name"] for p in a0.call("GET", "/api/admin/roles/student", quiet=True)[1]["data"] if p["assigned"]]
c.call("PATCH", "/api/admin/roles/student", {"permissions": perms}, quiet=True)
c.call("PUT", f"/api/admin/gift-quizes/{ids['c_quiz']}", {"value": "Q", "max_attempts": 20, "max_execution_time": 1}, quiet=True)
s = W("pooled-c.localhost"); code, js = s.call("POST", "/api/auth/login", {"email": "carol@c.test", "password": PW}, quiet=True); s.token = js["data"]["token"]
code, js = s.call("POST", "/api/quiz-attempts", {"topic_gift_quiz_id": ids["c_quiz"]}, quiet=True)
att = js["data"]["id"]
print(f"[{label}] carol started 1-minute attempt {att}: end_at", js["data"]["end_at"], "| is_ended:", js["data"]["is_ended"])
time.sleep(70)
# ONE shared worker (domain c) drains the queue, as a pooled deployment would have
r = subprocess.run(DC + ["php", "artisan", "queue:work", "--queue=default", "--domain=pooled-c.localhost", "--stop-when-empty", "--tries=1"], capture_output=True, text=True)
lines = [l.strip() for l in r.stdout.splitlines() if "MarkAttemptAsEnded" in l]
print(f"[{label}] worker output:", lines[:3] or "(no MarkAttemptAsEnded job ran)")
print(f"[{label}] attempt {att} in DB (end_at, result_score):", sql(f"select end_at, result_score from topic_gift_quiz_attempts where id={att}"))
code, js = s.call("GET", f"/api/quiz-attempts/{att}", quiet=True)
print(f"[{label}] API says is_ended:", js.get("data", {}).get("is_ended"), "result_score:", js.get("data", {}).get("result_score"))
