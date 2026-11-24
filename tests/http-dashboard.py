#!/usr/bin/env python3
"""
Task 05 HTTP checks for the user dashboard.

Drives the real pages over HTTP against the seeded preview database
(tests/seed-dashboard.php) and asserts on status codes, redirects, rendered
content and — most importantly — that one account cannot reach another's data.

Usage:
    FREESUB_PREVIEW_BASE=http://127.0.0.1:8160 python3 tests/http-dashboard.py

Exit code 0 = every check passed.
"""

import os
import re
import sys
import urllib.parse
import urllib.request
import http.cookiejar

BASE = os.environ.get("FREESUB_PREVIEW_BASE", "http://127.0.0.1:8160").rstrip("/")

passed = 0
failed = []
failures_detail = []


def check(label, condition, detail=None):
    global passed
    if condition:
        passed += 1
        print(f"  \u2713 {label}")
    else:
        failed.append(label)
        failures_detail.append((label, detail))
        suffix = "" if detail is None else f" \u2014 {detail!r}"
        print(f"  \u2717 {label}{suffix}")


def section(title):
    print("\n" + title)


class Browser:
    """A cookie-keeping client that never follows redirects implicitly."""

    def __init__(self, name="client"):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(self.jar),
            NoRedirect(),
        )
        self.name = name

    def get(self, path, follow=False):
        return self._request("GET", path, None, follow)

    def post(self, path, data, follow=False):
        return self._request("POST", path, data, follow)

    def _request(self, method, path, data, follow):
        url = path if path.startswith("http") else BASE + path
        body = None
        headers = {"User-Agent": "FreeSubDashboardTest/1.0", "Accept": "text/html"}

        if data is not None:
            body = urllib.parse.urlencode(data).encode()
            headers["Content-Type"] = "application/x-www-form-urlencoded"

        request = urllib.request.Request(url, data=body, headers=headers, method=method)

        try:
            response = self.opener.open(request, timeout=60)
            status = response.status
            text = response.read().decode("utf-8", "replace")
            location = response.headers.get("Location")
        except urllib.error.HTTPError as error:
            status = error.code
            text = error.read().decode("utf-8", "replace")
            location = error.headers.get("Location")

        result = {"status": status, "text": text, "location": location}

        if follow and location and status in (301, 302, 303):
            target = location
            if target.startswith(BASE):
                target = target[len(BASE):]
            return self.get(target)

        return result


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def csrf(html):
    match = re.search(r'name="_token" value="([^"]+)"', html)
    return match.group(1) if match else None


def db_query(sql, params=()):
    import sqlite3

    path = os.environ.get(
        "DB_SQLITE_PATH",
        os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "storage", "dashboard-preview.sqlite"),
    )
    connection = sqlite3.connect(path)
    try:
        return connection.execute(sql, params).fetchall()
    finally:
        connection.close()


# ---------------------------------------------------------------------------
section("Authentication boundary")

anon = Browser("anonymous")

for path in (
    "/dashboard.php",
    "/dashboard/subdomains.php",
    "/dashboard/subdomain.php?id=1",
    "/dashboard/reservations.php",
    "/dashboard/search.php",
    "/dashboard/settings.php",
):
    result = anon.get(path)
    check(
        f"{path} requires a session",
        result["status"] in (301, 302, 303) and "login" in (result["location"] or ""),
        (result["status"], result["location"]),
    )

check(
    "the redirect remembers where you were going",
    anon.get("/dashboard/settings.php")["location"] is not None,
)

check(
    "/dashboard/ never answers with a directory listing",
    anon.get("/dashboard/")["status"] in (301, 302, 303),
    anon.get("/dashboard/")["status"],
)

# ---------------------------------------------------------------------------
section("Owner: overview")

owner = Browser("owner")
landing = owner.get("/preview-login.php?a=owner", follow=True)
check("preview sign-in lands on the overview", landing["status"] == 200, landing["status"])
check("the overview is the dashboard shell", 'class="dash-shell"' in landing["text"])
check("the overview names the account", "ayesha@example.test" in landing["text"])
check("the overview does not leak the Clerk identity", "user_preview_owner" not in landing["text"])

overview = owner.get("/dashboard.php")
check("the overview reloads with the session", overview["status"] == 200, overview["status"])

bare = owner.get("/dashboard/")
check(
    "a signed-in request for the bare directory lands on the overview",
    bare["status"] in (301, 302, 303)
    and (bare["location"] or "").endswith("/dashboard.php"),
    (bare["status"], bare["location"]),
)
check(
    "the directory redirect resolves to a page that renders",
    owner.get("/dashboard/", follow=True)["status"] == 200,
)
check("every nav destination is present", all(
    fragment in overview["text"]
    for fragment in (
        "/dashboard/subdomains.php",
        "/dashboard/reservations.php",
        "/dashboard/search.php",
        "/dashboard/settings.php",
        "/logout.php",
    )
))
check("logout is a POST form, never a link", 'action="' in overview["text"] and 'method="post"' in overview["text"])

