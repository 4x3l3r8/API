"""Stand-in for Luminate's webhook endpoint. Verifies HMAC, logs deliveries, de-duplicates on delivery id.
Fail injection: write an integer N into evidence/.fail_next -> next N requests get HTTP 500."""
import hmac, hashlib, json, os, sys, time
from http.server import BaseHTTPRequestHandler, HTTPServer

SECRET = b"eval-shared-secret"
ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "evidence")
LOG = os.path.join(ROOT, "webhook-received.jsonl")
FAIL = os.path.join(ROOT, ".fail_next")
seen = set()


class H(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def do_POST(self):
        n = int(self.headers.get("Content-Length", 0))
        body = self.rfile.read(n)
        sig = self.headers.get("X-Wellms-Signature", "")
        good = "sha256=" + hmac.new(SECRET, body, hashlib.sha256).hexdigest()
        ok_sig = hmac.compare_digest(sig, good)
        delivery = self.headers.get("X-Wellms-Delivery")
        fail = 0
        if os.path.exists(FAIL):
            try:
                fail = int(open(FAIL).read().strip() or 0)
            except Exception:
                fail = 0
        status = 200
        if not ok_sig:
            status = 401
        elif fail > 0:
            open(FAIL, "w").write(str(fail - 1))
            status = 500
        dup = delivery in seen
        if status == 200:
            seen.add(delivery)
        rec = {"t": time.strftime("%H:%M:%S"), "status": status, "sig_ok": ok_sig, "duplicate": dup,
               "event": self.headers.get("X-Wellms-Event"), "delivery": delivery, "body": json.loads(body or b"{}")}
        open(LOG, "a", encoding="utf-8").write(json.dumps(rec) + "\n")
        self.send_response(status)
        self.end_headers()
        self.wfile.write(b"ok")


HTTPServer(("0.0.0.0", 9099), H).serve_forever()
