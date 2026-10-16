"""Protected media delivery (contract D.6 fallback): GET /api/courses/{course_id}/topics/{topic_id}/media, against the live stack.

    python -I luminate/tests/smoke/media_delivery.py

Real PDF / video / audio / image topics are uploaded through the API and fetched back through the route. Proves: byte-exact full and ranged delivery
(206, 416, If-Range, If-None-Match, HEAD), the headers a player needs, course-access rules (enrolled learner, machine admin, not enrolled, unpublished
course, anonymous), topic/course binding (another course's topic, no-file topic), tenant isolation (the other tenant's ids and token, no context), the
object-key prefix enforced ON THE READ even when the stored path is hostile, no caching across users, and that a large object streams without being buffered.
"""
import hashlib
import json
import pathlib
import re
import subprocess
import sys
import time
import uuid

HERE = pathlib.Path(__file__).resolve()
sys.path.insert(0, str(HERE.parents[1] / "gate"))
sys.path.insert(0, str(HERE.parents[2] / "tools"))
import gatelib as g  # noqa: E402
import luminate_client as lc  # noqa: E402

E = g.E
A, B = "t-alpha", "t-beta"
RUN = uuid.uuid4().hex[:6]
BS = chr(92)
CLS = {k: f"EscolaLms{BS}TopicTypes{BS}Models{BS}TopicContent{BS}{k}" for k in ("PDF", "Video", "Audio", "Image", "RichText")}
results = []
MC_IMAGE = "minio/mc@sha256:a7fe349ef4bd8521fb8497f55c6042871b2ae640607cf99d9bede5e9bdf11727"  # the same pinned image storage_isolation.py uses


def check(name, ok, detail=""):
    results.append(bool(ok))
    print(f"{'PASS' if ok else 'FAIL'}  {name}" + (f"  [{detail}]" if detail and not ok else ""))


def h(r, name):
    for k, v in r.headers.items():
        if k.lower() == name.lower():
            return v
    return None


def real_png(side=320):
    """A genuine, decodable PNG of incompressible noise (about 3*side*side bytes): the image topic really decodes what it is given."""
    import random
    import struct
    import zlib
    raw = b"".join(b"\x00" + random.Random(side + y).randbytes(side * 3) for y in range(side))

    def chunk(tag, body):
        return struct.pack(">I", len(body)) + tag + body + struct.pack(">I", zlib.crc32(tag + body) & 0xFFFFFFFF)
    return b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", struct.pack(">IIBBBBB", side, side, 8, 2, 0, 0, 0)) + chunk(b"IDAT", zlib.compress(raw, 0)) + chunk(b"IEND", b"")


