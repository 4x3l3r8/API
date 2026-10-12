"""S6: drive a learner lifecycle on tenant A and compare with what the webhook receiver got."""
import sys, os, json, time
sys.path.insert(0, os.path.dirname(__file__))
from wl import W

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "evidence")
LOG = os.path.join(ROOT, "webhook-received.jsonl")
BS = chr(92)
RICH = BS.join(["EscolaLms", "TopicTypes", "Models", "TopicContent", "RichText"])
GIFT = BS.join(["EscolaLms", "TopicTypeGift", "Models", "GiftQuiz"])
PW = "Passw0rd!eval"


def received():
    if not os.path.exists(LOG):
        return []
    return [json.loads(l) for l in open(LOG, encoding="utf-8")]


def wait(n_events, label, timeout=60):
    t0 = time.time()
    while time.time() - t0 < timeout:
        r = [x for x in received() if x["status"] == 200]
        if len(r) >= n_events:
            break
        time.sleep(2)
    print(f"  [{label}] delivered so far:", [(x["event"]) for x in received() if x["status"] == 200][-6:])


a = W("tenant-a.localhost"); a.login("admin@tenant-a.test", "alpha-secret")
c, js = a.call("POST", "/api/admin/users", {"email": "carol@tenant-a.test", "first_name": "Carol", "last_name": "A", "password": PW, "password_confirmation": PW, "roles": ["student"], "is_active": True, "verified": True, "return_url": "http://localhost/v"}, quiet=True)
uid = js["data"]["id"] if c == 201 else a.call("GET", "/api/admin/users?search=carol", quiet=True)[1]["data"][0]["id"]
c, js = a.call("POST", "/api/admin/courses", {"title": "A-101 Webhook Test", "status": "published", "fields": {}}, quiet=True)
cid = js["data"]["id"]
c, js = a.call("POST", "/api/admin/lessons", {"title": "L1", "order": 1, "course_id": cid}, quiet=True); lid = js["data"]["id"]
c, js = a.call("POST", "/api/admin/topics", {"title": "Read", "lesson_id": lid, "order": 1, "active": 1, "topicable_type": RICH, "value": "<p>x</p>"}, form=True, quiet=True); t_read = js["data"]["id"]
c, js = a.call("POST", "/api/admin/topics", {"title": "Quiz", "lesson_id": lid, "order": 2, "active": 1, "topicable_type": GIFT, "value": "Q"}, form=True, quiet=True)
t_quiz = js["data"]["id"]; qid = js["data"]["topicable_id"]
a.call("PUT", f"/api/admin/gift-quizes/{qid}", {"value": "Q", "max_attempts": 3, "min_pass_score": 1, "counts_to_grade": True, "weight": 2}, quiet=True)
c, js = a.call("POST", "/api/admin/gift-questions", {"topic_gift_quiz_id": qid, "score": 4, "order": 1, "value": "2+2? {=4 ~5 ~3}"}, quiet=True); question = js["data"]["id"]
print("setup: user", uid, "course", cid, "topics", t_read, t_quiz, "quiz", qid, "question", question)

n0 = len(received())
print("1) enrol")
a.call("POST", f"/api/admin/courses/{cid}/access/add", {"users": [uid]})
wait(1, "after enrol")

s = W("tenant-a.localhost"); s.login("carol@tenant-a.test", PW)
print("2) learner completes the reading topic")
s.call("PATCH", f"/api/courses/progress/{cid}", {"progress": [{"topic_id": t_read, "status": 1}]})
wait(2, "after topic")

print("3) learner takes the quiz (auto-scored, counts_to_grade)")
c, js = s.call("POST", "/api/quiz-attempts", {"topic_gift_quiz_id": qid}); att = js["data"]["id"]
s.call("POST", "/api/quiz-answers/all", {"topic_gift_quiz_attempt_id": att, "answers": [{"topic_gift_question_id": question, "answer": {"text": "4"}}]})
s.call("POST", f"/api/quiz-attempts/{att}/end")
s.call("PATCH", f"/api/courses/progress/{cid}", {"progress": [{"topic_id": t_read, "status": 1}, {"topic_id": t_quiz, "status": 1}]})
wait(5, "after quiz+course")

print("4) unenrol")
a.call("POST", f"/api/admin/courses/{cid}/access/remove", {"users": [uid]})
wait(7, "after unenrol")

print("\n== received (new this run):")
for x in received()[n0:]:
    b = x["body"]
    print(f"  {x['t']} {x['status']} sig_ok={x['sig_ok']} dup={x['duplicate']} {x['event']:24s} user={b.get('user',{}).get('email')} course={b.get('course',{}).get('id')} topic={b.get('topic',{}).get('id')} attempt={b.get('attempt')}")
open(os.path.join(ROOT, ".s06"), "w").write(json.dumps({"uid": uid, "cid": cid, "t_read": t_read, "t_quiz": t_quiz, "qid": qid, "question": question}))
