"""Drives the API through the flows the examples document and writes the
responses to docs/api/examples. Called by generate-api-examples.sh."""

import base64
import hashlib
import hmac
import http.cookiejar
import json
import os
import plistlib
import struct
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

BASE = os.environ["BASE_URL"]
OUT = sys.argv[1]
ORIGIN = BASE + "/"

# Illustrative install states so clients can render every CTA in mock mode.
DEMO_STATES = {
    "focus-notes": ("get", None, None, None),
    "pixel-weather": ("preparing", None, 0.42, "01j9ex4mp1e000000000000001"),
    "tempo": ("ready_to_install", None, None, "01j9ex4mp1e000000000000002"),
    "habit-garden": ("update_available", None, None, None),
    "frame": ("delivered", None, None, "01j9ex4mp1e000000000000003"),
    "atlas": ("not_eligible", "DEVICE_NOT_ELIGIBLE", None, None),
    "orbit-mail": ("failed", "INCOMPATIBLE_DEVICE", None, "01j9ex4mp1e000000000000004"),
    "paper-scan": ("unavailable", "ARTIFACT_NOT_INSTALLABLE", None, None),
    "lingua": ("not_eligible", "UNAUTHENTICATED", None, None),
}
SIZES = {"focus-notes": 88080384, "pixel-weather": 48234496, "tempo": 134217728, "habit-garden": 75497472,
         "frame": 225443840, "atlas": 171966464, "orbit-mail": 102760448, "lingua": 196083712}


class Client:
    def __init__(self, browser=False):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.browser = browser
        self.token = None

    def xsrf(self):
        for cookie in self.jar:
            if cookie.name == "XSRF-TOKEN":
                return urllib.parse.unquote(cookie.value)
        return None

    def raw(self, method, path, data, content_type):
        """Request without JSON, e.g. the iOS enrollment callback. Returns (status, Location)."""
        class NoRedirect(urllib.request.HTTPRedirectHandler):
            def redirect_request(self, *args, **kwargs):
                return None
        opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect)
        request = urllib.request.Request(BASE + "/api/v1" + path, method=method, data=data)
        request.add_header("Content-Type", content_type)
        try:
            with opener.open(request) as response:
                return response.status, response.headers.get("Location")
        except urllib.error.HTTPError as error:
            return error.code, error.headers.get("Location")

    def download(self, path):
        request = urllib.request.Request(BASE + "/api/v1" + path)
        request.add_header("Referer", ORIGIN)
        with self.opener.open(request) as response:
            return response.read()

    def call(self, method, path, body=None, headers=None):
        if self.browser and method != "GET" and self.xsrf() is None:
            self.call("GET", "/sanctum/csrf-cookie")
        url = BASE + (path if path.startswith("/sanctum") else "/api/v1" + path)
        request = urllib.request.Request(url, method=method, data=None if body is None else json.dumps(body).encode())
        request.add_header("Accept", "application/json")
        if body is not None:
            request.add_header("Content-Type", "application/json")
        if self.browser:
            request.add_header("Referer", ORIGIN)
            if method != "GET" and self.xsrf():
                request.add_header("X-XSRF-TOKEN", self.xsrf())
        if self.token:
            request.add_header("Authorization", "Bearer " + self.token)
        for name, value in (headers or {}).items():
            request.add_header(name, value)
        try:
            with self.opener.open(request) as response:
                raw = response.read()
        except urllib.error.HTTPError as error:
            raw = error.read()
        return json.loads(raw) if raw else None


