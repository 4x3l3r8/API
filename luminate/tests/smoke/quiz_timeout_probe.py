"""Batch 5 prerequisite (contract v4 §F / §H): does a timed-out quiz attempt fire any event, and does the admin attempts API
expose completed AND timed-out attempts for the mandatory reconciliation pull?

    python -I luminate/tests/smoke/quiz_timeout_probe.py        (takes ~2 minutes: a real 1-minute quiz timer)

Dev data only (tenant t-alpha). Five students end in five states; the REAL worker processes the REAL delayed timer; then a
probe runs the job's handle() with the dispatcher faked to record which events it would fire. A control (an attempt that is
still open) proves the probe can see events at all.
"""
import json
import pathlib
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[2] / "tools"))
import context_token as ct  # noqa: E402
import luminate_client as lc  # noqa: E402  (per-tenant machine-admin secrets, contract A.2.2)

E = ct.read_env()
BASE = f"http://{E['LAN_HOST']}:{E.get('API_PORT', '8080')}"
KEY = E["TENANT_CONTEXT_KEY"]
TID, OTHER = "t-alpha", "t-beta"
RUN = uuid.uuid4().hex[:6]
PW = "Fake-Passw0rd!x"
GIFT = "EscolaLms\\TopicTypeGift\\Models\\GiftQuiz"
results = []


def call(method, path, tid=TID, bearer=None, body=None, form=False):
    h = {"Accept": "application/json", "X-Tenant-Context": ct.mint(KEY, tid)}
    if bearer:
        h["Authorization"] = "Bearer " + bearer
    data = None
    if body is not None:
        if form:
            data = urllib.parse.urlencode(body).encode()
            h["Content-Type"] = "application/x-www-form-urlencoded"
        else:
            data = json.dumps(body).encode()
            h["Content-Type"] = "application/json"
    req = urllib.request.Request(BASE + path, data=data, method=method, headers=h)
    try:
        with urllib.request.urlopen(req, timeout=40) as r:
            raw = r.read()
            return r.status, (json.loads(raw) if raw else None)
    except urllib.error.HTTPError as e:
        raw = e.read().decode()
        try:
            return e.code, json.loads(raw)
        except Exception:
            return e.code, raw[:300]


def check(name, ok, detail=""):
    results.append(ok)
    print(f"{'PASS' if ok else 'FAIL'}  {name}" + (f"  [{detail}]" if detail and not ok else ""))


def sh(args, stdin=None):
    p = subprocess.run(args, input=stdin, capture_output=True, text=True)
    return p.returncode, p.stdout.strip(), p.stderr.strip()


def redis(*a):
    return sh(["docker", "exec", "luminate-wellms-redis-1", "redis-cli", "-a", E["REDIS_PASSWORD"], "--no-auth-warning", *a])[1]


def psql(sql, guc=TID):
    p = subprocess.run(["docker", "exec", "-i", "-e", f"PGPASSWORD={E['DB_APP_PASSWORD']}", "luminate-wellms-postgres-1", "psql", "-h", "127.0.0.1",
                        "-U", E["DB_APP_USER"], "-d", E.get("DB_NAME", "wellms"), "-At", "-q", "-F", "|"],
                       input=f"select set_config('app.tenant_id','{guc}',false);" + sql, capture_output=True, text=True)
    return [l for l in p.stdout.splitlines()[1:] if l]



# --- setup: an admin, a course with a 1-minute quiz of two auto-graded questions, five students --------------------------
s, r = call("POST", "/api/auth/login", body={"email": E["INITIAL_USER_EMAIL"], "password": lc.password(TID)})
admin = r["data"]["token"]
s, r = call("POST", "/api/admin/courses", bearer=admin, body={"title": f"timeout-probe-{RUN}", "status": "published"})
course = r["data"]["id"]
s, r = call("POST", "/api/admin/lessons", bearer=admin, body={"title": "L", "order": 1, "course_id": course})
lesson = r["data"]["id"]
s, r = call("POST", "/api/admin/topics", bearer=admin, form=True, body={"title": "Quiz", "lesson_id": lesson, "order": 1, "active": 1, "topicable_type": GIFT, "value": "Q"})
quiz = r["data"]["topicable_id"]
s, r = call("PUT", f"/api/admin/gift-quizes/{quiz}", bearer=admin, body={"value": "Q", "max_attempts": 5, "max_execution_time": 1})
check("setup: a quiz with a 1-minute limit exists", s == 200, f"{s} {str(r)[:150]}")
qs = []
for i, (text, _) in enumerate([("2+2? {=4}", "4"), ("3+3? {=6}", "6")], start=1):
    s, r = call("POST", "/api/admin/gift-questions", bearer=admin, body={"topic_gift_quiz_id": quiz, "score": 1, "order": i, "value": text})
    qs.append(r["data"]["id"])
