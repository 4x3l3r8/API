"""S7 media: upload via REST, inspect storage keys / URLs / access / processing."""
import sys, os, json, time, urllib.request, urllib.error, base64
sys.path.insert(0, os.path.dirname(__file__))
from wl import W, BASE

S = r"C:\Users\DCADES~1\AppData\Local\Temp\claude\c--Users-dcadesanya-source-repos-wellms-eval\4488d39f-96b9-4e78-92be-bae348fd4bf8\scratchpad"
HOST = "tenant-b.localhost"
BS = chr(92)
T = lambda *p: BS.join(["EscolaLms"] + list(p))
a = W(HOST); a.login("admin@tenant-b.test", "beta-secret")

mp4 = open(os.path.join(S, "eval.mp4"), "rb").read()
mp3 = open(os.path.join(S, "eval.mp3"), "rb").read()
png = base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==")
pdf = b"%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF"

c, js = a.call("POST", "/api/admin/courses", {"title": "Media test", "status": "published"}, quiet=True); cid = js["data"]["id"]
c, js = a.call("POST", "/api/admin/lessons", {"title": "Media", "order": 1, "course_id": cid}, quiet=True); lid = js["data"]["id"]

topics = {}
print("== topic content uploads (multipart, field 'value')")
for label, ttype, fname, data, ct in [
    ("Video", T("TopicTypes", "Models", "TopicContent", "Video"), "eval.mp4", mp4, "video/mp4"),
    ("Audio", T("TopicTypes", "Models", "TopicContent", "Audio"), "eval.mp3", mp3, "audio/mpeg"),
    ("Image", T("TopicTypes", "Models", "TopicContent", "Image"), "pic.png", png, "image/png"),
    ("PDF", T("TopicTypes", "Models", "TopicContent", "PDF"), "doc.pdf", pdf, "application/pdf"),
]:
    c, js = a.multipart("POST", "/api/admin/topics", {"title": label, "lesson_id": lid, "order": len(topics) + 1, "active": 1, "topicable_type": ttype}, {"value": (fname, data, ct)}, quiet=True)
    d = js.get("data", {})
    tp = d.get("topicable", {}) if isinstance(d, dict) else {}
    topics[label] = d.get("id")
    print(f"  {label:6s} -> {c} topic={d.get('id')} topicable keys={sorted(tp.keys())[:8]} value={str(tp.get('value'))[:70]} url={str(tp.get('url') or tp.get('value_url'))[:90]} {'' if c < 300 else js}")

print("== course cover image/poster via multipart")
c, js = a.multipart("POST", f"/api/admin/courses/{cid}", {"title": "Media test"}, {"image": ("cover.png", png, "image/png"), "poster": ("poster.png", png, "image/png")}, quiet=True)
d = js.get("data", {}); print("  ->", c, {k: str(d.get(k))[:100] for k in ("image_path", "image_url", "poster_path", "poster_url")})

print("== topic resource attach")
if topics.get("Video"):
    c, js = a.multipart("POST", f"/api/admin/topics/{topics['Video']}/resources/", {}, {"resource": ("notes.pdf", pdf, "application/pdf")}, quiet=True)
    print("  ->", c, str(js)[:260])

print("== file manager upload/list")
c, js = a.multipart("POST", "/api/admin/file/upload", {"target": "eval-folder"}, {"file[]": ("doc.pdf", pdf, "application/pdf")}, quiet=True)
print("  upload ->", c, str(js)[:260])
c, js = a.call("GET", "/api/admin/file/list?dir=eval-folder", quiet=True)
print("  list ->", c, str(js)[:300])

json.dump({"cid": cid, "topics": topics}, open(os.path.join(os.path.dirname(__file__), "..", "evidence", ".s07"), "w"))
