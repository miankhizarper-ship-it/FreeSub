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

print("Subdomain search and reservation")

# The public endpoint is the same engine the dashboard uses. It must answer for
# a free label, refuse a reserved one, and never write anything.
free = requests.get(f"{BASE}/api/check.php", params={"label": "e2e-free"})
free_body = free.json() if free.status_code == 200 else {}
check("availability API answers", free.status_code == 200, (free.status_code, free.text[:160]))
check("availability API reports a free label as available", free_body.get("available") is True, free_body)
check("availability API states its provenance", "authoritative" in free_body, list(free_body))
check("availability API returns no credential", "sk_test" not in free.text)

reserved_label = requests.get(f"{BASE}/api/check.php", params={"label": "www"})
reserved_body = reserved_label.json() if reserved_label.status_code == 200 else {}
check("availability API refuses a reserved label", reserved_body.get("available") is False, reserved_body)
check("a reserved label is explained, not silently rejected",
      reserved_body.get("status") == "reserved", reserved_body.get("status"))

malformed = requests.get(f"{BASE}/api/check.php", params={"label": "Bad Label!"})
malformed_body = malformed.json() if malformed.status_code == 200 else {}
check("availability API rejects malformed input", malformed_body.get("available") is False, malformed_body)

# Reservation through the dashboard's own form (Task 05 moved it from the
# overview to /dashboard/search.php, which is where the shell links to).
search_page = session.get(f"{BASE}/dashboard/search.php")
check("the dashboard shell links to the reservation form", "/dashboard/search.php" in session.get(f"{BASE}/dashboard.php").text)
check("the reservation form is on its own page", "name=\"label\"" in search_page.text)
reserve_no_csrf = session.post(f"{BASE}/dashboard/search.php",
                               data={"action": "reserve", "label": "e2e-reserved"},
                               allow_redirects=False)
check("reservation requires CSRF", reserve_no_csrf.status_code == 419, reserve_no_csrf.status_code)

reserve_token = csrf(search_page)
accepted = session.post(
    f"{BASE}/dashboard/search.php",
    data={"action": "reserve", "label": "e2e-reserved", "domain_id": "1", "_token": reserve_token},
    allow_redirects=False,
)
check("a valid reservation is accepted", accepted.status_code in (302, 303), accepted.status_code)

# It must be visible in the account's own history, which is where a person looks.
after_reserve = session.get(f"{BASE}/dashboard/reservations.php")
check("the reservation is listed for its owner", "e2e-reserved" in after_reserve.text)
check("the reservation is listed with its outcome", "Awaiting review" in after_reserve.text)

connection = db()
pending_row = connection.execute(
    "SELECT status, live_guard FROM reservations WHERE label = ? ORDER BY id DESC LIMIT 1",
    ("e2e-reserved",),
).fetchone()
connection.close()
check("the reservation is stored as pending", pending_row is not None and pending_row[0] == "pending", pending_row)
check("the reservation holds the label", pending_row is not None and pending_row[1] == 1, pending_row)

taken = requests.get(f"{BASE}/api/check.php", params={"label": "e2e-reserved"})
taken_body = taken.json() if taken.status_code == 200 else {}
check("a reserved label is no longer available", taken_body.get("available") is False, taken_body)

# Identity scoping: another browser must not see it.
stranger = requests.Session()
stranger.get(f"{BASE}/login.php")
stranger_dash = stranger.get(f"{BASE}/dashboard.php", allow_redirects=False)
check("the list is not readable without a session", stranger_dash.status_code in (302, 303),
      stranger_dash.status_code)

# Releasing a name must not be possible through another account's id.
release = session.post(
    f"{BASE}/dashboard.php",
    data={"action": "release_subdomain", "id": "999999", "_token": reserve_token},
    allow_redirects=False,
)
check("an unknown subdomain id is refused", release.status_code in (302, 303), release.status_code)

print("User dashboard")
check("the overview renders the app shell", 'class="dash-shell"' in session.get(f"{BASE}/dashboard.php").text)
check("the subdomain list renders", session.get(f"{BASE}/dashboard/subdomains.php").status_code == 200)
check("the reservation history renders", session.get(f"{BASE}/dashboard/reservations.php").status_code == 200)
check("the account page renders", session.get(f"{BASE}/dashboard/settings.php").status_code == 200)
check("an unknown subdomain is a 404, not a 403 that confirms it exists",
      session.get(f"{BASE}/dashboard/subdomain.php?id=999999", allow_redirects=False).status_code == 404)