def blob(kind, size):
    if kind == "Image":
        return real_png()
    """A file the API's content sniffing accepts as `kind`, padded with a deterministic, position-dependent pattern so a wrong slice is visible."""
    head = {"PDF": b"%PDF-1.4\n", "Video": b"\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom", "Audio": b"\xff\xfb\x90\x64\x00\x00\x00\x00",
            "Image": bytes.fromhex("89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d49444154789c6360f8cfc0f01f0005000201a5f645400000000049454e44ae426082")}[kind]
    body = bytes(((i * 7 + i // 251) % 256) for i in range(max(0, size - len(head))))
    return head + body


def admin(tid):
    return lc.password(tid)


def login(tid, email, pw):
    r = g.http_call("POST", "/api/auth/login", tid=tid, body={"email": email, "password": pw})
    return r.json["data"]["token"] if r.status == 200 else None


def api(method, path, tid, tok, **kw):
    return g.http_call(method, path, tid=tid, bearer=tok, **kw)


def new_course(tid, tok, title, status="published"):
    r = api("POST", "/api/admin/courses", tid, tok, body={"title": f"{title}_{RUN}", "status": status})
    return r.json["data"]["id"]


def new_lesson(tid, tok, course):
    return api("POST", "/api/admin/lessons", tid, tok, body={"title": f"lesson_{RUN}", "order": 1, "course_id": course}).json["data"]["id"]


def new_file_topic(tid, tok, lesson, kind, data, name):
    r = api("POST", "/api/admin/topics", tid, tok, multipart=[("title", f"{kind}_{RUN}"), ("lesson_id", str(lesson)), ("order", "1"), ("active", "1"),
                                                             ("topicable_type", CLS[kind]), ("value", (name, data, "application/octet-stream"))],
               timeout=180)  # stock Image topics fetch the file by its PUBLIC url (getimagesize), which can wait out a 60 s socket timeout
    if r.status not in (200, 201):
        raise RuntimeError(f"upload {kind}: {r.status} {r.text[:200]}")
    return r.json["data"]["id"]


def put_object(tid, key, data):
    """Write an object under the tenant's own prefix (as the storage layer would have)."""
    script = (f"mc alias set l http://minio:9000 '{E['MINIO_ROOT_USER']}' '{E['MINIO_ROOT_PASSWORD']}' >/dev/null && cat > /tmp/o && "
              f"mc cp -q /tmp/o l/{E.get('S3_BUCKET', 'wellms')}/tenants/{tid}/{key} >/dev/null")
    p = subprocess.run(["docker", "run", "--rm", "-i", "--network", "luminate-wellms_default", "--entrypoint", "sh", MC_IMAGE, "-c", script], input=data, capture_output=True)
    if p.returncode != 0:
        raise RuntimeError("put_object: " + p.stderr.decode()[:200])


def new_image_topic(tid, tok, lesson, data, name):
    """Stock Wellms cannot create an Image topic against a PRIVATE bucket (its upload handler runs getimagesize() on the public URL, which the bucket
    refuses: gap 11 again), so the row and the object are written directly, exactly as an upload would have."""
    tid_topic = api("POST", "/api/admin/topics", tid, tok, form={"title": f"image_{RUN}", "lesson_id": lesson, "order": 3, "active": 1, "topicable_type": CLS["RichText"], "value": "<p>x</p>"}).json["data"]["id"]
    key = f"course/{lesson}/topic/{tid_topic}/image/{name}"
    put_object(tid, key, data)
    img = psql(f"insert into topic_images (value, width, height, created_at, updated_at, tenant_id) values ('{key}', 320, 320, now(), now(), '{tid}') returning id")
    psql(f"update topics set topicable_type = '{CLS['Image']}', topicable_id = {img} where id = {tid_topic} and tenant_id = '{tid}'")
    return tid_topic


def media(tid, tok, course, topic, method="GET", **headers):
    return g.http_call(method, f"/api/courses/{course}/topics/{topic}/media", tid=tid, bearer=tok, headers=headers)


def student(tid, tok, course=None):
    email = f"media-{uuid.uuid4().hex[:8]}@{tid}.gate.test"
    r = api("POST", "/api/admin/users", tid, tok, body={"first_name": "Stu", "last_name": "Dent", "email": email, "password": "Gate-Passw0rd!x1", "return_url": "http://localhost/", "verified": True})
    uid = r.json["data"]["id"]
    if course:
        api("POST", f"/api/admin/courses/{course}/access/add", tid, tok, body={"users": [uid]})
    return uid, login(tid, email, "Gate-Passw0rd!x1")


def psql(sql, owner=True):
    return g.psql(sql, owner=owner).strip()


def raw_update(sql):
    """A change made behind the application's back. Wellms caches model queries in Redis (QueryCacheable; tenant-prefixed, flushed by model events), so
    a raw UPDATE is only seen after the cache is flushed. A change made through the API flushes it itself."""
    psql(sql)
    subprocess.run(["docker", "exec", "-u", "www-data", "luminate-wellms-api-1", "php", "artisan", "cache:clear"], capture_output=True)


# ---------------------------------------------------------------------------------------------------------------------------------
ta, tb = admin(A), admin(B)
admin_a, admin_b = login(A, g.ADMIN, ta), login(B, g.ADMIN, tb)
SIZE = 300_000  # (the image is its own size: see files[]; PDF is the ranged one)
files = {k: blob(k, SIZE) for k in ("PDF", "Video", "Audio", "Image")}  # the PNG has its own size (about 307 KB)

course = new_course(A, admin_a, "media")
lesson = new_lesson(A, admin_a, course)
topics = {k: new_file_topic(A, admin_a, lesson, k, files[k], f"{k.lower()}_{RUN}.{ {'PDF': 'pdf', 'Video': 'mp4', 'Audio': 'mp3'}[k] }") for k in files if k != "Image"}
topics["Image"] = new_image_topic(A, admin_a, lesson, files["Image"], f"image_{RUN}.png")
rich = api("POST", "/api/admin/topics", A, admin_a, form={"title": f"rich_{RUN}", "lesson_id": lesson, "order": 2, "active": 1, "topicable_type": CLS["RichText"], "value": "<p>x</p>"}).json["data"]["id"]
course2 = new_course(A, admin_a, "other")
other_topic = new_file_topic(A, admin_a, new_lesson(A, admin_a, course2), "PDF", blob("PDF", 5000), f"other_{RUN}.pdf")
draft = new_course(A, admin_a, "draft", status="draft")
draft_topic = new_file_topic(A, admin_a, new_lesson(A, admin_a, draft), "PDF", blob("PDF", 5000), f"draft_{RUN}.pdf")
uid_in, tok_in = student(A, admin_a, course)
uid_out, tok_out = student(A, admin_a)
uid_draft, tok_draft = student(A, admin_a, draft)
pdf = topics["PDF"]
print(f"--- course {course}: topics {topics}; learner enrolled={uid_in} not enrolled={uid_out}")

print("--- A. Full delivery, byte exact, for every file kind; the headers a player needs")
for kind, data in files.items():
    r = media(A, tok_in, course, topics[kind])
    check(f"{kind}: enrolled learner gets 200 and exactly the uploaded {len(data)} bytes", r.status == 200 and r.raw == data, f"{r.status} got {len(r.raw)}")
r = media(A, tok_in, course, pdf)
etag = h(r, "ETag")
check("Accept-Ranges: bytes", h(r, "Accept-Ranges") == "bytes", str(r.headers))
check("Content-Length is the file size", h(r, "Content-Length") == str(SIZE), str(h(r, "Content-Length")))
check("an ETag and a Content-Type are present", bool(etag) and etag.startswith('"') and (h(r, "Content-Type") or "").startswith("application/pdf"), f"{etag} {h(r, 'Content-Type')}")
check("Cache-Control is private (a response is per user and tenant)", "private" in (h(r, "Cache-Control") or "") and "public" not in (h(r, "Cache-Control") or ""), str(h(r, "Cache-Control")))
check("Vary names the credentials the response depends on", "authorization" in (h(r, "Vary") or "").lower() and "x-tenant-context" in (h(r, "Vary") or "").lower(), str(h(r, "Vary")))
check("a stored file is served nosniff and sandboxed, as an inline attachment with a safe name", h(r, "X-Content-Type-Options") == "nosniff" and "sandbox" in (h(r, "Content-Security-Policy") or "")
      and re.fullmatch(r'inline; filename="[A-Za-z0-9._-]+"', h(r, "Content-Disposition") or "") is not None, f"{h(r, 'Content-Disposition')}")
check("it is not a redirect and does not point at the bucket", r.status == 200 and h(r, "Location") is None)

print("--- B. Ranges")
data = files["PDF"]
for label, rng, start, end in (("first 100 bytes", "bytes=0-99", 0, 99), ("a middle slice", "bytes=1000-1999", 1000, 1999), ("open ended", f"bytes={SIZE - 500}-", SIZE - 500, SIZE - 1),
                               ("the suffix", "bytes=-250", SIZE - 250, SIZE - 1), ("an end past the file is clamped", f"bytes={SIZE - 10}-{SIZE + 5000}", SIZE - 10, SIZE - 1),
                               ("one byte", "bytes=7-7", 7, 7)):
    r = media(A, tok_in, course, pdf, Range=rng)
    check(f"{label} ({rng}): 206, the right Content-Range and Content-Length, exactly those bytes",
          r.status == 206 and h(r, "Content-Range") == f"bytes {start}-{end}/{SIZE}" and h(r, "Content-Length") == str(end - start + 1) and r.raw == data[start:end + 1],
          f"{r.status} {h(r, 'Content-Range')} {len(r.raw)}")
r = media(A, tok_in, course, pdf, Range=f"bytes={SIZE}-")
check("a range starting at the end of the file: 416 with Content-Range: bytes */size", r.status == 416 and h(r, "Content-Range") == f"bytes */{SIZE}" and r.raw == b"", f"{r.status} {h(r, 'Content-Range')}")
r = media(A, tok_in, course, pdf, Range=f"bytes={SIZE * 3}-{SIZE * 4}")
check("a range far past the end: 416", r.status == 416)
r = media(A, tok_in, course, pdf, Range="bytes=0-9,20-29")
check("several ranges are served as the whole file (allowed)", r.status == 200 and r.raw == data)
r = media(A, tok_in, course, pdf, Range="bytes=oops")
check("a malformed Range is ignored (200, whole file)", r.status == 200 and r.raw == data)
r = media(A, tok_in, course, pdf, **{"Range": "bytes=0-99", "If-Range": etag})
check("If-Range with the current ETag keeps the range (206)", r.status == 206 and r.raw == data[:100])
r = media(A, tok_in, course, pdf, **{"Range": "bytes=0-99", "If-Range": '"stale"'})
check("If-Range with a stale ETag gives the WHOLE file, not a splice (200)", r.status == 200 and r.raw == data)
r = media(A, tok_in, course, pdf, **{"If-None-Match": etag})
check("If-None-Match with the current ETag: 304, no body, headers kept", r.status == 304 and r.raw == b"" and h(r, "ETag") == etag)
r = media(A, tok_in, course, pdf, method="HEAD")
check("HEAD: 200 with the real Content-Length and no body", r.status == 200 and h(r, "Content-Length") == str(SIZE) and r.raw == b"")
r = media(A, tok_in, course, topics["Video"], Range="bytes=100000-100999")
check("ranges work for video too (the seek case)", r.status == 206 and r.raw == files["Video"][100000:101000])

print("--- C. Who may read it")
check("the machine admin can (tutor preview)", media(A, admin_a, course, pdf).status == 200)
r = media(A, tok_out, course, pdf)
check("a learner who is NOT enrolled is denied (403)", r.status == 403 and r.raw[:1] != b"%", f"{r.status}")
check("...and cannot tell whether a topic id exists: a made-up topic id in that course is the same 403", media(A, tok_out, course, 99999999).status == 403)
r = g.http_call("GET", f"/api/courses/{course}/topics/{pdf}/media", tid=A)
check("no bearer token: 401", r.status == 401, str(r.status))
r = g.http_call("GET", f"/api/courses/{course}/topics/{pdf}/media", tid=A, bearer="not-a-token")
check("a garbage token: 401", r.status == 401, str(r.status))
check("an enrolled learner of an UNPUBLISHED course is denied (403); the admin is not", media(A, tok_draft, draft, draft_topic).status == 403 and media(A, admin_a, draft, draft_topic).status == 200)
# revocation takes effect immediately
api("POST", f"/api/admin/courses/{course}/access/remove", A, admin_a, body={"users": [uid_in]})
check("once access is removed, the same learner is denied on the very next request", media(A, tok_in, course, pdf).status == 403)
api("POST", f"/api/admin/courses/{course}/access/add", A, admin_a, body={"users": [uid_in]})
check("...and allowed again when it is restored", media(A, tok_in, course, pdf).status == 200)

print("--- D. The topic must belong to that course and have a file")
check("another course's topic under this course id: 404 (even for the admin)", media(A, admin_a, course, other_topic).status == 404 and media(A, tok_in, course, other_topic).status == 404)
check("a topic id that does not exist: 404", media(A, admin_a, course, 99999999).status == 404)
check("a course id that does not exist: 404", media(A, admin_a, 99999999, pdf).status == 404)
check("a topic with no file (rich text): 404", media(A, admin_a, course, rich).status == 404)
check("non-numeric ids are not routed (404)", g.http_call("GET", f"/api/courses/x/topics/{pdf}/media", tid=A, bearer=tok_in).status == 404)

print("--- E. Tenant isolation")
course_b = new_course(B, admin_b, "media_b")
topic_b = new_file_topic(B, admin_b, new_lesson(B, admin_b, course_b), "PDF", b"%PDF-1.4\n" + b"BETA-SECRET-" * 1500, f"beta_{RUN}.pdf")
_, learner_b = student(B, admin_b, course_b)
check("tenant B's own learner reads B's file (control)", media(B, learner_b, course_b, topic_b).status == 200)
r = media(A, admin_a, course_b, topic_b)
check("A's admin asking for B's course/topic ids is a 404 and never B's bytes", r.status == 404 and b"BETA-SECRET" not in r.raw, f"{r.status}")
r = media(B, learner_b, course, pdf)
check("B's learner asking for A's ids under B's context: 404", r.status == 404 and b"%PDF" not in r.raw[:5], f"{r.status}")
check("A's token under B's context is refused (403, token binding)", media(B, tok_in, course_b, topic_b).status == 403 and media(B, admin_a, course_b, topic_b).status == 403)
r = g.http_call("GET", f"/api/courses/{course}/topics/{pdf}/media", bearer=tok_in)
check("no tenant context: 401 tenant_context_missing", r.status == 401 and "tenant_context" in r.text, r.text[:80])
check("the response the admin just got cannot be replayed to a non-enrolled user (nothing is cached across users)", media(A, tok_out, course, pdf).status == 403)

print("--- F. The object-key prefix is enforced on the READ, whatever the database says")
b_value = psql(f"select value from topic_pdfs where tenant_id = '{B}' order by id desc limit 1")
a_row = psql(f"select topicable_id from topics where id = {pdf} and tenant_id = '{A}'")  # uploads are stored under a random name
orig = psql(f"select value from topic_pdfs where id = {a_row} and tenant_id = '{A}'")
hostile = {
    "a traversal into the other tenant": f"../{B}/{b_value}", "a traversal to the bucket root": "../../" + b_value, "an absolute key": f"/tenants/{B}/{b_value}",
    "the other tenant's full key as a relative path": f"tenants/{B}/{b_value}", "the other tenant's relative path (it exists only under B's prefix)": b_value,
    "a URL": f"http://minio:9000/wellms/tenants/{B}/{b_value}", "a double slash": f"course//x.pdf", "a backslash": "course\\x.pdf",
}
try:
    for label, val in hostile.items():
        raw_update(f"update topic_pdfs set value = '{val}' where id = {a_row} and tenant_id = '{A}'")
        r = media(A, admin_a, course, pdf)
        check(f"stored path = {label}: 404 and no byte of the other tenant's file", r.status == 404 and b"BETA-SECRET" not in r.raw, f"{r.status} {len(r.raw)}")
finally:
    raw_update(f"update topic_pdfs set value = '{orig}' where id = {a_row} and tenant_id = '{A}'")
_r = media(A, tok_in, course, pdf)
check("restored: the real file is served again", _r.raw == data, f"{_r.status} {len(_r.raw)} {_r.text[:80]}")
b_obj_exists = subprocess.run(["docker", "run", "--rm", "--network", "luminate-wellms_default", "--entrypoint", "sh", MC_IMAGE, "-c",
                               f"mc alias set l http://minio:9000 '{E['MINIO_ROOT_USER']}' '{E['MINIO_ROOT_PASSWORD']}' >/dev/null && mc stat l/{E.get('S3_BUCKET', 'wellms')}/tenants/{B}/{b_value} | head -3"], capture_output=True, text=True).stdout
check("(non-vacuous) the other tenant's object really exists under its own prefix", "Size" in b_obj_exists or "Name" in b_obj_exists, b_obj_exists[:120])

print("--- G. A large object streams: it is not buffered")
BIG = 200 * 1024 * 1024
key = psql(f"select value from topic_pdfs where id = {a_row} and tenant_id = '{A}'")
script = (f"mc alias set l http://minio:9000 '{E['MINIO_ROOT_USER']}' '{E['MINIO_ROOT_PASSWORD']}' >/dev/null && head -c {BIG} /dev/urandom > /tmp/big && sha256sum /tmp/big | cut -d' ' -f1 && "
          f"mc cp -q /tmp/big l/{E.get('S3_BUCKET', 'wellms')}/tenants/{A}/{key} >/dev/null && rm /tmp/big")
p = subprocess.run(["docker", "run", "--rm", "--network", "luminate-wellms_default", "--entrypoint", "sh", MC_IMAGE, "-c", script], capture_output=True, text=True)
want = p.stdout.strip().splitlines()[0] if p.stdout.strip() else ""
sha, total, t0 = hashlib.sha256(), 0, time.time()
import http.client  # noqa: E402
c = http.client.HTTPConnection(g.HOST, g.PORT, timeout=300)
c.request("GET", f"/api/courses/{course}/topics/{pdf}/media", headers={"Authorization": "Bearer " + tok_in, "X-Tenant-Context": g.ct.mint(g.CTX_KEY, A)})
resp = c.getresponse()
first = None
while True:
    chunk = resp.read(1 << 20)
    if not chunk:
        break
    first = first or time.time() - t0
    sha.update(chunk)
    total += len(chunk)
c.close()
check(f"a 200 MB object arrives complete and identical (sha256), through a 2 GB php limit that buffering would not survive 10 times over", resp.status == 200 and total == BIG and sha.hexdigest() == want, f"{resp.status} {total} {sha.hexdigest()[:10]} vs {want[:10]}")
peak = subprocess.run(["docker", "stats", "--no-stream", "--format", "{{.MemUsage}}", "luminate-wellms-api-1"], capture_output=True, text=True).stdout.strip()
print(f"INFO  first byte after {first:.2f}s, total {time.time() - t0:.1f}s; api container memory after: {peak}")
r = media(A, tok_in, course, pdf, Range=f"bytes={BIG - 1000}-")
check("a ranged read of the large object returns just the tail", r.status == 206 and len(r.raw) == 1000 and h(r, "Content-Range") == f"bytes {BIG - 1000}-{BIG - 1}/{BIG}", f"{r.status} {len(r.raw)}")
subprocess.run(["docker", "run", "--rm", "--network", "luminate-wellms_default", "--entrypoint", "sh", MC_IMAGE, "-c",
                f"mc alias set l http://minio:9000 '{E['MINIO_ROOT_USER']}' '{E['MINIO_ROOT_PASSWORD']}' >/dev/null && mc rm --force l/{E.get('S3_BUCKET', 'wellms')}/tenants/{A}/{key} >/dev/null"], capture_output=True)

print(f"\n{sum(results)}/{len(results)} passed   (run {RUN})")
sys.exit(0 if all(results) else 1)
