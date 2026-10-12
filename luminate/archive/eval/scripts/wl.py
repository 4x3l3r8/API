"""Tiny Wellms REST helper for the eval (stdlib only). Usage: from wl import W; w=W('api.localhost'); w.login(email,pw); w.call('GET','/api/profile/me')"""
import json, urllib.request, urllib.error

BASE = "http://127.0.0.1:8080"


class W:
    def __init__(self, host="api.localhost"):
        self.host, self.token = host, None

    def call(self, method, path, body=None, form=False, quiet=False):
        data, headers = None, {"Host": self.host, "Accept": "application/json"}
        if self.token:
            headers["Authorization"] = "Bearer " + self.token
        if body is not None:
            if form:
                from urllib.parse import urlencode
                data = urlencode(body).encode()
                headers["Content-Type"] = "application/x-www-form-urlencoded"
            else:
                data = json.dumps(body).encode()
                headers["Content-Type"] = "application/json"
        req = urllib.request.Request(BASE + path, data=data, headers=headers, method=method)
        try:
            with urllib.request.urlopen(req, timeout=120) as r:
                code, txt = r.status, r.read().decode()
        except urllib.error.HTTPError as e:
            code, txt = e.code, e.read().decode()
        try:
            js = json.loads(txt)
        except Exception:
            js = {"_raw": txt[:300]}
        if not quiet:
            msg = js.get("message") or js.get("error") or ""
            errs = js.get("errors")
            print(f"  {method} {path} -> {code} {str(msg)[:80]}" + (f" errors={json.dumps(errs)[:200]}" if errs else ""))
        return code, js

    def login(self, email, pw):
        c, js = self.call("POST", "/api/auth/login", {"email": email, "password": pw}, quiet=True)
        self.token = js["data"]["token"]
        return self.token


def multipart(self, method, path, fields=None, files=None, quiet=False):
    """files: {name: (filename, bytes, content_type)}"""
    boundary = "----evalboundary"
    CRLF = bytes([13, 10])
    body = b""
    for k, v in (fields or {}).items():
        body += ("--" + boundary).encode() + CRLF + ('Content-Disposition: form-data; name="%s"' % k).encode() + CRLF + CRLF + str(v).encode() + CRLF
    for k, (fn, data, ct) in (files or {}).items():
        body += ("--" + boundary).encode() + CRLF + ('Content-Disposition: form-data; name="%s"; filename="%s"' % (k, fn)).encode() + CRLF
        body += ("Content-Type: " + ct).encode() + CRLF + CRLF + data + CRLF
    body += ("--" + boundary + "--").encode() + CRLF
    headers = {"Host": self.host, "Accept": "application/json", "Content-Type": "multipart/form-data; boundary=" + boundary}
    if self.token:
        headers["Authorization"] = "Bearer " + self.token
    req = urllib.request.Request(BASE + path, data=body, headers=headers, method=method)
    try:
        with urllib.request.urlopen(req, timeout=600) as r:
            code, txt = r.status, r.read().decode()
    except urllib.error.HTTPError as e:
        code, txt = e.code, e.read().decode()
    try:
        js = json.loads(txt)
    except Exception:
        js = {"_raw": txt[:300]}
    if not quiet:
        print(f"  {method} {path} -> {code} {str(js.get('message') or js.get('error') or '')[:80]}" + (f" errors={json.dumps(js.get('errors'))[:200]}" if js.get("errors") else ""))
    return code, js


W.multipart = multipart
