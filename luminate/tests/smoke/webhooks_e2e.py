"""Batch 5: signed webhooks (contract v4 section F) and is_fully_graded (v5), end to end against the live dev stack.

    python -I luminate/tests/smoke/webhooks_e2e.py        (about 3-4 minutes: it waits on real retry timings)

Needs the dev override (docker-compose.dev.yml): a test receiver on 127.0.0.1:8099 and short retry timings (3,5,8,10 s; cap 40 s).
Dev data only (tenants t-alpha and t-beta).
"""
import json
import pathlib
import re
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
SECRET = E["LUMINATE_WEBHOOK_SECRET"]
RECV = "http://127.0.0.1:8099"
A, B = "t-alpha", "t-beta"
RUN = uuid.uuid4().hex[:6]
PW = "Fake-Passw0rd!x"
RICH = "EscolaLms\\TopicTypes\\Models\\TopicContent\\RichText"
GIFT = "EscolaLms\\TopicTypeGift\\Models\\GiftQuiz"
UUID_RE = re.compile(r"^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$")
results = []


def check(name, ok, detail=""):
    results.append(ok)
    print(f"{'PASS' if ok else 'FAIL'}  {name}" + (f"  [{detail}]" if detail and not ok else ""))


def call(method, path, tid=A, bearer=None, body=None, form=False):
    h = {"Accept": "application/json", "X-Tenant-Context": ct.mint(KEY, tid)}
    if bearer:
        h["Authorization"] = "Bearer " + bearer
    data = None
    if body is not None:
        data = urllib.parse.urlencode(body).encode() if form else json.dumps(body).encode()
        h["Content-Type"] = "application/x-www-form-urlencoded" if form else "application/json"
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


def recv(method="GET", path="/received", body=None):
    req = urllib.request.Request(RECV + path, data=json.dumps(body).encode() if body is not None else None, method=method)
    with urllib.request.urlopen(req, timeout=15) as r:
        raw = r.read()
        return json.loads(raw) if raw else None


def sh(args):
    p = subprocess.run(args, capture_output=True, text=True)
    return p.returncode, p.stdout.strip(), p.stderr.strip()


def psql(sql, guc=A, role="app"):
    user, pw = (E["DB_APP_USER"], E["DB_APP_PASSWORD"]) if role == "app" else (E["DB_OWNER_USER"], E["DB_OWNER_PASSWORD"])
    pre = f"select set_config('app.tenant_id','{guc}',false);" if role == "app" else ""
    p = subprocess.run(["docker", "exec", "-i", "-e", f"PGPASSWORD={pw}", "luminate-wellms-postgres-1", "psql", "-h", "127.0.0.1", "-U", user,
                        "-d", E.get("DB_NAME", "wellms"), "-At", "-q", "-F", "|"], input=pre + sql, capture_output=True, text=True)
    lines = p.stdout.splitlines()
    return [l for l in (lines[1:] if role == "app" else lines) if l]


def wait_for(pred, timeout, interval=1.0):
    end = time.time() + timeout
    while time.time() < end:
        v = pred()
        if v:
            return v
        time.sleep(interval)
    return pred()


def hooks(event=None, **match):
    out = []
    for d in recv():
        if event and d["event"] != event:
            continue
        data = (d.get("json") or {}).get("data") or {}
        if all(data.get(k) == v for k, v in match.items()):
            out.append(d)
    return out


def delivered(event, **match):
    return [d for d in hooks(event, **match) if d["responded"] == 200]


# ---- preconditions: the receiver is up and the module is on -----------------------------------------------------------------
try:
    recv("POST", "/reset")
except Exception as e:
    print("FAIL  the test receiver is not reachable on 127.0.0.1:8099 (is docker-compose.dev.yml active?)", e)
    sys.exit(1)

