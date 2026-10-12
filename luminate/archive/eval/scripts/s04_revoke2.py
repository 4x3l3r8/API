import sys, os, json, time, subprocess
sys.path.insert(0, os.path.dirname(__file__))
from wl import W

PW = "Passw0rd!eval"
a = W("api.localhost"); a.login("admin@escolalms.com", "secret")
s = W("api.localhost"); s.login("stud.ab@example.test", PW)
DC = "docker compose -f api/docker-compose.yml -f docker-compose.override.yml -f docker-compose.tenants.yml --project-directory api -p wellms".split()


def sql(q):
    r = subprocess.run(DC + ["exec", "-T", "postgres", "psql", "-U", "default", "-d", "default", "-tAc", q], capture_output=True, text=True)
    return r.stdout.strip().replace("\n", " | ")


def api_view():
    my = s.call("GET", "/api/courses/my", quiet=True)[1].get("data", {}).get("ids")
    pc, pj = s.call("GET", "/api/courses/2/program", quiet=True)
    return f"my={my} program={pc}"


print("direct enrolment rows for user 12:", sql("select count(*) from course_user where user_id=12"))
print("start            :", api_view(), "| groups:", sql("select group_id from group_user where user_id=12"))
a.call("DELETE", "/api/admin/user-groups/3/members/12", quiet=True)
print("rm IT group      :", api_view(), "| groups:", sql("select group_id from group_user where user_id=12"))
a.call("DELETE", "/api/admin/user-groups/2/members/12", quiet=True)
print("rm CS group too  :", api_view(), "| groups:", sql("select coalesce(string_agg(group_id::text,','),'none') from group_user where user_id=12"))
time.sleep(3)
print("   +3s later     :", api_view())
a.call("POST", "/api/admin/user-groups/2/members", {"user_id": 12}, quiet=True)
a.call("POST", "/api/admin/user-groups/3/members", {"user_id": 12}, quiet=True)
print("re-added         :", api_view())
print("course_user row for 12 after all this:", sql("select count(*) from course_user where user_id=12"))
print("default-tenant queue depth (zset/list):", subprocess.run(DC + ["exec", "-T", "redis", "sh", "-c", "redis-cli -a escola_lms --no-auth-warning type wellms_horizon:queue:default; redis-cli -a escola_lms --no-auth-warning zcard wellms_horizon:queue:default"], capture_output=True, text=True).stdout.split())
