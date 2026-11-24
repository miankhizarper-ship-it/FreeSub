# Security audit — Clerk authentication migration (Task 02)

**Scope:** the replacement of FreeSub's native password authentication with Clerk,
covering `login.php`, `register.php`, `logout.php`, `clerk-bridge.php`,
`auth/handoff.php`, `clerk/webhook.php`, `includes/auth.php`,
`includes/models/User.php`, `includes/services/Clerk/*`, the schema changes and
the CLI tooling in `database/`.

**Date:** 2026-11-23 · **Revision:** working branch `arena/9d25821a-freesub`

**Status of the integration:** the flow is implemented and exercised end to end
against a test double (real RS256 signatures, stubbed Backend API). **It has not
been tested against the live Clerk instance**, because this environment cannot
reach `api.clerk.com`, the instance's frontend API, or obtain a secret key. Do not
describe Clerk authentication as working until the checklist in
[README → Verifying against Clerk](README.md#verifying-against-clerk) has been
run on a networked machine.

---

## 1. Summary

| Area | Result |
|------|--------|
| Credential handling | **Pass** — no web password ever reaches PHP; no Clerk credential is stored |
| Browser integration | **Pass** after one fix — every key the client script needs is emitted, asserted mechanically (§4 F11) |
| Token verification | **Pass** — signature, `alg`, `kid`, `iss`, `exp`, `nbf`, `azp`, `sub`, with `iss` checked (the SDK does not) |
| Session management | **Pass** after one fix — the client fingerprint is now re-checked on every resolution (§4 F2) |
| Authorization / roles | **Pass** — server-side only, never from Clerk or the client |
| CSRF / same-origin | **Pass** |
| Secret hygiene | **Pass** — no secret in HTML, logs, URLs, database or responses |
| Webhook trust | **Pass** — svix HMAC verified before parsing, replay window enforced |
| Data preservation | **Pass** — accounts, ids, FKs and audit history survive migration |
| Break-glass path | **Pass** after two fixes; CLI-only, password-gated, flag-gated, audited |
| Dependency posture | **Pass with a caveat** — SDK declared but not vendored (§6) |

Eleven issues were found during this audit and have been fixed; they are listed in
§4, because a report that only lists what currently looks fine is useless to the
next reviewer. Five risks remain open and are listed in §5 — the most important
being that none of this has been run against the live Clerk instance.

---

## 2. Threat model

Assets, in order of value:

1. **Administrator accounts** — they can approve subdomains and manage domains.
2. **User accounts and their reservations/subdomains** — ownership integrity.
3. **The Clerk secret key** — full Backend API access to the instance (create
   sign-in tokens, read users, revoke sessions).
4. **The webhook secret** — forgeable account-state events.
5. **Audit trail integrity** — needed for abuse handling.

Trust boundaries:

* **Browser → PHP.** Untrusted. The only privileged input is a Clerk session
  token, and it is only accepted at `/clerk-bridge.php`.
* **PHP → Clerk Backend API.** Trusted, authenticated with the secret key.
* **Clerk → PHP (webhooks).** Untrusted until the svix signature verifies.
* **Operator → CLI.** Trusted but still password-gated for break-glass.

Out of scope for this task (unchanged): Cloudflare/DNS automation, hosting
features, email deliverability, and the foundation's existing controls (already
covered by `tests/run.php security`).

---

## 3. Controls, and where to find them

### 3.1 No password in the web tier

`login.php` and `register.php` render a Clerk mount (`includes/views/partials/clerk-auth.php`)
and nothing else. There is no `type="password"` input, no `Auth::attempt()` call
and no password field on any route — asserted by the `views`, `auth` and HTTP
suites. `users.password_hash` is nullable after the migration and is used only by
`database/break-glass.php`.

Passwords, OAuth and e-mail codes are handled by `clerk-js`, loaded from the
instance's own frontend API (`https://<frontendApi>/npm/@clerk/clerk-js@<ver>/dist/clerk.browser.js`),
so no third-party CDN is trusted at runtime beyond Clerk itself. The bundle is
pinned by `clerk.js_version` and the local script never decrypts or parses a JWT
in the browser: it only asks Clerk for a signed token.

