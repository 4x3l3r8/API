"""DEV-ONLY stand-in for Luminate's tenant-registry endpoint (contract §A.2 reconcile pull). Not for production.

It serves the authoritative tenant list the way Luminate must: the request is verified (HMAC over timestamp, method, path and body),
and the response is signed so Wellms can refuse a forged list. The list starts as the dev snapshot, so a freshly started stack
converges on exactly the tenants it was seeded with. It can be told to misbehave on purpose.

    GET  /registry/tenants     the signed listing (what Wellms pulls)
    GET  /_control             requests seen so far and whether each one verified
    POST /_control             {"tenants": {tid: {status, rev, placement}} | null, "as_of": n | null,
                                "mode": "ok|http500|badsig|notjson|nots", "reset": true}
"""
import hashlib
import hmac
import json
import os
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

KEYS = [k for k in (os.environ.get("LUMINATE_REGISTRY_KEY"), os.environ.get("LUMINATE_REGISTRY_KEY_PREVIOUS")) if k]
SNAPSHOT = os.environ.get("SNAPSHOT", "/etc/luminate/tenants.json")
LOCK = threading.Lock()


def initial():
    try:
        tenants = json.load(open(SNAPSHOT))["tenants"]
    except Exception:
        tenants = {}
    return {tid: {"status": t["status"], "rev": 1, "placement": t["placement"]} for tid, t in tenants.items()}


STATE = {"tenants": initial(), "as_of": None, "mode": "ok", "seen": []}


def sign(key, message):
    return "sha256=" + hmac.new(key.encode(), message.encode(), hashlib.sha256).hexdigest()


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def _send(self, status, payload, headers=None, raw=None):
        body = raw if raw is not None else json.dumps(payload).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        for k, v in (headers or {}).items():
            self.send_header(k, v)
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        if self.path == "/_control":
            with LOCK:
                return self._send(200, {"seen": STATE["seen"], "mode": STATE["mode"], "as_of": STATE["as_of"]})
        if self.path != "/registry/tenants":
            return self._send(404, {})
        ts = self.headers.get("X-Luminate-Timestamp", "")
        sig = self.headers.get("X-Luminate-Signature", "")
        msg = f"{ts}.GET.{self.path}.{hashlib.sha256(b'').hexdigest()}"
        ok = bool(ts) and abs(time.time() - int(ts or 0)) <= 300 and any(hmac.compare_digest(sig, sign(k, msg)) for k in KEYS)
        with LOCK:
            STATE["seen"].append({"at": int(time.time()), "verified": ok})
            mode, tenants, as_of = STATE["mode"], STATE["tenants"], STATE["as_of"]
        if not ok:
            return self._send(401, {"code": "bad_request_signature"})
        if mode == "http500":
            return self._send(500, {"error": "boom"})
        body = b"<html>not json</html>" if mode == "notjson" else json.dumps({
            "as_of": int(time.time()) if as_of is None else as_of,
            "tenants": [{"tenant_id": tid, **t} for tid, t in tenants.items()],
        }).encode()
        rts = str(int(time.time()))
        headers = {"X-Luminate-Timestamp": rts,
                   "X-Luminate-Signature": "sha256=" + "0" * 64 if mode == "badsig" else sign(KEYS[0], f"response.{rts}.{hashlib.sha256(body).hexdigest()}")}
        if mode == "nots":
            headers = {}
        self._send(200, None, headers, raw=body)

    def do_POST(self):
        if self.path != "/_control":
            return self._send(404, {})
        data = json.loads(self.rfile.read(int(self.headers.get("Content-Length") or 0)) or b"{}")
        with LOCK:
            if data.get("reset"):
                STATE.update({"tenants": initial(), "as_of": None, "mode": "ok", "seen": []})
            if "tenants" in data and data["tenants"] is not None:
                STATE["tenants"] = data["tenants"]
            if "as_of" in data:
                STATE["as_of"] = data["as_of"]
            if "mode" in data:
                STATE["mode"] = data["mode"]
        self._send(200, {"ok": True})


ThreadingHTTPServer(("0.0.0.0", 8098), Handler).serve_forever()
