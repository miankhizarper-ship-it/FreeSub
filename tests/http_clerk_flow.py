#!/usr/bin/env python3
"""End-to-end Clerk authentication checks against a running test server.

The server must be started with tests/router-e2e.php (see tests/http-clerk.sh),
which installs the Clerk test double. No network access is used and no real
Clerk secret is involved.

Usage: tests/http_clerk_flow.py http://127.0.0.1:8099
"""

import base64
import hashlib
import hmac
import json
import os
import re
import sqlite3
import subprocess
import sys
import time

import requests

BASE = sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:8099"
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = os.path.join(ROOT, "storage", "dev.sqlite")
SECRET = os.environ.get("CLERK_WEBHOOK_SECRET", "")

failures = []


def check(name, condition, extra=""):
    print(("  ok   " if condition else "  FAIL ") + name + ("" if condition else f"  [{extra}]"))
    if not condition:
        failures.append(name)


def token(sub, ttl=60):
    out = subprocess.run(
        [os.environ.get("PHP_BIN", "php"), os.path.join(ROOT, "tests", "mint-token.php"), sub, str(ttl)],
        capture_output=True, text=True, check=True,
    )
    return out.stdout.strip()


def db():
    return sqlite3.connect(DB, timeout=20)


def config(response):
    """Extract the embedded Clerk configuration from a rendered page."""
    text = response.text if hasattr(response, "text") else str(response)
    match = re.search(r'<script type="application/json" data-clerk-config>(.*?)</script>', text, re.S)
    return json.loads(match.group(1)) if match else {}


def csrf(response):
    """Extract the CSRF token from any form on the page."""
    text = response.text if hasattr(response, "text") else str(response)
    match = re.search(r'name="_token" value="([^"]+)"', text)
    return match.group(1) if match else ""


def sign(body, msg_id, timestamp, secret=None):
    key = base64.b64decode((secret or SECRET)[6:])
    digest = hmac.new(key, f"{msg_id}.{timestamp}.{body}".encode(), hashlib.sha256).digest()
    return "v1," + base64.b64encode(digest).decode()


def deliver(event, msg_id=None, timestamp=None, secret=None):
    body = json.dumps(event)
    msg_id = msg_id or f"msg_{int(time.time() * 1000)}_{os.getpid()}"
    timestamp = timestamp or str(int(time.time()))
    return requests.post(
        f"{BASE}/clerk/webhook.php",
        data=body,
        headers={
            "Content-Type": "application/json",
            "svix-id": msg_id,
            "svix-timestamp": timestamp,
            "svix-signature": sign(body, msg_id, timestamp, secret),
        },
    )


print("Login page")
session = requests.Session()
page = session.get(f"{BASE}/login.php")
check("login page renders", page.status_code == 200, page.status_code)
check("no password field remains", 'type="password"' not in page.text)
check("Clerk sign-in mount present", "data-clerk-sign-in" in page.text)
check("local JS assets referenced", "clerk-auth.js" in page.text)
check("no secret key in the HTML", "sk_test" not in page.text)

cfg = config(page)
check("config carries the publishable key", cfg.get("publishableKey", "").startswith("pk_test_"))
check("config carries a channel", len(cfg.get("channel", "")) >= 32)
check("config never carries the secret", "sk_test" not in json.dumps(cfg))
check("bridge URL is same-origin", cfg.get("bridgeUrl", "").startswith("http")
      and cfg.get("bridgeUrl", "").rstrip("/").endswith("/clerk-bridge.php"), cfg.get("bridgeUrl"))
form_token = csrf(page)
check("CSRF token present in the handoff form", len(form_token) >= 32)

# Every key clerk-auth.js reads must actually be emitted, otherwise the page
# silently degrades to "not configured".
JS_PATH = os.path.join(ROOT, "assets", "js", "clerk-auth.js")
js_source = open(JS_PATH, encoding="utf-8").read()
required_keys = sorted(set(re.findall(r"config\.([A-Za-z][A-Za-z0-9_]*)", js_source)))
missing = [key for key in required_keys if not cfg.get(key)]

check(f"every config key the JS reads is emitted ({', '.join(required_keys)})",
      not missing, f"missing: {missing}")

check("frontend API host is published for the SDK loader",
      cfg.get("frontendApi") == "freesub-test.clerk.accounts.dev", cfg.get("frontendApi"))
check("handoff template name is published",
      cfg.get("handoffTemplate") == "freesub_handoff", cfg.get("handoffTemplate"))

