"""S9/S8/S10: per-package table for the 50 escolalms/* packages: version, licence, repo, last push, open issues."""
import json, urllib.request, urllib.error, datetime, sys, re

lock = json.load(open("api/composer.lock", encoding="utf-8"))
pk = [p for p in lock["packages"] if p["name"].startswith("escolalms/")]


def get(path):
    req = urllib.request.Request("https://api.github.com" + path, headers={"User-Agent": "wellms-eval", "Accept": "application/vnd.github+json"})
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            return json.load(r), r.headers.get("X-RateLimit-Remaining")
    except urllib.error.HTTPError as e:
        return {"_error": e.code}, e.headers.get("X-RateLimit-Remaining")


now = datetime.datetime.now(datetime.timezone.utc)
rows = []
for p in sorted(pk, key=lambda x: x["name"]):
    repo = re.sub(r"^https://github.com/|\.git$", "", p["source"]["url"])
    info, rem = get(f"/repos/{repo}")
    if "_error" in info:
        rows.append((p["name"], p["version"], ",".join(p.get("license") or ["?"]), repo, info["_error"], "", ""))
        if info["_error"] == 403:
            print("rate limited, stopping", file=sys.stderr)
            break
        continue
    pushed = datetime.datetime.fromisoformat(info["pushed_at"].replace("Z", "+00:00"))
    rows.append((p["name"], p["version"], ",".join(p.get("license") or ["?"]), repo, info["pushed_at"][:10], (now - pushed).days, info["open_issues_count"]))
print(f"{'package':34s} {'ver':10s} {'lic':11s} {'last push':10s} {'days':>5s} {'open':>4s}  repo")
for r in rows:
    print(f"{r[0]:34s} {str(r[1]):10s} {r[2]:11s} {str(r[4]):10s} {str(r[5]):>5s} {str(r[6]):>4s}  {r[3]}")
ages = [r[5] for r in rows if isinstance(r[5], int)]
if ages:
    ages.sort()
    print("\npackages with data:", len(ages), "| median days since last push:", ages[len(ages) // 2], "| >365d:", sum(a > 365 for a in ages), "| >730d:", sum(a > 730 for a in ages), "| <=90d:", sum(a <= 90 for a in ages))
