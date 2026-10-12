"""Pooled spike setup: tenant C gets confidential data; tenant D gets its own course."""
import sys, os, json
sys.path.insert(0, os.path.dirname(__file__))
from wl import W

BS = chr(92)
RICH = BS.join(["EscolaLms", "TopicTypes", "Models", "TopicContent", "RichText"])
GIFT = BS.join(["EscolaLms", "TopicTypeGift", "Models", "GiftQuiz"])
PW = "Passw0rd!eval"
c = W("pooled-c.localhost"); c.login("admin@pooled.test", "pooled-secret")
d = W("pooled-d.localhost"); d.login("admin@d.test", "d-secret")

code, js = c.call("POST", "/api/admin/courses", {"title": "C-CONFIDENTIAL course", "status": "published"}, quiet=True); cc = js["data"]["id"]
code, js = c.call("POST", "/api/admin/lessons", {"title": "C-CONFIDENTIAL lesson", "order": 1, "course_id": cc}, quiet=True); cl = js["data"]["id"]
code, js = c.call("POST", "/api/admin/topics", {"title": "C-CONFIDENTIAL read", "lesson_id": cl, "order": 1, "active": 1, "topicable_type": RICH, "value": "<p>C secret</p>"}, form=True, quiet=True); ct = js["data"]["id"]
code, js = c.call("POST", "/api/admin/topics", {"title": "C quiz", "lesson_id": cl, "order": 2, "active": 1, "topicable_type": GIFT, "value": "Q"}, form=True, quiet=True)
cq = js["data"]["topicable_id"]
c.call("PUT", f"/api/admin/gift-quizes/{cq}", {"value": "Q", "max_attempts": 3}, quiet=True)
code, js = c.call("POST", "/api/admin/gift-questions", {"topic_gift_quiz_id": cq, "score": 1, "order": 1, "value": "2+2? {=4}"}, quiet=True); cqq = js["data"]["id"]
# carol (user 2, created earlier on C) enrols and takes the quiz => quiz_attempts row owned by tenant C
c.call("POST", f"/api/admin/courses/{cc}/access/add", {"users": [2]}, quiet=True)
s = W("pooled-c.localhost")
code, js = s.call("POST", "/api/auth/login", {"email": "carol@c.test", "password": PW}, quiet=True)
print("carol login on C:", code, (js.get("message") or ""))
if code == 200:
    s.token = js["data"]["token"]
    code, js = s.call("POST", "/api/quiz-attempts", {"topic_gift_quiz_id": cq}, quiet=True); att = js["data"]["id"]
    s.call("POST", "/api/quiz-answers/all", {"topic_gift_quiz_attempt_id": att, "answers": [{"topic_gift_question_id": cqq, "answer": {"text": "4"}}]}, quiet=True)
    s.call("POST", f"/api/quiz-attempts/{att}/end", quiet=True)
    print("carol attempt", att, "ended")
else:
    att = None
code, js = d.call("POST", "/api/admin/courses", {"title": "D-own course", "status": "published"}, quiet=True); dc = js["data"]["id"]
ids = {"c_course": cc, "c_lesson": cl, "c_topic": ct, "c_quiz": cq, "c_attempt": att, "carol": 2, "d_course": dc}
json.dump(ids, open(os.path.join(os.path.dirname(__file__), "..", "evidence", ".pooled_ids.json"), "w"))
print(ids)
