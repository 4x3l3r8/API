import sys,json; sys.path.insert(0,'scripts')
from wl import W
ids=json.load(open('evidence/.pooled_ids.json'))
d=W("pooled-d.localhost"); d.login("admin@d.test","d-secret")
c=W("pooled-c.localhost"); c.login("admin@pooled.test","pooled-secret")
def r(w,label):
    code,js=w.call("GET",f"/api/admin/gift-quizes/{ids['c_quiz']}",quiet=True); print(f"  {label:42s} -> {code}")
print("A) after clearing the cache, D asks for C's quiz first:"); r(d,"D reads C quiz (cold cache)")
print("B) C reads its own quiz (populates the cache), then D asks again:"); r(c,"C reads own quiz"); r(d,"D reads C quiz (warm cache)")
