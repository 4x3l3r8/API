"""S8: free-vs-paid evidence table for the 50 escolalms/* packages in composer.lock (generated markdown)."""
import json, re

lock = json.load(open("api/composer.lock", encoding="utf-8"))
pk = {p["name"]: p for p in lock["packages"]}
esc = sorted(n for n in pk if n.startswith("escolalms/"))

# Hand-mapped from what I actually called in the eval (Verified) -- everything else is "not exercised".
USED = {
    "escolalms/core": "all", "escolalms/auth": "S1,S3,S5", "escolalms/permissions": "S5", "escolalms/courses": "S1,S2,S4",
    "escolalms/course-access": "S1,S4,S6", "escolalms/topic-types": "S1,S2,S7", "escolalms/topic-type-gift": "S2,S6",
    "escolalms/scorm": "S2", "escolalms/lrs": "S2 (GPL dep)", "escolalms/cmi5": "S2 (endpoint only)",
    "escolalms/headless-h5p": "S2 (GPL dep)", "escolalms/reports": "S1,S4", "escolalms/settings": "S3",
    "escolalms/model-fields": "S4", "escolalms/templates": "S6", "escolalms/templates-email": "S6", "escolalms/templates-pdf": "S6",
    "escolalms/templates-sms": "S6", "escolalms/csv-users": "S6", "escolalms/video": "S7", "escolalms/files": "S7",
    "escolalms/images": "S7", "escolalms/notifications": "S6",
}
rows = []
for n in esc:
    p = pk[n]
    lic = ",".join(p.get("license") or ["?"])
    desc = (p.get("description") or "").replace("|", "/")[:70]
    rows.append(f"| `{n}` | {p['version']} | {lic} | {desc} | {USED.get(n, 'not exercised')} |")
out = ["| Package | Installed | Licence (composer) | Description | Used in eval |", "|---|---|---|---|---|"] + rows
open("evidence/s08-modules.md", "w", encoding="utf-8").write("\n".join(out) + "\n")
print(len(esc), "packages written; exercised:", sum(1 for n in esc if n in USED))
