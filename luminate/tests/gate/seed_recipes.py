"""Hand-written seed recipes for the isolation gate.

The schema-driven seeder cannot know Laravel's validation rules (enum values, required date pairs, parent ids), so the resource types the API
will not let it create stay empty, and every operation on them degrades to "no victim id to attack". These recipes build a realistic object
graph with bodies known to be accepted by Wellms 1.0.54. Each step reports ok/fail; a failure only means that type stays unseeded (and the
gate's report says so). Every text value carries the tenant marker, so a victim value showing up in an attacker's response is unmistakable.
"""
import gatelib as g

BS = chr(92)
RICH = "EscolaLms" + BS + "TopicTypes" + BS + "Models" + BS + "TopicContent" + BS + "RichText"
GIFT = "EscolaLms" + BS + "TopicTypeGift" + BS + "Models" + BS + "GiftQuiz"
USER_CLASS = "EscolaLms" + BS + "Auth" + BS + "Models" + BS + "User"


def run(tid, token, mark, n, log):
    """Create n of each simple resource plus one course graph per n. Returns {kind: [ids]}; failures are logged, not raised."""
    made = {}
    fails = []

    def call(kind, method, path, body=None, form=None, bearer=None):
        r = g.http_call(method, path, tid=tid, bearer=bearer or token(), body=body, form=form)
        if r.status in (200, 201) and isinstance(r.json, dict):
            made.setdefault(kind, []).append((r.json.get("data") or {}).get("id") if isinstance(r.json.get("data"), dict) else None)
            return r
        fails.append(f"{kind}: {r.status} {r.text[:140]}".replace(chr(10), " "))
        return None

    for i in range(n):
        m = f"{mark}_{i}"
        call("category", "POST", "/api/admin/categories", {"name": f"cat_{m}", "is_active": True})
        call("group", "POST", "/api/admin/user-groups", {"name": f"grp_{m}"})
        call("page", "POST", "/api/admin/pages", {"title": f"page_{m}", "slug": f"page-{m}".lower().replace("_", "-"), "content": f"body_{m}", "active": True})
        call("product", "POST", "/api/admin/products", {"name": f"prod_{m}", "type": "single", "price": 100 + i, "tax_rate": 0})
        call("translation", "POST", "/api/admin/translations", {"language": "en", "key": f"k_{m}", "group": "g", "text": [f"t_{m}"]})
        call("voucher", "POST", "/api/admin/vouchers", {"code": f"V_{m}", "type": "cart_fixed", "amount": 5})
        call("webinar", "POST", "/api/admin/webinars", {"name": f"web_{m}", "description": f"desc_{m}", "status": "published",
                                                         "active_from": "2030-01-01 10:00:00", "active_to": "2030-01-01 11:00:00", "duration": "60"})
        call("consultation", "POST", "/api/admin/consultations", {"name": f"cons_{m}", "description": f"desc_{m}", "status": "published", "duration": "60", "base_price": 100})
        call("event", "POST", "/api/admin/stationary-events", {"name": f"evt_{m}", "description": f"desc_{m}", "status": "published", "place": f"place_{m}",
                                                                "started_at": "2030-01-01 10:00:00", "finished_at": "2030-01-01 11:00:00", "max_participants": 5})
        call("questionnaire", "POST", "/api/admin/questionnaire", {"title": f"quest_{m}", "active": True})
        call("setting", "POST", "/api/admin/settings", {"key": f"key_{m}", "group": "g", "type": "text", "value": f"val_{m}"})
        call("modelfield", "POST", "/api/admin/model-fields", {"name": f"fld_{m}", "type": "text", "class_type": USER_CLASS})
        call("task", "POST", "/api/tasks", {"title": f"task_{m}"})

        # a course graph: course -> lesson -> richtext topic + gift quiz topic (+questions), a student with access and a quiz attempt
        c = call("course", "POST", "/api/admin/courses", {"title": f"course_{m}", "status": "published", "summary": f"sum_{m}"})
        if not c:
            continue
        cid = c.json["data"]["id"]
        les = call("lesson", "POST", "/api/admin/lessons", {"title": f"lesson_{m}", "order": 1, "course_id": cid})
        lid = les and les.json["data"]["id"]
        if lid:
            call("topic", "POST", "/api/admin/topics", form={"title": f"topic_{m}", "lesson_id": lid, "order": 1, "active": 1, "topicable_type": RICH, "value": f"<p>rich_{m}</p>"})
            qt = call("quiztopic", "POST", "/api/admin/topics", form={"title": f"quiz_{m}", "lesson_id": lid, "order": 2, "active": 1, "topicable_type": GIFT, "value": f"Q_{m}"})
            quiz = qt and qt.json["data"].get("topicable", {}).get("id") if qt else None
            if quiz:
                g.http_call("PUT", f"/api/admin/gift-quizes/{quiz}", tid=tid, bearer=token(), body={"value": f"Q_{m}", "max_attempts": 5, "max_execution_time": 60, "counts_to_grade": True})
                call("giftq", "POST", "/api/admin/gift-questions", {"topic_gift_quiz_id": quiz, "score": 1, "order": 1, "value": f"2+2? {{=4}}"})
        email = f"student_{m}@{tid}.gate.test".lower()
        u = call("student", "POST", "/api/admin/users", {"first_name": f"stu_{m}", "last_name": "S", "email": email, "password": "Gate-Passw0rd!x1", "return_url": "http://localhost/", "verified": True})
        if u:
            uid = u.json["data"]["id"]
            call("access", "POST", f"/api/admin/courses/{cid}/access/add", {"users": [uid]})
            call("grpmember", "POST", f"/api/admin/user-groups/{(made.get('group') or [None])[-1]}/members", {"user_id": uid})
            lg = g.http_call("POST", "/api/auth/login", tid=tid, body={"email": email, "password": "Gate-Passw0rd!x1"})
            if lg.status == 200 and lid:
                stok = lg.json["data"]["token"]
                if quiz:
                    at = call("attempt", "POST", "/api/quiz-attempts", {"topic_gift_quiz_id": quiz}, bearer=stok)
                    if at:
                        call("bookmark", "POST", "/api/bookmarks", {"bookmarkable_id": cid, "bookmarkable_type": "EscolaLms" + BS + "Courses" + BS + "Models" + BS + "Course"}, bearer=stok)
    for f in sorted(set(fails)):
        log(f"    recipe miss: {f}")
    return made
