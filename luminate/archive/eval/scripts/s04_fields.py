import sys, os
sys.path.insert(0, os.path.dirname(__file__))
from wl import W

BS = chr(92)
CT = BS.join(["EscolaLms", "Courses", "Models", "Course"])
w = W("api.localhost")
w.login("admin@escolalms.com", "secret")
for f in ["academic_session", "academic_term", "course_code", "sis_course_run_id"]:
    w.call("POST", "/api/admin/model-fields",
           {"name": f, "type": "varchar", "class_type": CT, "default": "", "rules": '["nullable","string"]', "visibility": 1})
c, js = w.call("GET", "/api/admin/model-fields?class_type=" + CT.replace(BS, "%5C"))
print([(m.get("name"), m.get("type")) for m in js.get("data", [])])
