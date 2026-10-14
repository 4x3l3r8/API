"""Tiny helper for the PgBouncer admin console (`SHOW ...`), used by the pooler tests and handy by hand:

    python -I luminate/tests/smoke/pooler_admin.py "SHOW POOLS"
"""
import pathlib
import subprocess
import sys

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[2] / "tools"))
import context_token as ct  # noqa: E402

E = ct.read_env()


def admin(command):
    """Run one admin-console command; return (columns, rows) with each row a dict."""
    p = subprocess.run(
        ["docker", "exec", "-e", f"PGPASSWORD={E['PGBOUNCER_ADMIN_PASSWORD']}", "luminate-wellms-pgbouncer-1",
         "psql", "-h", "127.0.0.1", "-p", "6432", "-U", "pgb_admin", "-d", "pgbouncer", "-A", "-F", "|", "-c", command],
        capture_output=True, text=True)
    if p.returncode != 0:
        raise RuntimeError(p.stderr.strip())
    lines = [l for l in p.stdout.splitlines() if l and not l.startswith("(")]
    cols = lines[0].split("|") if lines else []
    return cols, [dict(zip(cols, l.split("|"))) for l in lines[1:]]


def pool(database="wellms"):
    """The SHOW POOLS row for one database, or {}."""
    return next((r for r in admin("SHOW POOLS")[1] if r.get("database") == database), {})


def stats(database="wellms"):
    return next((r for r in admin("SHOW STATS")[1] if r.get("database") == database), {})


if __name__ == "__main__":
    cols, rows = admin(sys.argv[1] if len(sys.argv) > 1 else "SHOW POOLS")
    print("|".join(cols))
    for r in rows:
        print("|".join(r.values()))
