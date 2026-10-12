import sys, os, json, time
sys.path.insert(0, os.path.dirname(__file__))
from wl import W

PW = "Passw0rd!eval"
a = W("api.localhost"); a.login("admin@escolalms.com", "secret")
s = W("api.localhost"); s.login("stud.ab@example.test", PW)


def mine():
    return s.call("GET", "/api/courses/my", quiet=True)[1]["data"]["ids"]


print("== clone response")
c, js = a.call("GET", "/api/admin/courses/2/clone", quiet=True)
print(" ", c, json.dumps(js)[:300])
time.sleep(8)
c, js = a.call("GET", "/api/admin/courses?per_page=20", quiet=True)
print("  courses after 8s:", [(x["id"], x["title"]) for x in js["data"]])

print("== revocation sequence for stud.ab (member of CS=2 and IT=3; course 2 granted to both)")
print("  start:", mine())
c, js = a.call("DELETE", "/api/admin/user-groups/3/members/12", quiet=True)
print("  after removing from IT group:", mine(), "(still in CS group which has access -> expect [2])")
c, js = a.call("DELETE", "/api/admin/user-groups/2/members/12", quiet=True)
print("  after removing from CS group too:", mine(), "(expect [])")
a.call("POST", "/api/admin/user-groups/2/members", {"user_id": 12}, quiet=True)
a.call("POST", "/api/admin/user-groups/3/members", {"user_id": 12}, quiet=True)
print("  after re-adding both:", mine())

print("== individual removal while also in group: access/remove users")
a.call("POST", "/api/admin/courses/2/access/add", {"users": [12]}, quiet=True)
a.call("POST", "/api/admin/courses/2/access/remove", {"users": [12]}, quiet=True)
print("  user removed directly but still in groups:", mine())

print("== existing token after deactivation (revocation latency)")
c, js = a.call("GET", "/api/admin/users/12", quiet=True)
print("  user 12 is_active:", js["data"].get("is_active"))