print("Registration page")
signup = requests.Session().get(f"{BASE}/register.php")
check("register page renders", signup.status_code == 200, signup.status_code)
check("register page has no password field", 'type="password"' not in signup.text)
check("register page mounts Clerk's sign-up component", "data-clerk-sign-up" in signup.text)
check("register page has its own channel",
      config(signup).get("mode") == "sign-up" and config(signup).get("channel") != cfg.get("channel"),
      config(signup).get("mode"))
check("register page never carries the secret", "sk_test" not in signup.text)
check("register page exposes the handoff form", 'name="handoff_token"' in signup.text)

print("Guests")
for path in ("/dashboard.php", "/admin/index.php"):
    response = session.get(f"{BASE}{path}", allow_redirects=False)
    check(f"guest {path} redirects to login",
          response.status_code in (302, 303) and "login" in response.headers.get("Location", ""),
          (response.status_code, response.headers.get("Location")))

print("Bridge hardening")
check("bridge rejects GET", session.get(f"{BASE}/clerk-bridge.php").status_code == 405)
foreign = session.post(
    f"{BASE}/clerk-bridge.php",
    json={"handoffToken": "x"},
    headers={"Origin": "https://evil.example", "Content-Type": "application/json"},
)
check("bridge rejects a foreign origin", foreign.status_code == 403, foreign.status_code)
check("bridge never echoes a secret", "sk_test" not in foreign.text)

expired = session.post(
    f"{BASE}/clerk-bridge.php",
    json={"handoffToken": token("user_2handoff", -120), "channel": cfg["channel"], "state": cfg["state"]},
    headers={"Origin": BASE, "Content-Type": "application/json"},
)
check("expired token refused", expired.status_code == 401, expired.status_code)

wrong_channel = session.post(
    f"{BASE}/clerk-bridge.php",
    json={"handoffToken": token("user_2handoff"), "channel": "0" * 48, "state": cfg["state"]},
    headers={"Origin": BASE, "Content-Type": "application/json"},
)
check("wrong channel refused", wrong_channel.status_code == 403, wrong_channel.status_code)

print("Registration through the handoff")
good = session.post(
    f"{BASE}/clerk-bridge.php",
    json={"handoffToken": token("user_2handoff"), "channel": cfg["channel"], "state": cfg["state"]},
    headers={"Origin": BASE, "Content-Type": "application/json"},
)
check("valid token accepted by the bridge", good.status_code == 200, (good.status_code, good.text[:160]))
body = good.json() if good.status_code == 200 else {}
check("bridge returns only ok/token/redirect", set(body) <= {"ok", "token", "redirect"}, list(body))
ticket = body.get("token", "")

no_csrf = session.post(f"{BASE}/auth/handoff.php", data={"handoff_token": ticket}, allow_redirects=False)
check("handoff requires CSRF", no_csrf.status_code in (400, 403, 419), no_csrf.status_code)

handoff = session.post(
    f"{BASE}/auth/handoff.php",
    data={"handoff_token": ticket, "_token": form_token, "welcome": "1"},
    allow_redirects=False,
)
check("handoff form accepts the ticket", handoff.status_code in (302, 303), handoff.status_code)
check("handoff redirects to the dashboard", "dashboard" in handoff.headers.get("Location", ""),
      handoff.headers.get("Location"))

dashboard = session.get(f"{BASE}/dashboard.php")
check("dashboard accessible after sign-in", dashboard.status_code == 200, dashboard.status_code)
check("dashboard shows the signed-in account", "handoff@clerk-test.local" in dashboard.text or "Alan" in dashboard.text)
check("dashboard contains no secret", "sk_test" not in dashboard.text)

replay = requests.Session()
replay.get(f"{BASE}/login.php")
replay.post(f"{BASE}/auth/handoff.php", data={"handoff_token": ticket, "_token": form_token}, allow_redirects=False)
check("ticket cannot be replayed",
      replay.get(f"{BASE}/dashboard.php", allow_redirects=False).status_code in (302, 303))

print("Authorization")
check("normal user is denied the admin area",
      session.get(f"{BASE}/admin/index.php", allow_redirects=False).status_code in (302, 303, 403))

print("Webhooks")
check("webhook rejects GET", requests.get(f"{BASE}/clerk/webhook.php").status_code == 405)
check("unsigned webhook rejected",
      requests.post(f"{BASE}/clerk/webhook.php", data="{}",
                    headers={"Content-Type": "application/json"}).status_code == 401)
