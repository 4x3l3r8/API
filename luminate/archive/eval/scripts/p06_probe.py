"""Pooled spike probe: from tenant D, hit the API and flag any response containing tenant C's markers."""
import sys, os, json, urllib.request, urllib.error
sys.path.insert(0, os.path.dirname(__file__))
from wl import W, BASE

ids = json.load(open(os.path.join(os.path.dirname(__file__), "..", "evidence", ".pooled_ids.json")))
MARKERS = ["C-CONFIDENTIAL", "carol@c.test", "C quiz", "C secret", "pooled-c.localhost", "Carol"]
PW = "Passw0rd!eval"
d = W("pooled-d.localhost"); d.login("admin@d.test", "d-secret")
c = W("pooled-c.localhost"); c.login("admin@pooled.test", "pooled-secret")
label_set = sys.argv[1] if len(sys.argv) > 1 else "?"


def raw(w, method, path, body=None):
    h = {"Host": w.host, "Accept": "application/json", "Authorization": "Bearer " + w.token}
    data = None
    if body is not None:
        data = json.dumps(body).encode(); h["Content-Type"] = "application/json"
    req = urllib.request.Request(BASE + path, data=data, headers=h, method=method)
    try:
        with urllib.request.urlopen(req, timeout=90) as r:
            return r.status, r.read()
    except urllib.error.HTTPError as e:
        return e.code, e.read()


def probe(label, method, path, body=None, w=None):
    st, txt = raw(w or d, method, path, body)
    s = txt.decode("utf-8", "ignore")
    hits = sorted({m for m in MARKERS if m in s})
    foreign = ("by id" in label or "of C course" in label or "C attempt" in label) and st == 200
    if foreign and not hits:
        hits = ["200-on-foreign-id"]
    flag = "LEAK " + ",".join(hits) if hits else "clean"
    print(f"  {label:58s} {st}  {flag}")
    return st, s, hits


print(f"=== PROBES FROM TENANT D (scope set = {label_set}); tenant C owns course {ids['c_course']}, user {ids['carol']}, attempt {ids['c_attempt']}")
leaks = 0
res = []
for args in [
    ("list courses (admin)", "GET", "/api/admin/courses?per_page=50"),
    ("list courses (public)", "GET", "/api/courses?per_page=50"),
    ("read C course by id (admin)", "GET", f"/api/admin/courses/{ids['c_course']}"),
    ("read C course by id (learner API)", "GET", f"/api/courses/{ids['c_course']}"),
    ("read C lesson by id", "GET", f"/api/admin/lessons/{ids['c_lesson']}"),
    ("read C topic by id", "GET", f"/api/admin/topics/{ids['c_topic']}"),
    ("list users", "GET", "/api/admin/users?per_page=50"),
    ("read C user by id", "GET", f"/api/admin/users/{ids['carol']}"),
    ("user groups", "GET", "/api/admin/user-groups/"),
    ("read C quiz by id", "GET", f"/api/admin/gift-quizes/{ids['c_quiz']}"),
    ("list quiz attempts (all)", "GET", "/api/admin/quiz-attempts?per_page=50"),
    ("quiz attempts filtered by C course", "GET", f"/api/admin/quiz-attempts?course_id={ids['c_course']}"),
    ("read C attempt by id", "GET", f"/api/admin/quiz-attempts/{ids['c_attempt']}"),
    ("stats of C course", "GET", f"/api/admin/stats/course/{ids['c_course']}"),
    ("stats export of C course", "GET", f"/api/admin/stats/course/{ids['c_course']}/export"),
    ("quiz results export", "GET", f"/api/admin/quiz-attempts/export?course_id={ids['c_course']}"),
    ("reports metrics", "GET", "/api/admin/reports/metrics"),
    ("access list of C course", "GET", f"/api/admin/courses/{ids['c_course']}/access"),
]:
    st, s, hits = probe(*args); res.append((args[0], st, hits))
    leaks += bool(hits)

print("--- writes / validation (ids from another tenant)")
st, s, hits = probe("D: access/add C's user to D's course (exists: rule)", "POST", f"/api/admin/courses/{ids['d_course']}/access/add", {"users": [ids['carol']]})
res.append(("access/add foreign user", st, hits))
st, s, hits = probe("D: access list of D course afterwards", "GET", f"/api/admin/courses/{ids['d_course']}/access"); res.append(("access list after", st, hits))
st, s, hits = probe("D: access/add C's course? update C course (PUT-like POST)", "POST", f"/api/admin/courses/{ids['c_course']}", {"title": "HIJACKED BY D"})
res.append(("update foreign course", st, hits))
st, s, hits = probe("D: delete C's course", "DELETE", f"/api/admin/courses/{ids['c_course']}"); res.append(("delete foreign course", st, hits))
st, s, hits = probe("D: create user with C's email (unique:users,email)", "POST", "/api/admin/users", {"email": "carol@c.test", "first_name": "x", "last_name": "y", "password": PW, "password_confirmation": PW, "roles": ["student"], "verified": True, "return_url": "http://localhost/v"})
print("      ->", s[:140].replace("\n", " "))

print("--- shared global state")
stc, sc, _ = raw(c, "PATCH", "/api/admin/roles/student", {"permissions": ["user_delete_self"]}), None, None
st, s, _ = probe("D: reads role 'student' after C narrowed it", "GET", "/api/admin/roles/student")
print("      student role assigned count seen by D:", sum(1 for p in json.loads(s)["data"] if p.get("assigned")))
st, s, _ = probe("C sets registration=disabled; D reads /api/config", "POST", "/api/admin/config", {"config": [{"key": "escola_auth.registration", "value": "disabled"}]}, w=c)
st, s, _ = probe("D reads /api/config", "GET", "/api/config")
print("      registration seen by D:", json.loads(s)["data"]["escola_auth"]["registration"])

print("--- auth: C's learner token used on D's host")
sC = W("pooled-c.localhost"); code, js = sC.call("POST", "/api/auth/login", {"email": "carol@c.test", "password": PW}, quiet=True)
if code == 200:
    sD = W("pooled-d.localhost"); sD.token = js["data"]["token"]
    st, s, hits = probe("C's student token -> D /api/profile/me", "GET", "/api/profile/me", w=sD)
    print("      ->", s[:120])
print(f"\nSUMMARY ({label_set}): {leaks} of the first {len(res) - 5 if len(res) > 5 else len(res)} read probes leaked tenant C data to D")