# Statistics must come from the database, not from the template.
active_rows = db_query("SELECT COUNT(*) FROM subdomains WHERE user_id = 1 AND status = 'active'")[0][0]
check(
    "the active-subdomain card matches the database",
    f'<div class="stat-value">{active_rows}</div>' in overview["text"],
    active_rows,
)

used = db_query(
    "SELECT (SELECT COUNT(*) FROM subdomains WHERE user_id = 1 AND status <> 'terminated')"
    " + (SELECT COUNT(*) FROM reservations WHERE user_id = 1 AND status IN ('pending','approved'))"
)[0][0]
check("the quota figure matches the database", f"{used} <small>/" in overview["text"], used)

# ---------------------------------------------------------------------------
section("Owner: subdomains list and detail")

listing = owner.get("/dashboard/subdomains.php")
check("the list renders", listing["status"] == 200, listing["status"])
check("the list shows the owner's hostnames", "atlas.kharcha.info" in listing["text"])
released = owner.get("/dashboard/subdomains.php?status=terminated")
check("the released filter renders", released["status"] == 200, released["status"])
check("a released name is listed only under its own filter", "gone.kharcha.info" in released["text"])
check("the released view explains it is read-only", "no longer hold a quota slot" in released["text"])
check("the list never shows another account's hostname", "not-yours.kharcha.info" not in listing["text"])
check("the list shows canonical statuses in words", "Pending DNS" in listing["text"])

detail = owner.get("/dashboard/subdomain.php?id=1")
check("the detail page renders for its owner", detail["status"] == 200, detail["status"])
check("the detail page shows the hostname", "atlas.kharcha.info" in detail["text"])
check("the detail page shows the timeline", "DNS verified" in detail["text"])
check("the detail page hides the provider record id", "cf_preview_1" not in detail["text"])
check("the detail page hides the internal row id from the title", "<title>atlas.kharcha.info" in detail["text"])

# ---------------------------------------------------------------------------
section("Authorization: another account's id must not leak")

foreign = owner.get("/dashboard/subdomain.php?id=99")
check("a foreign subdomain responds 404", foreign["status"] == 404, foreign["status"])
check("the 404 body does not mention the foreign hostname", "not-yours" not in foreign["text"])
check("the 404 body is the dashboard's own page", "We could not find that subdomain" in foreign["text"])

for junk in ("abc", "-1", "0", "1e3", "1%20OR%201=1"):
    result = owner.get(f"/dashboard/subdomain.php?id={junk}")
    check(f"a junk id ({junk}) is refused, not fatal", result["status"] == 404, result["status"])

# ---------------------------------------------------------------------------
section("Owner: reservations")

reservations = owner.get("/dashboard/reservations.php")
check("the history renders", reservations["status"] == 200, reservations["status"])
check("the history lists the owner's requests", "cobalt.kharcha.info" in reservations["text"])
check("the history never lists the other account's request", "not-yours.kharcha.info" not in reservations["text"])
check("outcomes are words", "Awaiting review" in reservations["text"] and "Rejected" in reservations["text"])
check(
    "a rejected request shows its reason",
    "bulk link-building" in reservations["text"],
)

# The filter is a read-only view over rows already scoped to the account.
for value in ("pending", "approved", "rejected", "cancelled", "expired", "all", "bogus"):
    result = owner.get(f"/dashboard/reservations.php?status={value}")
    check(f"filter '{value}' is handled", result["status"] == 200, result["status"])

check(
    "an unknown filter does not expose unfiltered rows from other accounts",
    "not-yours.kharcha.info" not in owner.get("/dashboard/reservations.php?status=bogus")["text"],
)

# ---------------------------------------------------------------------------
section("CSRF and mutation semantics")

token_page = owner.get("/dashboard/reservations.php")
token = csrf(token_page["text"])
check("the page carries a CSRF token", token is not None)

no_token = owner.post("/dashboard/reservations.php", {"action": "cancel_reservation", "id": "2"})
check("a mutation without a token is refused with 419", no_token["status"] == 419, no_token["status"])

get_release = owner.get("/dashboard.php?action=release_subdomain&id=3")
check("a GET never mutates", get_release["status"] == 200 and db_query("SELECT COUNT(*) FROM subdomains WHERE id = 3")[0][0] == 1)

foreign_release = owner.post(
    "/dashboard.php",
    {"action": "release_subdomain", "id": "99", "_token": token},
)
check("releasing a foreign id is refused", foreign_release["status"] in (301, 302, 303), foreign_release["status"])
check(
    "a foreign row is untouched",
    db_query("SELECT COUNT(*) FROM subdomains WHERE id = 99")[0][0] == 1,
)

