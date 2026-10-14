"""DEV-ONLY stand-in for the Luminate webhook receiver (contract v4 section F). Not for production.

It does what a real receiver must: verifies X-Wellms-Signature over "{delivery_id}.{timestamp}.{raw_body}" with the current OR
previous secret, validates the shapes it signs over, acks duplicates, and records everything so the end-to-end test can assert
on what Wellms really sent. It can be told to fail on purpose to exercise retries and dead-lettering.

    POST /hook      the webhook endpoint
    GET  /received  every request seen (JSON)
    POST /control   {"fail_next": N, "status": 500, "always_fail": false, "delay": 0}
    POST /reset     forget everything, clear the failure settings
"""
import hashlib
import hmac
import json
import os
import re
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

SECRETS = [s for s in (os.environ.get("LUMINATE_WEBHOOK_SECRET"), os.environ.get("LUMINATE_WEBHOOK_SECRET_PREVIOUS")) if s]
UUID_RE = re.compile(r"^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$")
LOCK = threading.Lock()
STATE = {"received": [], "seen": set(), "control": {"fail_next": 0, "status": 500, "always_fail": False, "delay": 0}}


def signature_ok(header, delivery_id, timestamp, raw):
    message = delivery_id.encode() + b"." + timestamp.encode() + b"." + raw
    return any(hmac.compare_digest(header, "sha256=" + hmac.new(s.encode(), message, hashlib.sha256).hexdigest()) for s in SECRETS)


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *a):  # quiet
        pass

    def _send(self, status, payload=None):
        body = json.dumps(payload if payload is not None else {}).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _raw(self):
        return self.rfile.read(int(self.headers.get("Content-Length") or 0))

    def do_GET(self):
        if self.path == "/received":
            with LOCK:
                return self._send(200, STATE["received"])
        self._send(404)

    def do_POST(self):
        raw = self._raw()
        if self.path == "/reset":
            with LOCK:
                STATE["received"].clear()
                STATE["seen"].clear()
                STATE["control"].update({"fail_next": 0, "status": 500, "always_fail": False, "delay": 0})
            return self._send(200)
        if self.path == "/control":
            with LOCK:
                STATE["control"].update(json.loads(raw or b"{}"))
            return self._send(200, STATE["control"])
        if self.path != "/hook":
            return self._send(404)

        delivery = self.headers.get("X-Wellms-Delivery", "")
        timestamp = self.headers.get("X-Wellms-Timestamp", "")
        sig = self.headers.get("X-Wellms-Signature", "")
        shapes_ok = bool(UUID_RE.match(delivery)) and timestamp.isdigit()
        sig_ok = shapes_ok and signature_ok(sig, delivery, timestamp, raw)

        with LOCK:
            ctl = STATE["control"]
            delay = ctl["delay"]
        if delay:
            time.sleep(delay)
        with LOCK:
            duplicate = delivery in STATE["seen"]
            if sig_ok and (ctl["always_fail"] or ctl["fail_next"] > 0):
                if not ctl["always_fail"]:
                    ctl["fail_next"] -= 1
                status = int(ctl["status"])
            elif not sig_ok:
                status = 401
            else:
                status = 200
                STATE["seen"].add(delivery)
            try:
                parsed = json.loads(raw)
            except Exception:
                parsed = None
            STATE["received"].append({
                "n": len(STATE["received"]) + 1, "at": time.time(), "event": self.headers.get("X-Wellms-Event"), "delivery_id": delivery,
                "timestamp": timestamp, "signature": sig, "signature_ok": sig_ok, "shapes_ok": shapes_ok, "duplicate": duplicate,
                "responded": status, "content_type": self.headers.get("Content-Type"), "raw": raw.decode("utf-8", "replace"), "json": parsed,
            })
        self._send(status)


if __name__ == "__main__":
    ThreadingHTTPServer(("0.0.0.0", int(os.environ.get("PORT", "8099"))), Handler).serve_forever()
