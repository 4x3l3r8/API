"""S9: repo health from the public GitHub API (unauthenticated, 60 req/h)."""
import json, urllib.request, urllib.error, datetime, collections, sys

def get(path):
    req = urllib.request.Request("https://api.github.com" + path, headers={"User-Agent": "wellms-eval", "Accept": "application/vnd.github+json"})
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            return json.load(r), dict(r.headers)
    except urllib.error.HTTPError as e:
        return {"_error": e.code, "_body": e.read().decode()[:200]}, {}

def days(s):
    return (datetime.datetime.now(datetime.timezone.utc) - datetime.datetime.fromisoformat(s.replace("Z", "+00:00"))).days

repo = "EscolaLMS/API"
r, h = get(f"/repos/{repo}")
if "_error" in r:
    print("ERR", r); sys.exit()
print(f"{repo}: stars={r['stargazers_count']} forks={r['forks_count']} open_issues(+PRs)={r['open_issues_count']} license={(r.get('license') or {}).get('spdx_id')} default={r['default_branch']} created={r['created_at'][:10]} pushed={r['pushed_at'][:10]} ({days(r['pushed_at'])}d ago) archived={r['archived']}")
print("rate remaining:", h.get("X-RateLimit-Remaining"))

rel, _ = get(f"/repos/{repo}/releases?per_page=5")
print("latest releases:", [(x['tag_name'], x['published_at'][:10]) for x in rel] if isinstance(rel, list) else rel)
tags, _ = get(f"/repos/{repo}/tags?per_page=5")
print("latest tags:", [t['name'] for t in tags] if isinstance(tags, list) else tags)

# commit cadence: last 100 commits on default branch
com, _ = get(f"/repos/{repo}/commits?per_page=100")
if isinstance(com, list):
    ds = [c['commit']['author']['date'][:10] for c in com]
    by_month = collections.Counter(d[:7] for d in ds)
    print("last 100 commits span:", ds[-1], "->", ds[0])
    print("commits by month (last 100):", sorted(by_month.items()))
    authors = collections.Counter((c['commit']['author']['name']) for c in com)
    print("authors in last 100 commits:", authors.most_common(6))

con, _ = get(f"/repos/{repo}/contributors?per_page=30")
if isinstance(con, list):
    tot = sum(c['contributions'] for c in con)
    print("contributors (top 30):", len(con), "| top5:", [(c['login'], c['contributions']) for c in con[:5]], "| top1 share: %.0f%%" % (100 * con[0]['contributions'] / tot))

iss, _ = get(f"/search/issues?q=repo:{repo}+type:issue+state:open&per_page=1")
prs, _ = get(f"/search/issues?q=repo:{repo}+type:pr+state:open&per_page=1")
cl, _ = get(f"/search/issues?q=repo:{repo}+type:issue+state:closed&per_page=1")
print("open issues:", iss.get('total_count'), "| open PRs:", prs.get('total_count'), "| closed issues:", cl.get('total_count'))

# key packages
print("--- key package repos")
for name in ["Courses", "Auth", "TopicTypeGift", "TopicTypes", "Scorm", "HeadlessH5P", "Admin", "Front", "Core", "Lrs", "Video"]:
    x, _ = get(f"/repos/EscolaLMS/{name}")
    if "_error" in x:
        print(f"  {name:14s} {x['_error']}")
        continue
    print(f"  {name:14s} pushed={x['pushed_at'][:10]} ({days(x['pushed_at'])}d) stars={x['stargazers_count']} open={x['open_issues_count']} license={(x.get('license') or {}).get('spdx_id')} archived={x['archived']}")

org, _ = get("/orgs/EscolaLMS")
print("org:", {k: org.get(k) for k in ('public_repos', 'followers', 'created_at')})