### 3.2 Token verification (`ClerkTokenVerifier`)

Done natively with `openssl` so it is auditable and testable:

| Control | Implementation | Failure mode |
|---------|----------------|--------------|
| `alg` pinning | Header must be exactly `RS256` **before** any key is fetched | Hard reject; `none`, `HS256` and `RS512` are refused |
| Signature | `openssl_verify(header.payload, sig, publicKey, SHA256)` | Hard reject |
| `kid` | Must be present; unknown `kid` triggers one cache-bypassing JWKS refetch, then rejects | Hard reject |
| `exp` / `nbf` | With `clerk.clock_skew` (default 5 s) | Hard reject |
| `iss` | `hash_equals` against `https://<frontendApiHost>` | Hard reject (the official SDK never checks `iss`) |
| `azp` | `in_array` against `CLERK_AUTHORIZED_PARTIES` **when present** | Hard reject |
| `sub` | `/^user_[A-Za-z0-9_]{1,190}$/` | Hard reject |
| JWKS transport | HTTPS only, 5 s timeout, 256 KB cap, disk cache with TTL | Treated as "cannot verify" → reject |

Comparison is constant-time (`hash_equals`) everywhere a value is compared to a
trusted one. The failure reason is mapped to a fixed, user-safe message; raw
exception text never reaches the browser.

### 3.3 Handoff and session binding

* The bridge is **POST-only** (`405` on anything else), checks the `Origin`
  header against the authorized parties (`403`), requires the per-page
  `channel` + `state` pair that lives in the server session (`403`), and rate
  limits per IP (`20/5 min`, `429`).
* Tickets are 32 random bytes, stored **only** as `sha256`, single-use, 60 s TTL
  (120 s for links), and claimed atomically:
  `UPDATE clerk_handoffs SET state='consumed' WHERE id=:id AND state='pending'`
  — a concurrent replay cannot both succeed.
* A ticket is bound to the user row it was issued for: on redemption the row's
  `clerk_user_id` must still equal the ticket's (`identity_mismatch` otherwise).
* The PHP session id is regenerated on login; the session stores the internal
  user id, the Clerk user id, the Clerk session id, a client fingerprint and a
  verification timestamp — never a token or a secret.
* Every request re-loads the user row: `status` must be `active`, the row's
  `clerk_user_id` must match the session, and the fingerprint must match
  (`$_auth_fingerprint`), so a copied cookie stops working.
* Optional periodic liveness check against `GET /v1/sessions/{id}`
  (`CLERK_SESSION_RECHECK`). Failure is **fail-open by design** (a Clerk outage
  must not sign everyone out) but the local idle timeout still applies.

### 3.4 Roles and authorization

* `role` is written only by PHP literals: `'user'` on provisioning, `'admin'` only
  by `create-admin.php` or an explicit SQL change by an operator.
* Clerk `public_metadata` / `private_metadata` / `unsafe_metadata` are ignored —
  asserted by the `clerk-sync` suite.
* `Auth::requireAdmin()` reads the database row; no client input participates.
* Break-glass sessions are re-validated on every request: the account must still
  be an active `admin` **and** `CLERK_BREAK_GLASS` must still be true, otherwise
  the session is destroyed.

### 3.5 Account reconciliation

`User::syncFromClerk()` runs inside a transaction and:

* reuses the row when `clerk_user_id` matches (no duplicate accounts);
* attaches the identity to an existing account **only** when Clerk reports the
  e-mail as verified, and refuses when that address is already linked to another
  Clerk user;
* never lets an unverified address claim an existing account;
* preserves `id`, `role`, `status`, reservations, subdomains and audit history;
* refuses banned Clerk users.

### 3.6 Webhooks

`ClerkWebhook::verify()` runs before `json_decode()`:

* requires `svix-id`, a 10-digit `svix-timestamp` and `svix-signature`;
* rejects timestamps outside ±300 s (replay window);
* base64-decodes the `whsec_` secret and compares
  `base64(HMAC-SHA256("{id}.{timestamp}.{body}"))` with `hash_equals`, accepting
  any `v1,<sig>` entry in a space-separated list (key rotation);
