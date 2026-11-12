"""Run against an isolated configured FrankenPHP instance, never creates a payment.
Usage: EC_TEST_STRIPE_SECRET=... python3 tests/Deployment/http_smoke.py http://127.0.0.1:18086
The target needs canonical energy-cost.eu, test GitHub provider and test Stripe config.
"""
import hashlib
import hmac
import json
import os
from pathlib import Path
import sys
import time
import urllib.error
import urllib.request
from urllib.parse import parse_qs, urlsplit

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

origin=sys.argv[1].rstrip("/")
opener=urllib.request.build_opener(NoRedirect())
count=0
def request(path,status,body=None,headers=None):
    global count
    h={"Host":"energy-cost.eu","X-Forwarded-Proto":"https","X-Forwarded-For":"203.0.113.10"}
    h.update(headers or {})
    req=urllib.request.Request(origin+path,data=body,headers=h)
    try:
        response=opener.open(req,timeout=15)
    except urllib.error.HTTPError as error:
        response=error
    data=response.read()
    assert response.status==status,(path,response.status,data[:150])
    count+=1
    return response.headers,data

_,health=request("/healthz",200)
assert health==b"ok\n",health
for path in ("/.git/config","/app/config/config.php","/vendor/autoload.php","/backups/dump.sql.gz",
             "/deploy/healthz.php","/healthz.php","/var/notifications/load-profiles.json"):
    request(path,404)
for locale in ("fr","en","nl","de"):
    request("/login?lang="+locale,200)
for path in ("/robots.txt","/sitemap.xml"):
    request(path,200)
repo=Path(__file__).resolve().parents[2]
asset=next((repo/"app/public/assets").rglob("*.css"))
request("/"+str(asset.relative_to(repo/"app/public")),200)
headers,_=request("/api.php",308,body=b"untouched=payload")
assert headers["Location"]=="/api",headers
request("/api",401,body=b"{}",headers={"Content-Type":"application/json","Authorization":"Bearer invalid"})
headers,_=request("/auth/login?provider=github",302,headers={"Host":"spoof.invalid"})
target=urlsplit(headers["Location"])
assert target.netloc=="github.com",headers
assert parse_qs(target.query)["redirect_uri"]==["https://energy-cost.eu/auth/login"],headers
cookies=headers.get_all("Set-Cookie") or []
assert any("secure" in cookie.lower() and "httponly" in cookie.lower() for cookie in cookies),cookies
secret=os.environ["EC_TEST_STRIPE_SECRET"].encode()
now=int(time.time())
body=json.dumps({"id":"evt_container_smoke","created":now,"type":"ignored.test.event","livemode":False,
                 "data":{"object":{}}},indent=2).encode()
signature=hmac.new(secret,str(now).encode()+b"."+body,hashlib.sha256).hexdigest()
request("/stripe/webhook",200,body=body,headers={"Stripe-Signature":f"t={now},v1={signature}","Content-Type":"application/json"})
request("/stripe/webhook",400,body=body+b" ",headers={"Stripe-Signature":f"t={now},v1={signature}","Content-Type":"application/json"})
request("/stripe/webhook",413,body=b"x"*262145,headers={"Content-Type":"application/json"})
print(f"{count} HTTP checks passed")