s, r = call("POST", "/api/auth/login", body={"email": E["INITIAL_USER_EMAIL"], "password": lc.password(A)})
admin = r["data"]["token"]
s, r = call("POST", "/api/auth/login", tid=B, body={"email": E["INITIAL_USER_EMAIL"], "password": lc.password(B)})
admin_b = r["data"]["token"]


def new_course(token, tid, title):
    s, r = call("POST", "/api/admin/courses", tid, token, {"title": title, "status": "published"})
    course = r["data"]["id"]
    s, r = call("POST", "/api/admin/lessons", tid, token, {"title": "L", "order": 1, "course_id": course})
    lesson = r["data"]["id"]
    topics = []
    for i in (1, 2):
        s, r = call("POST", "/api/admin/topics", tid, token, {"title": f"Read {i}", "lesson_id": lesson, "order": i, "active": 1, "topicable_type": RICH, "value": "<p>x</p>"}, form=True)
        topics.append(r["data"]["id"])
    return course, lesson, topics


def new_student(token, tid, prefix):
    email = f"{prefix}-{RUN}@{tid}.test"
    s, r = call("POST", "/api/admin/users", tid, token, {"first_name": prefix, "last_name": "S", "email": email, "password": PW, "return_url": "http://localhost/", "verified": True})
    uid = r["data"]["id"]
    s, r = call("POST", "/api/auth/login", tid, body={"email": email, "password": PW})
    return uid, r["data"]["token"]


course, lesson, topics = new_course(admin, A, f"webhook-e2e-{RUN}")
uid, stok = new_student(admin, A, "learner")

# =====================================================================================================================
print("--- 1. enrolment")
s, r = call("POST", f"/api/admin/courses/{course}/access/add", A, admin, {"users": [uid]})
check("admin grants access (200)", s == 200, f"{s} {str(r)[:120]}")
enrol = wait_for(lambda: delivered("enrolment.created", wellms_user_id=uid, wellms_course_id=course), 25)
check("enrolment.created reached the receiver and was acknowledged", len(enrol) == 1, f"{len(enrol)}")
if enrol:
    d = enrol[0]
    check("its signature verified at the receiver (current secret, raw body, delivery id, timestamp)", d["signature_ok"] and d["shapes_ok"], str(d["signature_ok"]))
    check("delivery id is a UUID and the timestamp is digits (the shapes the dot-joined signature relies on)", bool(UUID_RE.match(d["delivery_id"])) and d["timestamp"].isdigit())
    body = d["json"]
    check("envelope is exactly {event, delivery_id, tenant_id, occurred_at, data}", set(body) == {"event", "delivery_id", "tenant_id", "occurred_at", "data"}, str(sorted(body)))
    check("tenant_id inside the signed body is t-alpha", body["tenant_id"] == A)
    check("header event / delivery id match the body", d["event"] == body["event"] == "enrolment.created" and d["delivery_id"] == body["delivery_id"])
    check("occurred_at is ISO-8601 UTC", bool(re.match(r"^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$", body["occurred_at"])), body["occurred_at"])
    check("data is exactly wellms_user_id + wellms_course_id (no group id: Wellms never emits one), as integers",
          body["data"] == {"wellms_user_id": uid, "wellms_course_id": course} and all(isinstance(v, int) for v in body["data"].values()), str(body["data"]))
    check("Content-Type is application/json", (d["content_type"] or "").startswith("application/json"))

# =====================================================================================================================
print("--- 2. topic completion")
s, r = call("GET", f"/api/courses/progress/{course}", A, stok)
tids = [p["topic_id"] for p in r["data"]]
check("the learner sees the course's topics", set(topics) <= set(tids), str(tids))
s, r = call("PATCH", f"/api/courses/progress/{course}", A, stok, {"progress": [{"topic_id": t, "status": 1} for t in topics]})
check("learner completes both reading topics (200)", s == 200, f"{s} {str(r)[:120]}")
tc = wait_for(lambda: len({d["json"]["data"]["topic_id"] for d in delivered("topic.completed", wellms_user_id=uid)}) >= 2 and delivered("topic.completed", wellms_user_id=uid), 25)
got = {d["json"]["data"]["topic_id"]: d["json"]["data"] for d in (tc or [])}
check("topic.completed arrives once per topic", set(topics) <= set(got), str(sorted(got)))
if got:
    one = got[topics[0]]
    check("topic.completed data = user, course, topic_id, topic_type", set(one) == {"wellms_user_id", "wellms_course_id", "topic_id", "topic_type"} and one["wellms_course_id"] == course and one["topic_type"].endswith("RichText"), str(one))