* a missing `CLERK_WEBHOOK_SECRET` makes the endpoint return `503` and log once —
  it never processes unverified events;
* responses are constant-shaped (`{"ok":…,"handled":…}`) and never echo the
  secret, the signature or an exception message;
* handling is idempotent and never creates an account or changes a role.
  `user.deleted` unlinks the identity and keeps the account.

### 3.7 Secret hygiene

Verified by test:

* the secret key is never rendered — `clerk-config` asserts the embedded public
  configuration and `ClerkConfig::diagnostics()` contain no `sk_` value, and the
  HTTP suite asserts no page body contains it;
* the bridge's success payload is exactly `{ok, token, redirect}`;
* no secret or token is logged; `error_log()` calls carry exception class/message
  only, and `ClerkApiException` never interpolates the key;
* `.env` (with the real keys) is web-inaccessible via `.htaccess` **and**
  `router.php`, kept out of git, and `composer.lock.md` documents the swap
  without containing a value;
* no token is ever placed in a URL: the ticket travels in a POST body or a
  hidden field, and the break-glass link is a one-time 64-hex token that expires.

### 3.8 Data-integrity controls in the migration

`database/migrate.php`:

* all steps are guarded (idempotent) and can be inspected first with `--status`;
* the SQLite `password_hash` rebuild runs inside `Database::transaction()` with an
  **explicit column list** and compares `COUNT(*)` before and after, rolling back
  on mismatch;
* `PRAGMA foreign_keys` is disabled only for the rebuild and restored afterwards;
* verified on a real legacy database: 3 accounts, 1 administrator, exit summary
  `Applied: 8  Failed: 0`, physical row ids unchanged, orphaning check clean.

---

## 4. Findings fixed during this audit

These were real defects in code written earlier in the same task. Each has a
regression test.

### F1 — The bridge never saw the channel/state (functional break, High)

`clerk-auth.js` posts `application/json`, but `clerk-bridge.php` read
`$_POST`, which PHP only populates for form encodings. Every real browser sign-in
would have failed with `missing_credentials`.

**Fix:** decode the JSON body first (with a 16 KB cap), fall back to `$_POST`
for form posts. *Covered by:* `tests/http-clerk.sh` (bridge → 200) and the
"valid token accepted by the bridge" check; before the fix it returned `400`.

### F2 — Session fingerprint was never enforced (High)

`Auth::guardAgainstFixation()` compared the fingerprint **at session start** and
`user()` never re-checked it, so a session cookie copied to another client
remained valid. Note that the pre-existing code has the same weakness, which
made it easy to miss.

**Fix:** `user()` now verifies `_auth_fingerprint` on every resolution and
destroys the session on mismatch. *Covered by:* `clerk-session` →
"session fingerprint mismatch is rejected".

### F3 — Break-glass login accepted no credentials (Critical)

`Auth::loginBreakGlass($user)` was a public method that started a 100 % privileged
session for any `admin` row, gated only by `CLERK_BREAK_GLASS`. Any future
caller — or a mistake in the queue worker or an API — would have been an
authentication bypass. It also made the "password required" promise in the CLI
unenforceable.

**Fix:** the method now requires an explicit `Auth::BREAK_GLASS_PROOF` handshake
value that only the CLI command passes, plus the role and flag checks. The
dependency is inverted: the *issuer* (`database/break-glass.php`) does the
password check, and `handoff.php` can only consume a ticket the issuer signed
(`ip_address='cli'`, `user_agent='break-glass'`). *Covered by:* `clerk-session` →
"break-glass login requires the CLI proof", `clerk-handoff` → the four
break-glass ticket assertions.

### F4 — Break-glass links could not be redeemed (functional break, Medium)

`ClerkHandoff::redeem()` required a Clerk identity, but break-glass tickets are
issued for accounts that have none, so every link was rejected as
`identity_mismatch`. The regression only appeared on the real page.

**Fix:** tickets now carry a kind (derived from the issuing row) and break-glass
tickets are validated against the admin role instead of a Clerk id. *Covered by:*
`clerk-handoff` → "CLI break-glass ticket redeems", "break-glass ticket refused
for a non-administrator", "break-glass ticket dies when the account is demoted".

