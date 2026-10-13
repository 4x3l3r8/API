"""Mint X-Tenant-Context tokens the way the Luminate proxy does (contract v3 §A.1). Dev/test tool, stdlib only.

    python -I luminate/tools/context_token.py --tid t-alpha            # uses TENANT_CONTEXT_KEY from luminate/infra/.env
    python -I luminate/tools/context_token.py --tid t-alpha --status suspended --ttl 60
"""
import argparse
import base64
import hashlib
import hmac
import json
import pathlib
import time
import uuid

ENV_FILE = pathlib.Path(__file__).resolve().parents[1] / "infra" / ".env"


def read_env(path: pathlib.Path = ENV_FILE) -> dict:
    env = {}
    for line in path.read_text(encoding="utf-8").splitlines():
        if line and not line.startswith("#") and "=" in line:
            k, v = line.split("=", 1)
            env[k] = v
    return env


def _b64(raw: bytes) -> str:
    return base64.urlsafe_b64encode(raw).rstrip(b"=").decode()


def mint(key: str, tid: str, *, status: str = "active", mode: str = "pooled", connection: str = "shard-1",
         bucket: str = "wellms", cache_prefix: str | None = None, ttl: int = 300, iat: int | None = None,
         jti: str | None = None, alg: str = "HS256", extra: dict | None = None) -> str:
    """Return a compact JWS. Arguments let tests build deliberately bad tokens."""
    iat = int(time.time()) if iat is None else iat
    claims = {
        "tid": tid,
        "status": status,
        "placement": {"mode": mode, "connection": connection, "bucket": bucket,
                      "cache_prefix": cache_prefix or tid},
        "iat": iat,
        "exp": iat + ttl,
        "jti": jti or uuid.uuid4().hex,
    }
    if extra:
        claims.update(extra)
    header = _b64(json.dumps({"alg": alg, "typ": "JWT"}, separators=(",", ":")).encode())
    payload = _b64(json.dumps(claims, separators=(",", ":")).encode())
    sig = hmac.new(key.encode(), f"{header}.{payload}".encode(), hashlib.sha256).digest()
    return f"{header}.{payload}.{_b64(sig)}"


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--tid", required=True)
    ap.add_argument("--status", default="active")
    ap.add_argument("--mode", default="pooled")
    ap.add_argument("--connection", default="shard-1")
    ap.add_argument("--bucket", default="wellms")
    ap.add_argument("--ttl", type=int, default=300)
    ap.add_argument("--key", help="defaults to TENANT_CONTEXT_KEY from luminate/infra/.env")
    a = ap.parse_args()
    print(mint(a.key or read_env()["TENANT_CONTEXT_KEY"], a.tid, status=a.status, mode=a.mode,
               connection=a.connection, bucket=a.bucket, ttl=a.ttl))