# =====================================================================================================================
print("--- 3. quiz: finished event carries no score; manual grading; is_fully_graded")
s, r = call("POST", "/api/admin/lessons", A, admin, {"title": "Quiz lesson", "order": 2, "course_id": course})
qlesson = r["data"]["id"]
s, r = call("POST", "/api/admin/topics", A, admin, {"title": "Quiz", "lesson_id": qlesson, "order": 1, "active": 1, "topicable_type": GIFT, "value": "Q"}, form=True)
quiz = r["data"]["topicable_id"]
s, r = call("PUT", f"/api/admin/gift-quizes/{quiz}", A, admin, {"value": "Q", "max_attempts": 5, "max_execution_time": 30, "counts_to_grade": True})
check("quiz set up with counts_to_grade", s == 200, f"{s} {str(r)[:150]}")
qa = call("POST", "/api/admin/gift-questions", A, admin, {"topic_gift_quiz_id": quiz, "score": 1, "order": 1, "value": "2+2? {=4}"})[1]["data"]["id"]
qe = call("POST", "/api/admin/gift-questions", A, admin, {"topic_gift_quiz_id": quiz, "score": 4, "order": 2, "value": "Explain gravity. {}"})[1]["data"]["id"]
s, r = call("POST", "/api/quiz-attempts", A, stok, {"topic_gift_quiz_id": quiz})
attempt = r["data"]["id"]


def attempt_item():
    s, r = call("GET", f"/api/admin/quiz-attempts?topic_gift_quiz_id={quiz}&per_page=50", A, admin)
    return next((x for x in r["data"] if x["id"] == attempt), None)


open_item = attempt_item()
check("an attempt still OPEN: result_score is null while is_fully_graded can already read true (so 'final' = is_ended AND is_fully_graded)",
      open_item is not None and open_item["result_score"] is None and open_item.get("is_fully_graded") is True, str(open_item))

s, r = call("POST", "/api/quiz-answers/all", A, stok, {"topic_gift_quiz_attempt_id": attempt, "answers": [
    {"topic_gift_question_id": qa, "answer": {"text": "4"}}, {"topic_gift_question_id": qe, "answer": {"text": "mass attracts mass"}}]})
check("learner submits all answers (200)", s == 200, f"{s} {str(r)[:150]}")
fin = wait_for(lambda: delivered("quiz.attempt.finished", attempt_id=attempt), 30)
check("quiz.attempt.finished arrived for the submitted attempt", len(fin) == 1, f"{len(fin)}")
if fin:
    data = fin[0]["json"]["data"]
    check("quiz.attempt.finished data = user, course, quiz, attempt (no score of any kind)",
          data == {"wellms_user_id": uid, "wellms_course_id": course, "topic_gift_quiz_id": quiz, "attempt_id": attempt}
          and not re.search(r"score|percent|passed|grade", fin[0]["raw"]), str(data))
check("quiz.grade.ready has NOT fired while an essay answer is ungraded", not hooks("quiz.grade.ready", attempt_id=attempt))

item = attempt_item()
check("the attempt list item exposes is_fully_graded", item is not None and "is_fully_graded" in item, str(sorted(item)) if item else "no item")
check("with an ungraded essay: is_ended is true, is_fully_graded is FALSE, so the partial score must not be treated as final",
      item and item["is_ended"] is True and item["is_fully_graded"] is False and item["result_score"] == 1, str({k: item.get(k) for k in ("is_ended", "is_fully_graded", "result_score")}))