### F5 — The in-memory signing-key cache ignored the instance (Low, crypto hygiene)

`ClerkTokenVerifier::signingKey()` cached by `kid` only. Two instances (or a
staging host and production) could share a `kid` and a process would then verify
production tokens with a staging key — or, more realistically, a rotated key
would not be re-fetched. **Fix:** the cache key is `sha256(jwksUrl|kid)`.
*Covered by:* `clerk-token` → "key rotation: loader is consulted for a new kid".

### F6 — Wrong status codes on the bridge (Low, information disclosure)

A bad channel returned `400`, indistinguishable from a malformed request, and the
`Origin` rejection shared the same shape. **Fix:** channel/state mismatch is `403`,
`Origin` rejection is `403`, expired token is `401`, rate limit is `429`,
unconfigured/misconfigured is `503`.

### F7 — A stray label in the test runner broke statement splitting (Low, test-only)

`tests/run.php` split `schema.sqlite.sql` on `;` and then skipped statements that
*started* with `--`. A comment directly above a `CREATE TABLE` became part of the
statement, so `clerk_handoffs` was never created and every Clerk test failed with
`no such table`. The same parser is used by `database/install.php`, meaning a
fresh install could skip a table. **Fix:** strip comment lines before splitting.
*Covered by:* the whole `clerk-*` suite, which could not run before.

### F8 — Two suites depended on each other's fixtures (Low, test-only)

`audit` and `clerk-session` crashed when run alone (`Trying to access array
offset on null`). **Fix:** each suite seeds what it needs.

### F9 — Deprecated reflection in the test helper (Informational)

`ReflectionProperty::setAccessible()` is deprecated as of PHP 8.5 and emitted
notices into the test output. **Fix:** removed; the memo reset is still explicit.

### F10 — Diagnostic output implied success (Low, operator-facing)

`ClerkConfig::diagnostics()` could show a green "publishable key" line while the
secret was missing, and `clerk-check.php` printed the JWT-template advice even
when it then exited non-zero. **Fix:** `canCompleteSignIn()` and the exit codes
(`0 ok / 2 incomplete / 3 unreachable-or-rejected-key`) now reflect unusability,
and the summary text states plainly that nobody can sign in.

### F11 — The Clerk SDK URL was never resolvable (functional break, Critical for sign-in)

`clerk-auth.js` builds the SDK URL from `config.frontendApi`, but the partial
never emitted that key. `clerkScriptUrl()` therefore returned `null`, the script
never loaded, and every visitor saw "Sign-in is not configured. Please contact
support." on a correctly configured deployment. The value is public (it is the
first segment of the publishable key), so there was never a reason to omit it.

**Fix:** the partial now emits `frontendApi` from `ClerkConfig::frontendApiHost()`,
and the end-to-end suite asserts that **every** key `clerk-auth.js` reads is
present in the rendered page — verified mechanically by grepping `config.<key>`
out of the script and comparing it with the embedded JSON, so this class of bug
cannot come back silently.

---

## 5. Open risks

### R1 — The integration is unverified against the live instance (High impact, open)

No live Clerk call has been made: no secret key is available and outbound access
to Clerk is blocked here. Everything is proven against a test double whose token
shape mirrors Clerk's documented format. **Residual risk:** the `freesub_handoff`
JWT template must exist and include the default session claims; the instance's
`iss` must be `https://<frontendApiHost>`; and the allowed origin must match
`CLERK_AUTHORIZED_PARTIES` byte for byte (scheme, host, port, no trailing slash).
All three are configuration steps, all three are checked by
`php database/clerk-check.php` or produce a specific error message.
**Action:** run the README checklist before declaring the task done.

### R2 — `vendor/` is absent, so the declared SDK is not exercised (Medium, accepted)

`composer.json` requires `clerkinc/backend-php: ^3.0`, but Packagist is
unreachable here and vendoring Guzzle/Speakeasy was explicitly rejected. The
runtime code therefore uses the native verifier and a stream-based API client.
**Residual risk:** the SDK path is documented, not tested. Mitigation: the
application does not need `vendor/` to run, and `composer.lock.md` records the
exact swap for `AuthenticateRequest`.