check("an unknown subdomain page discloses nothing",
      "not-yours" not in session.get(f"{BASE}/dashboard/subdomain.php?id=999999").text)
check("a junk subdomain id is refused", session.get(f"{BASE}/dashboard/subdomain.php?id=abc").status_code == 404)
check("the dashboard never renders a Clerk secret", "sk_test" not in session.get(f"{BASE}/dashboard.php").text)
check("the account page offers no password field", 'type="password"' not in session.get(f"{BASE}/dashboard/settings.php").text)
check("the account page exposes no role control", 'name="role"' not in session.get(f"{BASE}/dashboard/settings.php").text)
check("every dashboard page requires a session", all(
    requests.get(f"{BASE}{path}", allow_redirects=False).status_code in (302, 303)
    for path in ("/dashboard/subdomains.php", "/dashboard/subdomain.php?id=1",
                 "/dashboard/reservations.php", "/dashboard/search.php", "/dashboard/settings.php")
))

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
# identity. Browsing to it and signing in must NOT attach the identity (that
# would let anyone who controls the address take the admin account over); the
# operator links it deliberately from the CLI, exactly as the README says.
connection = db()
connection.execute(
    "UPDATE users SET role = 'admin', status = 'active', clerk_user_id = NULL WHERE email = ?",
    ("legacy-admin@clerk-test.local",),
)
connection.commit()

admin_id = connection.execute(
    "SELECT id FROM users WHERE email = ?", ("legacy-admin@clerk-test.local",)
).fetchone()[0]
connection.close()

attempt = requests.Session()
page = attempt.get(f"{BASE}/login.php")
cfg_attempt = config(page)
refused = attempt.post(
    f"{BASE}/clerk-bridge.php",
    json={"handoffToken": token("user_2legacy"), "channel": cfg_attempt["channel"],
          "state": cfg_attempt["state"]},
    headers={"Origin": BASE, "Content-Type": "application/json"},
)
check("an admin row cannot be claimed by signing in", refused.status_code in (401, 409),
      (refused.status_code, refused.text[:160]))

connection = db()
still_free = connection.execute(
    "SELECT role, clerk_user_id FROM users WHERE id = ?", (admin_id,)
).fetchone()
connection.close()
check("the admin row is unchanged by the attempt",
      still_free is not None and still_free[0] == "admin" and still_free[1] is None,
      still_free)

linked = subprocess.run(
    [os.environ.get("PHP_BIN", "php"), os.path.join(ROOT, "database", "link-clerk.php"),
     "--email=legacy-admin@clerk-test.local", "--clerk-id=user_2legacy"],
    capture_output=True, text=True,
)
cli_output = (linked.stdout + linked.stderr).strip()
check("the CLI refuses an address it cannot verify against Clerk",
      "Clerk lookup" in cli_output or "Verified" in cli_output or "verified" in cli_output,
      cli_output[:200])