check("setup: two auto-graded questions (max score 2)", len(qs) == 2)

students = {}
for name in ("open", "submitall", "ended", "timeout_partial", "timeout_empty"):
    email = f"{name}-{RUN}@alpha.test"
    s, r = call("POST", "/api/admin/users", bearer=admin, body={"first_name": name, "last_name": "S", "email": email, "password": PW, "return_url": "http://localhost/", "verified": True})
    uid = r["data"]["id"]
    call("POST", f"/api/admin/courses/{course}/access/add", bearer=admin, body={"users": [uid]})
    s, r = call("POST", "/api/auth/login", body={"email": email, "password": PW})
    if s != 200:
        print("FAIL  student login", name, s, str(r)[:150])
        sys.exit(1)
    students[name] = {"uid": uid, "token": r["data"]["token"]}

# --- start one attempt each; the deadline is ~60 s away ---------------------------------------------------------------------
for name, st in students.items():
    s, r = call("POST", "/api/quiz-attempts", bearer=st["token"], body={"topic_gift_quiz_id": quiz})
    st["attempt"] = r["data"]["id"]
check("five attempts started, each with a deadline", all("attempt" in st for st in students.values()))
pre = {n: psql(f"select end_at, updated_at from topic_gift_quiz_attempts where id={s_['attempt']}")[0] for n, s_ in students.items()}

_n = redis("--scan", "--pattern", "*queues:default:delayed").splitlines()
DELAYED = _n[0] if _n else None
jobs = [json.loads(m) for m in redis("zrange", DELAYED, "0", "-1").splitlines() if m.startswith("{")] if DELAYED else []
mine = {st["attempt"] for st in students.values()}
def is_mine(j):
    return "MarkAttemptAsEnded" in j.get("displayName", "") and any(f'quizAttemptId";i:{a};' in j["data"]["command"] for a in mine)


timers = [j for j in jobs if is_mine(j)]
print(f"INFO  delayed MarkAttemptAsEnded timers queued for these attempts: {len(timers)} (queue key {DELAYED})")
check("the quiz timer really is queued: one delayed MarkAttemptAsEnded per attempt, tenant-tagged", len(timers) == 5 and all(j.get("luminate_tenant", {}).get("tid") == TID for j in timers), f"{len(timers)}")

# --- drive the five states ---------------------------------------------------------------------------------------------------------
def answer(st, qid, text):
    return call("POST", "/api/quiz-answers", bearer=st["token"], body={"topic_gift_question_id": qid, "topic_gift_quiz_attempt_id": st["attempt"], "answer": {"text": text}})


st = students["submitall"]
call("POST", "/api/quiz-answers/all", bearer=st["token"], body={"topic_gift_quiz_attempt_id": st["attempt"], "answers": [
    {"topic_gift_question_id": qs[0], "answer": {"text": "4"}}, {"topic_gift_question_id": qs[1], "answer": {"text": "6"}}]})   # immediate job
st = students["ended"]
answer(st, qs[0], "4")
s, r = call("POST", f"/api/quiz-attempts/{st['attempt']}/end", bearer=st["token"])                                              # synchronous end
check("explicit end (POST /end) succeeds", s == 200, f"{s} {str(r)[:150]}")
answer(students["timeout_partial"], qs[0], "4")                                                                                    # one of two, then walks away


def admin_row(attempt_id):
    s, r = call("GET", f"/api/admin/quiz-attempts?topic_gift_quiz_id={quiz}&per_page=50", bearer=admin)
    rows = r["data"] if isinstance(r, dict) else []
    return next((x for x in rows if x.get("id") == attempt_id), None), rows, r


def db(attempt_id):
    row = psql(f"select end_at, updated_at from topic_gift_quiz_attempts where id={attempt_id}")
    return row[0] if row else None


time.sleep(4)  # let the worker take the immediate job
snap1 = {n: {"db": db(s_["attempt"]), "row": admin_row(s_["attempt"])[0]} for n, s_ in students.items()}
print("\nINFO  admin list item keys:", sorted((snap1["open"]["row"] or {}).keys()))
print("INFO  BEFORE the deadline  (name: end_at|updated_at in DB ; admin fields)")
for n, v in snap1.items():
    f = {k: v["row"].get(k) for k in v["row"] if any(w in k for w in ("score", "pass", "percent", "end", "grad", "status", "correct"))} if v["row"] else None
    print(f"      {n:16} {v['db']}   {f}")