def totp(secret):
    key = base64.b32decode(secret)
    digest = hmac.new(key, struct.pack(">Q", int(time.time()) // 30), hashlib.sha1).digest()
    offset = digest[-1] & 0x0F
    return "%06d" % ((struct.unpack(">I", digest[offset:offset + 4])[0] & 0x7FFFFFFF) % 1000000)


def write(name, payload):
    payload["meta"]["request_id"] = "01j0example0request0id0000"
    with open(os.path.join(OUT, name), "w", encoding="utf-8") as handle:
        json.dump(payload, handle, ensure_ascii=False, indent=2)
        handle.write("\n")


def decorate(app):
    status, reason, progress, installation = DEMO_STATES[app["slug"]]
    app["install_state"] = {"status": status, "reason": reason, "progress": progress, "installation_id": installation}
    if app.get("latest_version"):
        app["latest_version"]["size_bytes"] = SIZES.get(app["slug"])
    return app


def scrub_tokens(payload):
    payload["data"]["access_token"] = "1|example-access-token-not-valid"
    payload["data"]["refresh_token"] = "example-refresh-token-not-valid"
    return payload


# Catalog (Phase 1)
guest = Client()
write("health.json", guest.call("GET", "/health"))
feed = guest.call("GET", "/storefront/feed")
for section in feed["data"]["sections"]:
    for app in section.get("apps", []):
        decorate(app)
write("storefront-feed.json", feed)
write("storefront-status-signed-out.json", guest.call("GET", "/storefront/status"))
apps = guest.call("GET", "/apps?per_page=50")
for app in apps["data"]:
    decorate(app)
write("apps-list.json", apps)
focus = next(app for app in apps["data"] if app["slug"] == "focus-notes")
write("app-detail.json", {**(detail := guest.call("GET", "/apps/" + focus["id"])), "data": decorate(detail["data"])})
write("app-versions.json", guest.call("GET", "/apps/" + focus["id"] + "/versions"))
write("error-not-found.json", guest.call("GET", "/apps/01j0000000000000000000000z"))
write("error-validation.json", guest.call("GET", "/apps?per_page=500"))

# Staff sign-in and activation codes (Phase 2)
admin = Client(browser=True)
step = admin.call("POST", "/admin/auth/login", {"email": "admin@storefront.test", "password": os.environ["ADMIN_PASSWORD"]})
admin_me = admin.call("POST", "/admin/auth/totp", {"code": totp(step["data"]["enrollment"]["secret"])})
write("admin-me.json", admin_me)
batch = admin.call("POST", "/admin/activation-codes", {"count": 3, "duration_days": 365, "note": "Пилотная партия"}, {"Idempotency-Key": "examples-batch-0001"})
assert batch.get("data"), batch

# Customer: browser registration, then the native app's tokens
portal = Client(browser=True)
portal.call("POST", "/auth/register", {"name": "Анна Смирнова", "email": "anna@example.com", "password": "example-password-1"})
app_client = Client()
write("error-invalid-credentials.json", app_client.call("POST", "/auth/tokens", {"email": "anna@example.com", "password": "wrong-password-1"}))
tokens = app_client.call("POST", "/auth/tokens", {"email": "anna@example.com", "password": "example-password-1", "device_name": "iPhone Анны"})
app_client.token = tokens["data"]["access_token"]
write("activation-redeem.json", app_client.call("POST", "/activation/redeem", {"code": batch["data"]["codes"][0]}))
write("auth-me.json", app_client.call("GET", "/auth/me"))
tokens["data"]["user"] = app_client.call("GET", "/auth/me")["data"]
write("auth-tokens.json", scrub_tokens(tokens))
write("storefront-status-device-required.json", app_client.call("GET", "/storefront/status"))

# Device enrollment and the native app claim (Phase 3)
profile = plistlib.loads(portal.download("/devices/enrollment-profile"))
challenge = profile["PayloadContent"]["Challenge"]
payload = subprocess.run(["php", os.path.join(os.environ["ROOT"], "scripts/device-payload.php"), challenge], check=True, capture_output=True).stdout
status, location = portal.raw("POST", "/devices/enrollment/callback?challenge=" + urllib.parse.quote(challenge), payload, "application/pkcs7-signature")
assert status == 301 and "enrolled=" in location, (status, location)
write("devices-me.json", app_client.call("GET", "/devices/me"))
write("storefront-status-ready.json", app_client.call("GET", "/storefront/status"))
claim = portal.call("POST", "/storefront/claims")
claimed = Client().call("POST", "/storefront/claims/redeem", {"code": claim["data"]["code"], "device_name": "iPhone"})
write("storefront-claim-redeem.json", scrub_tokens(claimed))
write("admin-devices.json", admin.call("GET", "/admin/devices"))

write("admin-users.json", admin.call("GET", "/admin/users"))
write("admin-activation-codes.json", admin.call("GET", "/admin/activation-codes"))
write("admin-audit-logs.json", admin.call("GET", "/admin/audit-logs?per_page=10"))

print("Wrote examples to", OUT)