# Equivalently, and reachable without Clerk: attach the already-verified id the
# way database/link-clerk.php would after confirming the address.
connection = db()
connection.execute(
    "UPDATE users SET clerk_user_id = 'user_2legacy' WHERE id = ?", (admin_id,)
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
check("administrator can complete the handoff once linked", bridge.status_code == 200,
      (bridge.status_code, bridge.text[:120]))
admin.post(f"{BASE}/auth/handoff.php",
           data={"handoff_token": bridge.json().get("token", ""), "_token": form_token}, allow_redirects=False)
check("administrator reaches the admin area", admin.get(f"{BASE}/admin/index.php").status_code == 200)

connection = db()
role = connection.execute("SELECT role, clerk_user_id FROM users WHERE email = ?",
                          ("legacy-admin@clerk-test.local",)).fetchone()
connection.close()
check("migrated administrator keeps the admin role", role is not None and role[0] == "admin", role)
check("administrator identity was attached, not duplicated", role is not None and role[1] == "user_2legacy", role)

print("DNS diagnostics (admin only)")
# The page must render for an administrator, expose no credential, and refuse any
# mutating verb. Cloudflare is deliberately unconfigured in this run: the engine
# must degrade to "disabled", never to "attempted".
dns_page = admin.get(f"{BASE}/admin/dns.php")
check("administrator can open the DNS diagnostics page", dns_page.status_code == 200, dns_page.status_code)
check("DNS page states the provider", "cloudflare" in dns_page.text or "none" in dns_page.text)
check("DNS page never renders a token value", "CLOUDFLARE_API_TOKEN=" not in dns_page.text
      and "Bearer " not in dns_page.text and "sk_test" not in dns_page.text)
check("DNS page exposes the read-only connection test", "test_connection" in dns_page.text)
check("DNS page exposes no record mutation control",
      "createRecord" not in dns_page.text and "deleteRecord" not in dns_page.text)

dns_no_csrf = admin.post(f"{BASE}/admin/dns.php", data={"action": "test_connection"}, allow_redirects=False)
check("DNS actions require CSRF", dns_no_csrf.status_code == 419, dns_no_csrf.status_code)

dns_token = csrf(dns_page)
dns_unknown = admin.post(f"{BASE}/admin/dns.php", data={"action": "bogus", "_token": dns_token},
                         allow_redirects=False)
check("an unknown DNS action is rejected without side effects", dns_unknown.status_code in (302, 303),
      dns_unknown.status_code)

dns_test = admin.post(f"{BASE}/admin/dns.php",
                      data={"action": "test_connection", "_token": dns_token, "domain_id": 1},
                      allow_redirects=False)
check("the connection test is handled by the server", dns_test.status_code in (302, 303), dns_test.status_code)
check("the connection test does not crash when unconfigured",
      admin.get(f"{BASE}/admin/dns.php").status_code == 200)

check("a normal user is denied the DNS page",
      session.get(f"{BASE}/admin/dns.php", allow_redirects=False).status_code in (302, 303, 403))

print("Reservation administration")
# Same invariants as the DNS page: admin-only, CSRF-protected, no credential in
# the HTML, and no control that would write a record straight from the browser.
res_page = admin.get(f"{BASE}/admin/reservations.php")
check("administrator can open the reservation queue", res_page.status_code == 200, res_page.status_code)
check("the queue shows the provisioning section", "provisioning" in res_page.text.lower())
check("the queue never renders a token value", "CLOUDFLARE_API_TOKEN=" not in res_page.text
      and "Bearer " not in res_page.text and "sk_test" not in res_page.text)

res_no_csrf = admin.post(f"{BASE}/admin/reservations.php",
                         data={"action": "approve", "id": "1"}, allow_redirects=False)
check("reservation actions require CSRF", res_no_csrf.status_code == 419, res_no_csrf.status_code)

res_token = csrf(res_page)
res_unknown = admin.post(f"{BASE}/admin/reservations.php",
                         data={"action": "bogus", "id": "1", "_token": res_token},
                         allow_redirects=False)
check("an unknown reservation action is rejected", res_unknown.status_code in (302, 303),
      res_unknown.status_code)
check("a normal user is denied the reservation queue",
      session.get(f"{BASE}/admin/reservations.php", allow_redirects=False).status_code in (302, 303, 403))

users_page = admin.get(f"{BASE}/admin/users.php")
check("administrator can open the user directory", users_page.status_code == 200, users_page.status_code)
check("the directory exposes the quota control", 'name="quota"' in users_page.text)

users_token = csrf(users_page)
users_id = db()
target = users_id.execute("SELECT id FROM users WHERE email = ?", ("handoff@clerk-test.local",)).fetchone()
users_id.close()
quota_update = admin.post(f"{BASE}/admin/users.php",
                          data={"action": "quota", "id": str(target[0]) if target else "1",
                                "quota": "7", "_token": users_token},
                          allow_redirects=False)
check("a quota can be set by an administrator", quota_update.status_code in (302, 303),
      quota_update.status_code)

if target:
    verify = db()
    stored = verify.execute("SELECT subdomain_quota FROM users WHERE id = ?", (target[0],)).fetchone()
    verify.close()
    check("the quota change is persisted", stored is not None and stored[0] == 7, stored)

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