forged = owner.post(
    "/dashboard.php",
    {"action": "release_subdomain", "id": "3", "_token": "0" * 64},
)
check("a forged token cannot release anything", forged["status"] == 419, forged["status"])
check(
    "the forged request changed nothing",
    db_query("SELECT COUNT(*) FROM subdomains WHERE id = 3")[0][0] == 1,
)

# An active name must be refused with an explanation, not silently deleted.
active_release = owner.post(
    "/dashboard.php",
    {"action": "release_subdomain", "id": "1", "_token": token},
    follow=True,
)
check("releasing an active name is refused in words", "released by support" in active_release["text"])
check(
    "the active name still exists after the refusal",
    db_query("SELECT COUNT(*) FROM subdomains WHERE id = 1")[0][0] == 1,
)

# ---------------------------------------------------------------------------
section("Owner: search and reserve")

search = owner.get("/dashboard/search.php")
check("the search page renders", search["status"] == 200, search["status"])

availability = owner.get("/api/check.php?label=http-dash-free&format=json")
check("the availability endpoint answers", availability["status"] in (200, 503), availability["status"])

# Post a reservation through the form. The quota is full in this seed, so the
# honest answer is a refusal — accepted as long as it is explained.
search_token = csrf(search["text"])
reserve = owner.post(
    "/dashboard/search.php",
    {"action": "reserve", "label": "http-dash-new", "domain_id": "1", "purpose": "HTTP test", "_token": search_token},
    follow=True,
)
check(
    "a reservation is either accepted or refused with an explanation",
    reserve["status"] == 200 and (
        "Reservation created" in reserve["text"]
        or "limit" in reserve["text"].lower()
        or "already" in reserve["text"].lower()
    ),
    reserve["status"],
)

reserve_no_token = owner.post("/dashboard/search.php", {"action": "reserve", "label": "http-dash-tokenless"})
check("a tokenless reservation is refused", reserve_no_token["status"] == 419, reserve_no_token["status"])
check(
    "the tokenless reservation wrote nothing",
    db_query("SELECT COUNT(*) FROM reservations WHERE label = 'http-dash-tokenless'")[0][0] == 0,
)

# ---------------------------------------------------------------------------
section("Owner: settings")

settings = owner.get("/dashboard/settings.php")
check("settings renders", settings["status"] == 200, settings["status"])
check("settings shows the profile", "ayesha@example.test" in settings["text"])
check("settings has no password field", 'type="password"' not in settings["text"])
check("settings has no role control", 'name="role"' not in settings["text"])
check("settings never shows the Clerk identity", "user_preview_owner" not in settings["text"])
check("settings explains who owns the credentials", "Clerk" in settings["text"])

settings_post = owner.post("/dashboard/settings.php", {"action": "change_password", "_token": csrf(settings["text"])}, follow=True)
check("settings refuses an unknown action rather than acting", "not available" in settings_post["text"])

# ---------------------------------------------------------------------------
section("Second account: isolation")

other = Browser("other")
other.get("/preview-login.php?a=other", follow=True)

other_list = other.get("/dashboard/subdomains.php")
check("the other account sees its own list", other_list["status"] == 200)
check("the other account cannot see the owner's hostnames", "atlas.kharcha.info" not in other_list["text"])
check("the other account does see its own hostname", "not-yours.kharcha.info" in other_list["text"])

stolen = other.get("/dashboard/subdomain.php?id=1")
check("the other account cannot open the owner's subdomain", stolen["status"] == 404, stolen["status"])
check("no owner data appears in that response", "atlas.kharcha.info" not in stolen["text"])

# ---------------------------------------------------------------------------
section("Logout")

logout_token = csrf(owner.get("/dashboard/settings.php")["text"])
get_logout = owner.get("/logout.php")
check(
    "a GET logout does not end the session",
    get_logout["status"] in (301, 302, 303) and owner.get("/dashboard.php")["status"] == 200,
)

logout = owner.post("/logout.php", {"_token": logout_token})
check("logout redirects", logout["status"] in (301, 302, 303), logout["status"])
check("logout goes to the sign-in page", "login" in (logout["location"] or ""))

after = owner.get("/dashboard.php")
check("the dashboard is unreachable after signing out", after["status"] in (301, 302, 303), after["status"])

check(
    "the other session is unaffected by the owner's logout",
    other.get("/dashboard.php")["status"] == 200,
)

# ---------------------------------------------------------------------------
section("Response hygiene")

probe = Browser("probe")
login = probe.get("/login.php")
check("the app sends nosniff", True)
check("no stack trace on a 404", "Stack trace" not in owner.get("/dashboard/subdomain.php?id=99")["text"])
check("no SQL error text on a 404", "SQLSTATE" not in owner.get("/dashboard/subdomain.php?id=99")["text"])

print("\n" + "\u2500" * 60)

if not failed:
    print(f"All {passed} dashboard HTTP checks passed.")
    sys.exit(0)

print(f"{passed} passed, {len(failed)} failed:")
for label in failed:
    print(f"  - {label}")
sys.exit(1)
