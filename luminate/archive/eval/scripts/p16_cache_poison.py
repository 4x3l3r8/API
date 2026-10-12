import sys,json,subprocess; sys.path.insert(0,'scripts')
from wl import W
ids=json.load(open('evidence/.pooled_ids.json'))
d=W("pooled-d.localhost"); d.login("admin@d.test","d-secret")
c=W("pooled-c.localhost"); c.login("admin@pooled.test","pooled-secret")
def clear():
    subprocess.run(["docker","exec","wellms-api-1","php","artisan","cache:clear","--domain=pooled-c.localhost"],capture_output=True)
def r(w,label):
    code,js=w.call("GET",f"/api/admin/gift-quizes/{ids['c_quiz']}",quiet=True); print(f"  {label:44s} -> {code}"); return code
print("Run 1: owner C reads first (cold cache)"); clear(); r(c,"C reads own quiz"); r(d,"D reads C's quiz (RLS must hide it)"); r(c,"C reads own quiz again")
print("Run 2: attacker/other tenant D reads first (cold cache)"); clear(); r(d,"D reads C's quiz"); r(c,"C reads own quiz (poisoned by D's miss?)")
print("Run 3: owner reads, then cache is NOT cleared, D reads (does D get C's cached row?)"); clear(); r(c,"C reads own quiz"); r(d,"D reads C's quiz (warm cache)")
clear()
