"""Pooled follow-up, test 3: source census inside the container (run with python3 in /var/www/html).
Counts Eloquent models, existing global scopes, and every query path that would bypass an Eloquent global scope."""
import os, re, json, collections

ROOT = "/var/www/html"
dirs = [os.path.join(ROOT, "vendor/escolalms"), os.path.join(ROOT, "app")]
SKIP = ("/tests/", "/database/", "/Testing/", "/node_modules/")


def php_files():
    for base in dirs:
        for dp, dn, fn in os.walk(base):
            if any(s in dp + "/" for s in SKIP):
                continue
            for f in fn:
                if f.endswith(".php"):
                    yield os.path.join(dp, f)


def pkg(path):
    m = re.search(r"vendor/escolalms/([^/]+)/", path)
    return m.group(1) if m else "app"


PAT = {
    "DB:: facade (any)": re.compile(r"\bDB::"),
    "DB::raw/select/statement/table": re.compile(r"\bDB::(raw|select|statement|table|unprepared|insert|update|delete)\b"),
    "raw SQL fragments (whereRaw/selectRaw/orderByRaw/havingRaw/groupByRaw/joinRaw)": re.compile(r"->(whereRaw|selectRaw|orderByRaw|havingRaw|groupByRaw|orWhereRaw|fromRaw)\("),
    "sub-selects/joins on query builder (join/leftJoin/joinSub/fromSub)": re.compile(r"->(join|leftJoin|rightJoin|crossJoin|joinSub|leftJoinSub|fromSub)\("),
    "validation exists:/unique: strings or Rule::exists/unique": re.compile(r"""['"](exists|unique):|Rule::(exists|unique)"""),
    "Cache:: / cache() / remember": re.compile(r"\bCache::|\bcache\(|->remember\("),
    "Storage:: / disk(": re.compile(r"\bStorage::|->disk\("),
    "dispatch/queue jobs": re.compile(r"::dispatch\(|dispatch\(new |dispatchSync|->onQueue\("),
    "withoutGlobalScope(s)": re.compile(r"withoutGlobalScope"),
    "addGlobalScope / GlobalScope class": re.compile(r"addGlobalScope|implements Scope|use .*\\Scope;"),
    "SoftDeletes (is a global scope)": re.compile(r"\buse SoftDeletes\b|SoftDeletes;"),
    "Eloquent model class": re.compile(r"class\s+\w+\s+extends\s+(\\?[\w\\]*Model|\\?[\w\\]*User|Authenticatable|Pivot|AbstractTopicContent|AbstractTopicFileContent|\w*Model)\b"),
    "Artisan/Facade query cache (rennokki QueryCacheable)": re.compile(r"QueryCacheable|cacheFor\(|dontCache"),
    "scout/fulltext (to_tsvector, Searchable, ilike)": re.compile(r"to_tsvector|use Searchable|Searchable;|\bilike\b|ILIKE|MATCH \(.*\) AGAINST"),
    "Export (maatwebsite) / Excel::": re.compile(r"Maatwebsite|Excel::|FromQuery|FromCollection"),
    "->getConnection()/->getPdo()/DB::connection": re.compile(r"getConnection\(\)|getPdo\(\)|DB::connection"),
}
counts = {k: collections.Counter() for k in PAT}
examples = {k: collections.defaultdict(list) for k in PAT}
model_files = collections.defaultdict(list)
for f in php_files():
    try:
        txt = open(f, encoding="utf-8", errors="ignore").read()
    except Exception:
        continue
    p = pkg(f)
    for k, rx in PAT.items():
        n = len(rx.findall(txt))
        if n:
            counts[k][p] += n
            if len(examples[k][p]) < 3:
                examples[k][p].append(os.path.relpath(f, ROOT) + f" ({n})")
    if "Models/" in f and re.search(r"class\s+\w+\s+extends", txt):
        model_files[p].append(os.path.basename(f))

out = {}
print("== Eloquent-ish model files under */Models/ per package")
tot = 0
for p, fs in sorted(model_files.items(), key=lambda x: -len(x[1])):
    tot += len(fs)
print("total files in Models/ dirs:", tot, "in", len(model_files), "packages")
print()
for k in PAT:
    total = sum(counts[k].values())
    top = counts[k].most_common(8)
    print(f"-- {k}: {total} occurrences in {len(counts[k])} packages")
    print("   top:", ", ".join(f"{p}={n}" for p, n in top))
    out[k] = {"total": total, "by_pkg": dict(counts[k])}
out["models_total"] = tot
out["models_by_pkg"] = {p: len(fs) for p, fs in model_files.items()}
json.dump(out, open("/tmp/p03.json", "w"), indent=1)
print()
print("== risky files (DB:: raw/table/select, whereRaw/selectRaw, joinSub) examples")
for k in ("DB::raw/select/statement/table", "raw SQL fragments (whereRaw/selectRaw/orderByRaw/havingRaw/groupByRaw/joinRaw)", "sub-selects/joins on query builder (join/leftJoin/joinSub/fromSub)", "Cache:: / cache() / remember", "Export (maatwebsite) / Excel::"):
    print(" *", k)
    for p, ex in sorted(examples[k].items(), key=lambda x: -counts[k][x[0]])[:7]:
        print("    ", p, "->", "; ".join(ex))