# --- wait for the real worker to process the real timers (deadline 60 s + the worker's polling) -----------------------------------
print("\nINFO  waiting for the 1-minute deadlines and the real worker to process the delayed timers ...")
deadline = time.time() + 150
while time.time() < deadline:
    left = [j for j in (json.loads(m) for m in redis("zrange", DELAYED, "0", "-1").splitlines() if m.startswith("{")) if is_mine(j)]
    if not left:
        break
    time.sleep(5)
time.sleep(8)  # let the worker finish what it just popped
check("the REAL worker consumed every delayed timer (none left in the delayed set)", not left, f"{len(left)} left")
failed = psql("select count(*) from failed_jobs where payload like '%MarkAttemptAsEnded%' and failed_at > now() - interval '10 minutes'", guc=TID)
check("...and none of them failed or was dead-lettered", failed == ["0"], str(failed))
logs = sh(["docker", "logs", "--since", "6m", "luminate-wellms-worker-1"])
ran = [l for l in (logs[1] + logs[2]).splitlines() if "MarkAttemptAsEnded" in l and ("DONE" in l or "FAIL" in l)]
print(f"INFO  worker log lines for MarkAttemptAsEnded in the last 6 minutes: {len(ran)} (DONE: {sum('DONE' in l for l in ran)}, FAIL: {sum('FAIL' in l for l in ran)})")
check("the worker log shows MarkAttemptAsEnded jobs completing (DONE), none FAIL", any("DONE" in l for l in ran) and not any("FAIL" in l for l in ran), "\n".join(ran[:4]))

snap2 = {n: {"db": db(s_["attempt"]), "row": admin_row(s_["attempt"])[0]} for n, s_ in students.items()}
print("\nINFO  AFTER the deadline and after the real worker ran the timers")
for n, v in snap2.items():
    f = {k: v["row"].get(k) for k in v["row"] if any(w in k for w in ("score", "pass", "percent", "end", "grad", "status", "correct"))} if v["row"] else None
    print(f"      {n:16} {v['db']}   {f}")

# (1) Did the real timer change a timed-out attempt?  If the job had passed its isEnded() guard it would have set end_at = now (and bumped updated_at).
for n in ("timeout_partial", "timeout_empty", "open"):
    check(f"the real timer left the timed-out attempt '{n}' untouched (end_at and updated_at unchanged => the job returned early)", snap1[n]["db"] == snap2[n]["db"], f"{snap1[n]['db']} -> {snap2[n]['db']}")
check("control: the immediate job for 'submitall' DID run and moved end_at (so a job that proceeds leaves a visible trace)", pre["submitall"] != snap2["submitall"]["db"], f"{pre['submitall']} -> {snap2['submitall']['db']}")

# (1b) which events would the job dispatch?  Direct probe with the dispatcher faked.
sh(["docker", "cp", str(pathlib.Path(__file__).with_name("quiz_events_probe.php")), "luminate-wellms-api-1:/tmp/quiz_events_probe.php"])
s, r = call("POST", "/api/auth/login", body={"email": E["INITIAL_USER_EMAIL"], "password": lc.password(TID)})
control = {"token": students["open"]["token"]}
# control: a brand-new attempt that is still OPEN (we use a second quiz attempt by a fresh student would race the clock; instead restart 'ended' student? max_attempts=5)
s, r = call("POST", "/api/quiz-attempts", bearer=students["ended"]["token"], body={"topic_gift_quiz_id": quiz})
control["attempt"] = r["data"]["id"]
ids = [students[n]["attempt"] for n in ("timeout_partial", "timeout_empty", "open", "ended", "submitall")] + [control["attempt"]]
rc, out, err = sh(["docker", "exec", "-e", f"LUMINATE_TENANT={TID}", "-u", "www-data", "luminate-wellms-api-1", "php", "/tmp/quiz_events_probe.php", *map(str, ids)])
probe = {}
for line in out.splitlines():
    if line.startswith("{"):
        d = json.loads(line)
        probe[d["attempt"]] = d
print("\nINFO  event probe (MarkAttemptAsEnded::handle with the dispatcher faked):")
for n, aid in zip(("timeout_partial", "timeout_empty", "open->timed out", "ended", "submitall", "CONTROL (still open)"), ids):
    d = probe.get(aid, {})
    print(f"      {n:22} attempt {aid}: was_ended={d.get('is_ended_before')} finished_events={d.get('finished_events')} grade_ready_events={d.get('grade_ready_events')} end_at {d.get('end_at_before')} -> {d.get('end_at_after')} {d.get('error', '')}")