s, r = call("GET", f"/api/admin/quiz-attempts/{attempt}", A, admin)
check("the detail endpoint carries the same flag", s == 200 and r["data"].get("is_fully_graded") is False, f"{s}")
check("the key was only ADDED: every original list-item key is still present",
      item and {"id", "user_id", "topic_gift_quiz_id", "started_at", "end_at", "max_score", "result_score", "result_percent", "is_passed", "is_ended", "user", "topic", "course"} <= set(item))

answer_id = psql(f"select id from topic_gift_attempt_answers where topic_gift_quiz_attempt_id={attempt} and topic_gift_question_id={qe}")
s, r = call("PATCH", f"/api/admin/quiz-answers/{answer_id[0]}", A, admin, {"score": 3, "feedback": "good"}) if answer_id else (0, None)
check("a lecturer grades the essay (PATCH /api/admin/quiz-answers/{id})", s == 200, f"{s} {str(r)[:150]}")
ready = wait_for(lambda: delivered("quiz.grade.ready", attempt_id=attempt), 30)
check("OPEN QUESTION ANSWERED: manual grading DOES fire quiz.grade.ready (counts_to_grade quiz, last essay graded)", len(ready) == 1, f"{len(ready)} deliveries")
if ready:
    check("quiz.grade.ready data is exactly {attempt_id}", ready[0]["json"]["data"] == {"attempt_id": attempt}, str(ready[0]["json"]["data"]))
item = attempt_item()
check("after grading: is_fully_graded flips to true and the score is final (1 + 3 = 4 of 5)",
      item and item["is_fully_graded"] is True and item["result_score"] == 4 and item["max_score"] == 5, str({k: item.get(k) for k in ("is_fully_graded", "result_score", "max_score")}) if item else "")

# =====================================================================================================================
print("--- 4. unenrolment")
completed_before = len(delivered("course.completed", wellms_user_id=uid, wellms_course_id=course))
check("finishing every topic had ALREADY fired one genuine course.completed (both reading topics were done before the quiz topic existed)", completed_before == 1, f"{completed_before}")
s, r = call("POST", f"/api/admin/courses/{course}/access/remove", A, admin, {"users": [uid]})
check("admin removes access (200)", s == 200, f"{s} {str(r)[:120]}")
rem = wait_for(lambda: delivered("enrolment.removed", wellms_user_id=uid, wellms_course_id=course), 25)
done = wait_for(lambda: len(delivered("course.completed", wellms_user_id=uid, wellms_course_id=course)) > completed_before, 25)
check("enrolment.removed arrived", len(rem) == 1, f"{len(rem)}")
check("exactly ONE more course.completed arrived with it: Wellms always fires CourseFinished together with CourseUnassigned (the receiver ignores that one, per the contract)",
      done and len(delivered("course.completed", wellms_user_id=uid, wellms_course_id=course)) == completed_before + 1, f"{completed_before} -> {len(delivered('course.completed', wellms_user_id=uid, wellms_course_id=course))}")

# =====================================================================================================================
print("--- 5. tenant isolation of events and of the outbox")
course_b, _, _ = new_course(admin_b, B, f"webhook-e2e-beta-{RUN}")
uid_b, _ = new_student(admin_b, B, "learnerb")
call("POST", f"/api/admin/courses/{course_b}/access/add", B, admin_b, {"users": [uid_b]})
eb = wait_for(lambda: delivered("enrolment.created", wellms_user_id=uid_b, wellms_course_id=course_b), 25)
check("beta's enrolment is delivered with tenant_id t-beta", len(eb) == 1 and eb[0]["json"]["tenant_id"] == B, str(eb[0]["json"]["tenant_id"]) if eb else "none")
check("alpha's deliveries all say t-alpha; none of beta's ids appear in them",
      all(d["json"]["tenant_id"] == A for d in hooks() if (d["json"]["data"].get("wellms_course_id") == course)), "")
