# Source me. Usage: source scripts/lib.sh ; login admin@escolalms.com secret ; api GET /api/profile/me
B=${B:-http://127.0.0.1:8080}; HOSTH=${HOSTH:-api.localhost}; TOKEN=""
login(){ TOKEN=$(curl -s -H "Host: $HOSTH" -H 'Accept: application/json' -H 'Content-Type: application/json' -d "{\"email\":\"$1\",\"password\":\"$2\"}" $B/api/auth/login | python -c "import sys,json;print(json.load(sys.stdin)['data']['token'])"); }
# api METHOD PATH [json-body]  -> prints "HTTP <code>" then body
api(){ local m=$1 p=$2 b=$3; if [ -n "$b" ]; then curl -s -w '\nHTTP %{http_code}\n' -X $m -H "Host: $HOSTH" -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' -H 'Content-Type: application/json' -d "$b" "$B$p"; else curl -s -w '\nHTTP %{http_code}\n' -X $m -H "Host: $HOSTH" -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' "$B$p"; fi; }
jget(){ python -c "import sys,json;d=json.loads(sys.stdin.read().rsplit('\nHTTP',1)[0]);print(eval('d'+sys.argv[1]))" "$1"; }