### R3 — Fail-open on session re-check (Low, intentional)

If the periodic `GET /v1/sessions/{id}` fails (Clerk outage, network), the user
stays signed in until the idle timeout. Revocation is still enforced by
`logout.php` (local), by webhooks (`session.ended` closes the mirror) and by the
administrator's ability to suspend the row. Documented rather than "fixed",
because failing closed during an outage would lock out every user.

### R4 — Rate limiting is IP-based and file-backed (Low, inherited)

`clerk-bridge.php` limits per IP (`20/5 min`) and `rate_limit_hit()` fails open if
`storage/cache` is unwritable. A distributed credential-stuffing attempt against
the bridge is limited by Clerk's own protections on the credential step, not by
ours. Enforcement of "one sign-in per session channel" is the compensating
control.

### R5 — Break-glass is a deliberate bypass (Low, by design)

With shell access, `CLERK_BREAK_GLASS=true` and (when present) the local password,
an operator can mint an admin session. This is the requested escape hatch; the
compensating controls are the flag, the password, single use, the short TTL, the
warning banner, full auditing (`auth.break_glass_link_issued`,
`auth.break_glass_login`, `auth.break_glass_failed`) and the fact that there is no
web route that accepts a password.

---

## 6. Test evidence

| Suite | Assertions | What it covers |
|-------|-----------:|----------------|
| `clerk-config` | 13 | key decoding, host/iss/JWKS derivation, diagnostics, secret-as-publishable rejection |
| `clerk-token` | 22 | signature, tampering, foreign key, `alg` downgrade, expiry, `nbf`, `iss`, `azp`, `sub`, unknown `kid`, JWK→PEM, rotation |
| `clerk-sync` | 22 | first sign-in, duplicates, legacy admin migration, FK preservation, unverified-email hijack, bound-identity conflict, banned user, metadata→role refusal |
| `clerk-handoff` | 31 | channel/state, single-use, replay, expiry, origin, outage, deleted user, break-glass tickets |
| `clerk-session` | 22 | session keys, resolve, admin guard, logout, legacy session, fingerprint, suspension, break-glass gating, no secrets in session |
| `clerk-webhook` | 14 | HMAC, tampering, wrong secret, replay window, rotation list, parse ordering |
| `clerk-webhook-effects` | 17 | `user.updated`/`user.deleted`/session events, no provisioning, idempotency |
| `env`, `security`, `auth`, `schema`, `subdomains`, `audit`, `views` | 125 | inherited controls (8+39+22+13+25+8+10), still green after the auth rewrite |
| **Total** | **266** | all green, no network, isolated SQLite |
| `tests/http-clerk.sh` | **58 checks** | real HTTP: both pages render, no password field, every config key the JS reads, guest redirects, bridge hardening, full handoff, replay, admin denial, webhook signature/replay/idempotency, administrator migration, logout |

Not covered by automated tests, and why: rendering of the Clerk components
(themselves) needs a browser and network access to the frontend API; Google
OAuth needs real Google credentials; e-mail delivery needs a real inbox.

---

## 7. Recommendations before production

1. Run the README checklist against the live development instance, then repeat
   once with a **second** test user to confirm no duplicate rows appear.
2. Create the `freesub_handoff` JWT template **before** enabling the site; without
   it `session.getToken({template})` returns `null` and the bridge says
   "could not be read".
3. Register the webhook endpoint and store its secret; then delete a throwaway
   user in the dashboard and confirm the row is unlinked (not deleted).
4. Set `APP_URL` and `CLERK_AUTHORIZED_PARTIES` explicitly in production — never
   rely on auto-detection behind a proxy.
5. Keep `CLERK_BREAK_GLASS=false` except during an incident, and run
   `php database/link-clerk.php --unlinked` after onboarding to confirm every
   pre-Clerk account is attached.
6. Back up the database before `php database/migrate.php` on production MySQL.
7. Rotate the secret key if it was ever pasted into a chat, ticket or shell
   history; `php database/clerk-check.php` will report a rejected key (exit 3).