a_rows = psql("select count(*) from luminate_webhook_deliveries", A)
b_rows = psql("select count(*) from luminate_webhook_deliveries", B)
a_mentions_b = psql("select count(*) from luminate_webhook_deliveries where body like '%\"tenant_id\":\"t-beta\"%'", A)
b_mentions_a = psql("select count(*) from luminate_webhook_deliveries where body like '%\"tenant_id\":\"t-alpha\"%'", B)
check("each tenant sees only its own outbox rows at the database level", int(a_rows[0]) > 0 and int(b_rows[0]) > 0 and a_mentions_b == ["0"] and b_mentions_a == ["0"], f"{a_rows} {b_rows} {a_mentions_b} {b_mentions_a}")
check("no tenant context => the outbox is invisible", psql("select count(*) from luminate_webhook_deliveries", "") == ["0"])

# =====================================================================================================================
print("--- 6. retry: the same delivery id, timestamp, body and signature every time")
recv("POST", "/control", {"fail_next": 2, "status": 500})
uid2, _ = new_student(admin, A, "retry")
t0 = time.time()
call("POST", f"/api/admin/courses/{course}/access/add", A, admin, {"users": [uid2]})
final = wait_for(lambda: delivered("enrolment.created", wellms_user_id=uid2, wellms_course_id=course), 40)
check("a delivery that fails twice (500) is eventually delivered", len(final) == 1)
tries = hooks("enrolment.created", wellms_user_id=uid2, wellms_course_id=course)
check("the receiver saw exactly 3 requests: 500, 500, 200", [t["responded"] for t in tries] == [500, 500, 200], str([t["responded"] for t in tries]))
if len(tries) == 3:
    check("all three carry the SAME delivery id, FIRST-send timestamp, raw body and signature",
          len({t["delivery_id"] for t in tries}) == 1 and len({t["timestamp"] for t in tries}) == 1 and len({t["raw"] for t in tries}) == 1 and len({t["signature"] for t in tries}) == 1
          and all(t["signature_ok"] for t in tries))
    gaps = [round(tries[i + 1]["at"] - tries[i]["at"], 1) for i in range(2)]
    check("retries are spaced by the (dev) backoff of about 3 s then 5 s, not sent early", gaps[0] >= 2.5 and gaps[1] >= 4.5, str(gaps))
    row = psql(f"select status, attempts, last_status from luminate_webhook_deliveries where delivery_id='{tries[0]['delivery_id']}'")
    check("the outbox row ends delivered after 3 attempts", row == ["delivered|3|200"], str(row))
dups = {}
for d in recv():
    if d["responded"] == 200:
        dups[d["delivery_id"]] = dups.get(d["delivery_id"], 0) + 1
check("no delivery was ever acknowledged twice: Wellms stops after success", all(v == 1 for v in dups.values()), str({k: v for k, v in dups.items() if v > 1}))

# =====================================================================================================================
print("--- 7. dead-letter: a receiver that never recovers inside the window")
recv("POST", "/reset")
recv("POST", "/control", {"always_fail": True, "status": 503})
uid3, _ = new_student(admin, A, "dead")
call("POST", f"/api/admin/courses/{course}/access/add", A, admin, {"users": [uid3]})


def dead_row():
    ids = {d["delivery_id"] for d in hooks("enrolment.created", wellms_user_id=uid3, wellms_course_id=course)}
    if not ids:
        return None
    row = psql(f"select status, attempts, last_status, coalesce(last_error,'') from luminate_webhook_deliveries where delivery_id='{next(iter(ids))}'")
    return row[0] if row and row[0].startswith("dead") else None