check("bad signature rejected",
      deliver({"type": "user.updated", "data": {"id": "user_2handoff"}},
              secret="whsec_" + base64.b64encode(b"z" * 24).decode()).status_code == 401)
stale = str(int(time.time()) - 900)
check("stale timestamp rejected", deliver({"type": "user.created", "data": {"id": "user_2x"}},
                                          timestamp=stale).status_code == 401)
check("unknown event acknowledged",
      deliver({"type": "organization.created", "data": {"id": "org_1"}}).json().get("handled") is False)

deleted = deliver({"type": "user.deleted", "data": {"id": "user_2handoff"}}, msg_id="msg_e2e_delete_1")
check("user.deleted accepted", deleted.status_code == 200, (deleted.status_code, deleted.text[:120]))
check("deletion reports handled", deleted.json().get("handled") is True)
retry = deliver({"type": "user.deleted", "data": {"id": "user_2handoff"}}, msg_id="msg_e2e_delete_1")
check("retried delivery is idempotent", retry.status_code == 200 and retry.json().get("handled") is False)

connection = db()
row = connection.execute(
    "SELECT id, role, clerk_user_id FROM users WHERE email = ?", ("handoff@clerk-test.local",)
).fetchone()
connection.close()
check("account survived the deletion", row is not None)
check("identity unlinked", row is not None and row[2] is None, row)

print("Existing administrators")
# A pre-migration administrator: the row exists with the admin role and no Clerk
# identity. The handoff must attach the identity, never recreate the account.
connection = db()
connection.execute(
    "UPDATE users SET role = 'admin', status = 'active', clerk_user_id = NULL WHERE email = ?",
    ("legacy-admin@clerk-test.local",),
)
connection.commit()
connection.close()

admin = requests.Session()
page = admin.get(f"{BASE}/login.php")
cfg = config(page)
form_token = csrf(page)
bridge = admin.post(
    f"{BASE}/clerk-bridge.php",
    json={"handoffToken": token("user_2legacy"), "channel": cfg["channel"], "state": cfg["state"]},
    headers={"Origin": BASE, "Content-Type": "application/json"},
)
check("administrator can complete the handoff", bridge.status_code == 200, bridge.status_code)
admin.post(f"{BASE}/auth/handoff.php",
           data={"handoff_token": bridge.json().get("token", ""), "_token": form_token}, allow_redirects=False)
check("administrator reaches the admin area", admin.get(f"{BASE}/admin/index.php").status_code == 200)

connection = db()
role = connection.execute("SELECT role, clerk_user_id FROM users WHERE email = ?",
                          ("legacy-admin@clerk-test.local",)).fetchone()
connection.close()
check("migrated administrator keeps the admin role", role is not None and role[0] == "admin", role)
check("administrator identity was attached, not duplicated", role is not None and role[1] == "user_2legacy", role)

print("Logout")
# Use the administrator session: it is the only one still valid at this point,
# because the webhook above deliberately detached the first account.
get_logout = admin.get(f"{BASE}/logout.php", allow_redirects=False)
check("logout never signs out on GET", get_logout.status_code in (302, 303, 405), get_logout.status_code)
check("GET logout does not reach the signed-out page",
      "signed_out" not in get_logout.headers.get("Location", ""), get_logout.headers.get("Location"))
check("session survives a GET to logout", admin.get(f"{BASE}/admin/index.php").status_code == 200)

no_token = admin.post(f"{BASE}/logout.php", data={}, allow_redirects=False)
check("logout without CSRF refused", no_token.status_code == 419, no_token.status_code)

logout_token = csrf(admin.get(f"{BASE}/admin/index.php"))
check("logout form exposes a CSRF token", len(logout_token) >= 32, logout_token)
signed_out = admin.post(f"{BASE}/logout.php", data={"_token": logout_token}, allow_redirects=False)
check("logout succeeds", signed_out.status_code in (302, 303)
      and "signed_out" in signed_out.headers.get("Location", ""),
      (signed_out.status_code, signed_out.headers.get("Location")))
check("dashboard locked after logout",
      admin.get(f"{BASE}/dashboard.php", allow_redirects=False).status_code in (302, 303))
check("admin area locked after logout",
      admin.get(f"{BASE}/admin/index.php", allow_redirects=False).status_code in (302, 303))

print()
if failures:
    print(f"{len(failures)} check(s) failed: {failures}")
    sys.exit(1)

print("All end-to-end checks passed.")