check("the probe ran for every attempt", len(probe) == len(ids), f"{len(probe)}/{len(ids)} {err[:200]}")
check("(1) a TIMED-OUT attempt fires NO quiz event (partial answers)", probe.get(ids[0], {}).get("finished_events") == 0 and probe.get(ids[0], {}).get("grade_ready_events") == 0, str(probe.get(ids[0])))
check("(1) a TIMED-OUT attempt fires NO quiz event (no answers)", probe.get(ids[1], {}).get("finished_events") == 0 and probe.get(ids[1], {}).get("grade_ready_events") == 0, str(probe.get(ids[1])))
check("(1) an attempt whose deadline passed while still 'open' fires NO quiz event", probe.get(ids[2], {}).get("finished_events") == 0, str(probe.get(ids[2])))
check("CONTROL: an attempt still inside its deadline DOES fire quiz.attempt.finished (the probe can see events)", probe.get(ids[5], {}).get("finished_events", 0) >= 1, str(probe.get(ids[5])))

# --- (2) the pull: does GET /api/admin/quiz-attempts expose completed AND timed-out attempts? ----------------------------------------
_, rows, raw = admin_row(0)
by_id = {x["id"]: x for x in rows}
for n, st in students.items():
    check(f"(2) the admin list contains the '{n}' attempt", st["attempt"] in by_id)
timed = [by_id.get(students[n]["attempt"], {}) for n in ("timeout_partial", "timeout_empty")]
ended = [by_id.get(students[n]["attempt"], {}) for n in ("submitall", "ended")]
print("\nINFO  pull-relevant fields as the admin list shows them AFTER everything settled:")
for n in students:
    x = by_id.get(students[n]["attempt"], {})
    print(f"      {n:16} " + json.dumps({k: x.get(k) for k in x if any(w in k for w in ("score", "pass", "percent", "end_at", "correct", "grad"))}))
check("(2) a timed-out attempt shows its final score (derived from its end_at), not null", all(x.get("result_score") is not None for x in timed), str([x.get("result_score") for x in timed]))
check("(2) the partially answered timed-out attempt scores 1 of 2", timed[0].get("result_score") == 1 and timed[0].get("max_score", 2) in (2, None), str(timed[0]))
check("(2) completed attempts show their score", all(x.get("result_score") is not None for x in ended), str([x.get("result_score") for x in ended]))
snap_open = snap1["open"]["row"] or {}
check("(2) while an attempt was still OPEN its score was null, so a puller can tell final from not-final", snap_open.get("result_score") is None, str(snap_open))
s, r = call("GET", f"/api/admin/quiz-attempts?course_id={course}&per_page=50", bearer=admin)
check("(2) filter by course_id returns the attempts", s == 200 and len(r["data"]) >= 5, f"{s} {len(r['data']) if isinstance(r, dict) else r}")
s, r = call("GET", f"/api/admin/quiz-attempts?user_id={students['timeout_partial']['uid']}&per_page=50", bearer=admin)
check("(2) filter by user_id returns that user's timed-out attempt", s == 200 and any(x["id"] == students["timeout_partial"]["attempt"] for x in r["data"]), f"{s}")
s, r = call("GET", f"/api/admin/quiz-attempts?topic_gift_quiz_id={quiz}&per_page=2&page=2", bearer=admin)
check("(2) pagination works (per_page=2, page=2)", s == 200 and len(r["data"]) == 2 and r["meta"]["total"] >= 6, f"{s} {str(r)[:160]}")
s, r = call("GET", f"/api/admin/quiz-attempts?topic_gift_quiz_id={quiz}&per_page=50", tid=OTHER, bearer=None)
s2, rb = call("POST", "/api/auth/login", tid=OTHER, body={"email": E["INITIAL_USER_EMAIL"], "password": lc.password(OTHER)})
s3, r3 = call("GET", f"/api/admin/quiz-attempts?topic_gift_quiz_id={quiz}&per_page=50", tid=OTHER, bearer=rb["data"]["token"])
check("(2) tenant isolation: t-beta's admin sees none of t-alpha's attempts", s3 == 200 and not any(x["id"] in mine for x in r3["data"]), f"{s3} {len(r3['data']) if isinstance(r3, dict) else r3}")

print(f"\n{sum(results)}/{len(results)} passed   (quiz {quiz}, course {course}, run {RUN})")
sys.exit(0 if all(results) else 1)