row = wait_for(dead_row, 75, 2)
check("after the (dev) 40 s window the delivery is dead-lettered", row is not None, str(psql("select status, attempts from luminate_webhook_deliveries order by id desc limit 3")))
if row:
    status, attempts, last, err = row.split("|", 3)
    check("it made 5-6 attempts (3,5,8,10 s backoff inside 40 s) and recorded the last HTTP status 503", 5 <= int(attempts) <= 6 and last == "503", row)
    seen = len(hooks("enrolment.created", wellms_user_id=uid3, wellms_course_id=course))
    recv("POST", "/control", {"always_fail": False})
    time.sleep(20)
    check("once dead it is never sent again, even after the receiver recovers and the sweeper has run", len(hooks("enrolment.created", wellms_user_id=uid3, wellms_course_id=course)) == seen, f"{seen} -> {len(hooks('enrolment.created', wellms_user_id=uid3, wellms_course_id=course))}")
recv("POST", "/reset")

# =====================================================================================================================
print("--- 8. receiver down (connection refused), then back")
sh(["docker", "stop", "luminate-wellms-webhook-receiver-1"])
uid4, _ = new_student(admin, A, "down")
call("POST", f"/api/admin/courses/{course}/access/add", A, admin, {"users": [uid4]})


def down_row():
    r = psql(f"select status, attempts, coalesce(last_error,''), delivery_id from luminate_webhook_deliveries where body like '%\"wellms_user_id\":{uid4},%' and event='enrolment.created'")
    # a RECORDED failure: still pending, at least one attempt, and an error on the row
    return r[0] if r and r[0].startswith("pending|") and int(r[0].split("|")[1]) >= 1 and r[0].split("|")[2] else None


row = wait_for(down_row, 30)
check("with the receiver down the delivery stays pending with a RECORDED failed attempt", row is not None, str(psql(f"select status, attempts, coalesce(last_error,'') from luminate_webhook_deliveries where body like '%\"wellms_user_id\":{uid4},%'")))
down_delivery = row.split("|")[3] if row else None
if row:
    err = row.split("|")[2]
    check("the recorded error names the failure but never the body, the secret or any URL", SECRET not in err and "http://" not in err and "https://" not in err and "wellms_user_id" not in err, err)
sh(["docker", "start", "luminate-wellms-webhook-receiver-1"])
def receiver_up():
    try:
        recv()
        return True
    except Exception:
        return False


wait_for(receiver_up, 20, 1)  # the restarted receiver has an empty log; do NOT reset it after delivery


def recovered():
    r = psql(f"select status, attempts from luminate_webhook_deliveries where delivery_id='{down_delivery}'") if down_delivery else []
    return r[0] if r and r[0].startswith("delivered|") else None


rec = wait_for(recovered, 45, 2)
check("when the receiver returns, the SAME delivery is delivered by a later attempt", rec is not None and int(rec.split("|")[1]) >= 2, str(psql(f"select status, attempts, last_error from luminate_webhook_deliveries where delivery_id='{down_delivery}'")))
seen = [d for d in recv() if d["delivery_id"] == down_delivery]
check("the restarted receiver got it exactly once, correctly signed", len(seen) == 1 and seen[0]["signature_ok"] and seen[0]["responded"] == 200, str([(d["responded"], d["signature_ok"]) for d in seen]))

# =====================================================================================================================
print("--- 9. nothing sent unsigned, and the outbox holds only what was recorded")
check("every request the receiver ever saw verified its signature", all(d["signature_ok"] for d in recv()), str([d["delivery_id"] for d in recv() if not d["signature_ok"]]))
leaks = psql("select count(*) from luminate_webhook_deliveries where body ~* '(password|secret|token|email)'", A)
check("outbox bodies contain no password, secret, token or e-mail (ids only)", leaks == ["0"], str(leaks))

print(f"\n{sum(results)}/{len(results)} passed   (course {course}, quiz {quiz}, attempt {attempt}, run {RUN})")
sys.exit(0 if all(results) else 1)
