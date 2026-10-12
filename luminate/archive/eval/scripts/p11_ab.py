import sys,json; sys.path.insert(0,'scripts')
from wl import W
PW="Passw0rd!eval"
s=W("pooled-c.localhost"); code,js=s.call("POST","/api/auth/login",{"email":"carol@c.test","password":PW},quiet=True); tok=js["data"]["token"]
for host in ("pooled-c.localhost","pooled-d.localhost"):
    w=W(host); w.token=tok; c,j=w.call("GET","/api/_spike/whoami",quiet=True)
    print("  ",host,c,json.dumps(j)[:200] if c==200 else j)
w=W("pooled-d.localhost"); w.token=tok; print("   D /api/courses/my:",w.call("GET","/api/courses/my",quiet=True)[0])
