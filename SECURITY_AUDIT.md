# Security audit — Task 02 (Clerk authentication) and Task 03 (DNS engine)

**Scope:** the replacement of FreeSub's native password authentication with Clerk,
covering `login.php`, `register.php`, `logout.php`, `clerk-bridge.php`,
`auth/handoff.php`, `clerk/webhook.php`, `includes/auth.php`,
`includes/models/User.php`, `includes/services/Clerk/*`, the schema changes and
the CLI tooling in `database/`.

Task 03 scope: `includes/services/DnsProvider.php`,
`includes/services/Dns/*`, `database/dns-sync.php`, `admin/dns.php`,
`includes/views/admin/dns.php`, the `dns_records` migration in
`database/migrate.php` + both schema files, `tests/dns-suite.php` and
`tests/support/CloudflareTestDouble.php`.

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
| Availability / error reporting | **Pass** after one fix — a Clerk or JWKS outage answers 503, not a misleading 401 (§4 F14) |
| Authorization / roles | **Pass** after one critical fix — roles are server-side only, and an admin row can no longer be inherited by a web sign-in (§4 F12) |
| CSRF / same-origin | **Pass** |
| Secret hygiene | **Pass** — no secret in HTML, logs, URLs, database or responses |
| Webhook trust | **Pass** — svix HMAC verified before parsing, replay window enforced |
| Data preservation | **Pass** — accounts, ids, FKs and audit history survive migration |
| Break-glass path | **Pass** after two fixes; CLI-only, password-gated, flag-gated, audited |
| Dependency posture | **Pass with a caveat** — SDK declared but not vendored (§6) |

**Task 03 (DNS engine) — additional rows:**

| Area | Result |
|------|--------|
| Credentials | **Pass** — token read from the environment at call time, never stored on a dumpable object, scrubbed from free text in logs, never in HTML/JS/DB/exceptions |
| Zone binding | **Pass** — the zone is verified against `DNS_ROOT_DOMAIN` and must be `active` before any operation; no caller can choose a zone |
| SSRF | **Pass** — fixed base URL, validated path segments, no caller-supplied URL, `http` refused except loopback |
| Record safety | **Pass** — A/AAAA/CNAME only, public addresses only, no wildcard, no proxying, no reserved labels |
| Write safety | **Pass** — a create is never blindly retried; ambiguous outcomes are reconciled, and deletions address exactly one owned id |
| Non-destruction | **Pass** — sync reports and never deletes or recreates; unknown records are listed and left alone |
| Admin surface | **Pass** — admin-only, CSRF-checked, read-only, no token and no mutation control |
| Migration | **Pass** — additive, backfilled, guarded on both engines; no row deleted |
| Live verification | **Open** — nothing has been run against the real Cloudflare API (§4b D-R1) |

**Task 04 (reservation engine) — additional rows:**

| Area | Result |
|------|--------|
| Input validation | **Pass** — one strict validator shared by the API, the dashboard and the reservation path; rejects rather than rewriting; raw input is validated, not a pre-cleaned copy |
| Availability honesty | **Pass** after one fix — an unreachable provider returns `unknown`, never "available" (§4c R1); failures are not cached (§4c R2) |
| Duplicate/race protection | **Pass** — one live reservation per `(domain, label)` enforced by the database (partial index on SQLite, nullable `live_guard` key on MySQL); every transition is a conditional `UPDATE` |
| Quota | **Pass** — resolved server-side from three levels, counts pending reservations, bounded 0–500, audited |
| Identity scoping | **Pass** — ownership is always in the SQL `WHERE`; an unknown or foreign id returns the same "not found" path, so ids cannot be enumerated |
| DNS write path | **Pass** — no web page calls a provider write; provisioning is admin/CLI triggered, dry-run by default (§4c R5) |
| Activation | **Pass** — a subdomain becomes `active` only after a read-back whose value is compared; one write site |
| Failure handling | **Pass** — bounded backoff, no blind create retry, `last_error` visible to admins, cancelled jobs cannot be run |
| Migration | **Pass** — additive, backfilled, idempotent on both engines; `live_guard` backfill preserves existing live reservations |
| Live verification | **Open** — availability has never consulted a real zone, and provisioning has never written one (§4c R-1) |

Twenty-six issues were found across the three deliveries (fourteen in Task 02,
five in Task 03, seven in Task 04), all fixed; they are listed in §4, §4b and §4c,
because a report that only lists what currently looks fine is useless to the next
reviewer. Risks that remain open are listed in §5, §4b and §4c — the most
important being that none of the three integrations has been run against the live
service it talks to.

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

### F12 — An administrator row could be claimed by web sign-in (Critical)

`User::syncFromClerk()` step 2 linked **any** existing row to a Clerk identity
whose email Clerk reported as verified — including rows with `role='admin'`.
An attacker who controls an administrator's email address (recycled address,
re-registration after a lapse, a second Clerk user added to the same mailbox, or
a compromised mailbox) could sign up with it and inherit the admin account, its
id, its reservations and its role. Reproduced before the fix:

```
admin row id=3
claimed id = 3  role = admin  linked=true
>>> normal user now controls the admin account? YES (CRITICAL)
```

Note that `role` is *not* upgradable by metadata or claims (that control held),
but *inheriting* a pre-existing admin row is a privilege escalation by a
different route.

**Fix.** Step 2 now refuses when the candidate row is an `admin`, unless the
call passes `$force = true`, which only documented CLI code does. The message is
neutral and does not disclose the account's address. `database/link-clerk.php` —
the sanctioned path — was hardened at the same time: it now requires Clerk to
report the address as **verified**, refuses when it does not match the local row,
and never touches the role.

*Covered by:* new suite `clerk-privilege` (12 assertions) — web claim refused,
admin row unchanged, no ticket issued, address not echoed, normal accounts still
link automatically and keep their id and role, CLI path still works.

### F13 — `config()` mutated its own cache, so overrides could not be withdrawn (High, test seam / correctness)

`config()` merged `$GLOBALS['FREESUB_CONFIG_OVERRIDE']` **into** its static cache,
which made the override permanent for the rest of the process. It surfaced as a
baffling failure: an audience test set `clerk.audiences = ['freesub']`, restored
the previous override, and every later token test failed
`token_invalid_audience`. The seam is test-only, but the defect is exactly the
kind that hides production bugs — a value that cannot go back to how it was.

**Fix:** merge into a copy and return that. *Covered by:* `security` →
"config overrides are reversible", plus the previously failing rotation test.

### F14 — JWKS outage was reported as a client error (Medium)

`ClerkTokenVerifier` correctly returns `jwk_unavailable` when the key set cannot
be fetched, but `clerk-bridge.php` mapped every unrecognised reason to **401**.
A temporary Clerk outage therefore looked to the browser, to monitoring and to
the user like "your token was rejected". **Fix:** `jwk_unavailable`,
`not_configured` and `clerk_unavailable` now return **503**; channel and origin
problems return **403**. *Covered by:* `clerk-token` → "unreachable JWKS is
reported as key-unavailable"; exercised end to end through the bridge.

---

## 4b. DNS engine findings (Task 03)

These are the defects found while building and reviewing the Cloudflare engine,
plus the controls it adds. "Fixed" means the code and a regression test carry it.

### D1 — Silent credential downgrade would have attempted half-configured writes (High, fixed)

`DnsProvider::make('cloudflare')` originally produced a driver unconditionally.
A token without a zone id (or the reverse) would have reached the HTTP layer with
an empty value and failed at an unpredictable point. The factory now returns
`NullDnsProvider` unless **both** the token and the zone id are present, and the
degradation is logged with `present`/`missing` markers, never values.
Covered by `dns-security` ("factory falls back to the null driver without
credentials", "refuses a token without a zone id").

### D2 — `array + array` kept the defaults, so every pre-flight failure reported an empty reason (High, fixed)

`verifyZone()` merged its failure codes with `$base + ['code' => …]`. Union keeps
the left-hand keys, so `code` stayed `'error'` and `message` stayed `''`: the
admin page and the CLI would have shown a blank reason for every failure —
misconfiguration, auth failure, zone mismatch — while the driver silently
reported "wrong zone". Found by the first run of `dns-client`, fixed with
`array_merge`, and every branch now asserts its code.

### D3 — `listRecords()` read `result` before validating `success` (Medium, fixed)

The listing path bypassed the response validator and indexed `$response['result']`
directly, which is an undefined-key warning on an error envelope and would have
turned a clean auth failure into a half-rendered page. It now goes through the
same `decode()` that validates `success` and `errors`.

### D4 — A transport failure delivered as HTTP status 0 was not retried (Medium, fixed)

The double revealed that a dead socket can surface as `status: 0` rather than as
an exception. Reads now treat that as a transport failure (retry with backoff);
writes treat it as **ambiguous** and never retry. Two assertions: "a transport
failure on a read is retried" and "a 5xx on create is not retried".

### D5 — Reconciliation test was proving the wrong thing (test-only, fixed)

The first version of the "create timed out" test seeded the record *before* the
call, so it proved the duplicate check rather than reconciliation. The double now
supports `applyThenTimeoutOn('POST')`: the write lands, the response is lost. The
engine re-reads the zone, adopts the existing Cloudflare id, and does not create a
second record — which is exactly the failure mode the brief forbids blind-retrying.

### D6 — Empty name bypassed the subdomain-ownership check (Medium, fixed)

`createForSubdomain()` validated the (empty) caller-supplied name before filling
in the subdomain's own FQDN, so the first attempt at a record failed with "enter
the hostname". The name is now resolved before validation, and a name that is not
the subdomain's own FQDN is refused outright.

### Controls added by this task (all regression-tested)

| Control | Where | Test |
|---------|-------|------|
| Credentials from the environment only; never in the DB, HTML, JS, logs or exceptions | `CloudflareApiClient` constructor; `DnsLog::scrub` | `dns-client` token assertions, `dns-security`, HTTP run |
| Zone must match `DNS_ROOT_DOMAIN` and be `active` before any operation | `CloudflareApiClient::verifyZone` | `dns-client` (4 cases) |
| No caller-supplied URL: fixed base URL + validated path segments + identifier regex | `request()` / `requireIdentifier()` | "path guard refuses an absolute URL", "invalid zone id refused" |
| Plaintext base URL refused except loopback | `isAllowedUrl()` | `dns-security` |
| Only A/AAAA/CNAME may be written, whatever the caller asks | `requireWritableType()`, `DnsRecordValidator::validate` | 6 type refusals + client-level refusal |
| Public-address-only targets | `DnsRecordValidator::isBlockedIp` (v4 and v6, incl. IPv4-mapped) | 13 blocked IPv4 + 9 blocked IPv6 |
| Remote record addressed only by the id stored on our own row | `DnsManager::deleteRecord` / `updateRecord` | delete ownership, cross-domain delete |
| Never retry a create; reconcile first | `CloudflareApiClient::request`, `DnsManager::reconcileAfterFailure` | "a 5xx on create is not retried", "reconciled after a timeout" |
| Sync never deletes or recreates | `DnsManager::sync` | "never deletes an unmanaged record", "does not recreate a missing record" |
| Admin-only page, CSRF on every POST, no mutation control, no token in source | `admin/dns.php` | `dns-security` source checks + HTTP run |
| Every mutation audited (`dns.record_created`, `_deleted`, `_updated`, `dns.sync`, connection test) | `audit_log` calls | audit assertions in `dns-lifecycle` |
| Migration is additive and backfills | `database/migrate.php`, both schemas | migration run on a legacy DB, 9/9 MySQL checks |

### Risks that remain open (Task 03)

| # | Risk | Severity | Status |
|---|------|----------|--------|
| D-R1 | The engine is unproven against the live Cloudflare API. | High impact, low likelihood (the request/response shapes follow the documented v4 API) | Open by environment: no credentials, `api.cloudflare.com` unreachable |
| D-R2 | An operator with CLI access and a token can create records for any in-zone name, because the CLI is trusted. | Medium | Accepted: the CLI is the operator's tool, and it runs with the same rules; every action is audited |
| D-R3 | `DnsRecordValidator` blocks 6to4 and NAT64 prefixes wholesale, which could reject a legitimate IPv6 target. | Low | Accepted: refusing a rare address is safer than allowing an SSRF-adjacent target |
| D-R4 | Retry sleeps (up to ~4 s, or 5 s on `Retry-After`) can delay an admin request. | Low | Accepted: retries apply to reads only and are capped |
| D-R5 | SQLite cannot add a `REFERENCES` clause via `ALTER TABLE`, so a migrated SQLite database enforces `domain_id` in the application, not the engine. | Low (development only) | Documented in `migrate.php`; fresh installs and MySQL have the FK |

---

## 4c. Reservation engine findings (Task 04)

The defects found while building the availability and reservation engine, plus the
controls it adds. Every "fixed" entry is carried by code and a regression test in
the `reservations` suite.

### R1 — `useCache` never reached the lookup, so a reservation could trust a stale "free" (High, fixed)

`AvailabilityEngine::remote()` called the lookup seam with two arguments while
`lookupAuthoritative()` declares three. PHP silently used the default
(`$useCache = true`), so the boolean the reservation path passes to force an
authoritative read was discarded — a name could be reserved on the strength of a
cached "available" even though the zone had changed. The flag is now forwarded,
and the suite asserts an uncached call reaches the provider.

### R2 — Provider errors were one retry away from being cached (High, fixed)

The first version of the fail-closed check injected a single `500`. The client
retries reads twice, so the second attempt succeeded and the *successful* answer
was cached — which is correct behaviour for the client and the wrong test. It also
exposed the real hazard: a cache write must never be reachable from the error
path. Failures now bypass `AvailabilityCache::put()` entirely and the suite scripts
every attempt, then repeats the lookup to prove the error was not persisted.

### R3 — The memoised DNS driver leaked between configurations (High, test-only symptom, production-correct fix)

`DnsProvider::current()` cached its driver in a function-static. Correct for a
request, wrong for a process that installs several configurations (the test
runner): the reservation suite inherited the driver built by the DNS suite, so the
zone looked unconfigured and provisioning "failed" for the wrong reason. Replaced
with `DnsProvider::reset()`, called by the harness. In production the memoisation
is still exactly one driver per request.

### R4 — The dashboard's availability widget swallowed the reservation submit (High, would have blocked every reservation)

The search helper called `preventDefault()` on submit, which is right for the
landing-page search widget and catastrophic for the dashboard form whose submit
button *is* the reservation. The helper is now `bindAvailabilitySearch()` with an
`interceptSubmit` flag: the dashboard binds without interception, the landing page
with it.

### R5 — A policy-blocked name was reported as a typo (Medium, fixed)

`SubdomainLabel` correctly refused `www`, `@` and `*`, but the engine passed the
validator's status through unchanged, so all three came back as `invalid` — which
tells a user to fix their spelling when the truth is "this name will never be
yours". They now map to `reserved`, and the API documents that difference.

### R6 — `error_for()` was called with its arguments reversed (Medium, fixed)

`error_for(array $errors, string $field)`. The dashboard view passed
`error_for('label', $errors)` and *every* dashboard render after a failed
reservation threw a `TypeError` → HTTP 500. Caught by the end-to-end run, not by
the unit-style checks, which is exactly why the HTTP harness exists.

### R7 — SQLite could not accept the reservation insert after migration (High, fixed)

The live-label guard added a `live_guard` column to the MySQL schema and to
migration `2026_11_003`, but the **SQLite mirror schema** — which fresh installs
and the whole test suite build from — was missed. Every reservation insert failed
with "table reservations has no column named live_guard" on a fresh database.
Fixed in the mirror schema *and* guarded by a migration step that adds the column,
backfills `1` for pending/approved, and returns "already applied" on a re-run.

### Controls added by this task (all regression-tested)

| Control | Where | Test |
|---------|-------|------|
| One strict label validator; the raw value is validated, never a pre-cleaned copy | `SubdomainLabel::validate` | 10 rejection classes + 3 acceptance cases |
| Reserved/apex/wildcard reported as policy, not as a typo | `AvailabilityEngine::check` | "a reserved label is explained" (HTTP + suite) |
| Fail closed: provider error ⇒ `unknown`, `available = false`, HTTP `503` | `AvailabilityEngine`, `api/check.php` | "provider failure fails closed", "reported as unknown" |
| Errors are never cached | `lookupAuthoritative` | "a failed lookup is not cached" |
| Authoritative re-check on the reservation path | `ReservationService::create` | provisioning/availability assertions |
| One live reservation per `(domain, label)`, enforced by the database | partial unique index / `live_guard` key + `Reservation::transition` | "two accounts cannot both reserve the same label", "exactly one live row exists" |
| Ownership always in the SQL predicate; no id enumeration | `ReservationService::cancel`, `Subdomain::findForUser` | "cannot be cancelled by another account", "an unknown subdomain id is refused" |
| Quota resolved server-side (user → domain → platform), counts pending requests | `SubdomainQuota` | quota acceptance/refusal, admin override |
| Quota changes bounded (0–500) and audited | `SubdomainQuota::setLimit` | "a quota change is audited", "out-of-range refused" |
| Approval cannot activate and cannot write DNS | `ReservationService::approve` | "approval creates a pending_dns subdomain", "approval does not create a DNS record" |
| Activation only after a verified read-back; one write site | `ProvisioningQueue::provision` | "a record that cannot be verified is not accepted", "never activates" |
| Provisioning refuse-to-guess without a configured target | `ProvisioningQueue::targetsFor`, CLI guard | "provisioning without a configured target fails" |
| Atomic job claim; no blind create retry; bounded backoff | `ProvisioningQueue::claim` / `fail` | completed-job re-run, cancelled-job refusal, `last_error` visibility |
| Provisioning is admin/CLI only; no web page writes to a zone | source check over every web entry point | "… does not call the provider directly" (4 pages) |
| Additive migration preserving ids, reservations and FKs | `database/migrate.php` `2026_11_003` | fresh install, legacy upgrade, duplicate-block, re-run |

### Risks that remain open (Task 04)

| # | Risk | Severity | Status |
|---|------|----------|--------|
| R-1 | Availability has never consulted a real zone, and provisioning has never written one. | High impact, low likelihood (the shapes follow the documented API and are exercised against a scripted double) | Open by environment: no Cloudflare credentials |
| R-2 | A reservation holds a label for up to `RESERVATION_TTL_HOURS` (168 h) while an administrator is unavailable; the sweep is manual. | Medium | Accepted: the admin queue shows lapsed rows and offers "Expire lapsed"; a scheduled call is a deliberate operator choice, not a default |
| R-3 | Availability is cached for up to `AVAILABILITY_CACHE_TTL` (300 s), so a name created directly in Cloudflare may read as free for that window. Reservation re-checks without the cache, so the label cannot actually be taken. | Low | Accepted: bounded by the TTL; errors are not cached |
| R-4 | The dashboard release path only cancels `pending_dns`/failed names; an `active` name must be released by support so its record is removed by the audited DNS path. | Low | Accepted: the alternative — deleting a record from a web form — is the risk this design exists to avoid |
| R-5 | Provisioning jobs are claimed atomically but there is no queue daemon; a job sits until an admin or the CLI runs it. | Low | Accepted and intentional: automatic public DNS writes are out of scope |
| R-6 | `provisioning_jobs.requested_by` uses `ON DELETE SET NULL`, so a deleted administrator leaves an unattributed (but still timestamped and audited) job. | Informational | Accepted: `audit_logs` retains the actor id and action |

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
| `clerk-token` | 28 | signature, tampering, foreign key, `alg` downgrade, expiry, `nbf`, `iss`, `azp`, `aud` (5 cases), `sub`, unknown `kid`, unreachable JWKS, JWK→PEM, rotation |
| `clerk-sync` | 24 | first sign-in, duplicates, admin claim refused, FK preservation, unverified-email hijack, bound-identity conflict, banned user, metadata→role refusal |
| `clerk-privilege` | 12 | takeover attempts: admin row cannot be claimed (model + handoff), row untouched, no ticket, address not echoed, normal linking still works, CLI path works |
| `clerk-handoff` | 31 | channel/state, single-use, replay, expiry, origin, outage, deleted user, break-glass tickets |
| `clerk-session` | 22 | session keys, resolve, admin guard, logout, legacy session, fingerprint, suspension, break-glass gating, no secrets in session |
| `clerk-webhook` | 14 | HMAC, tampering, wrong secret, replay window, rotation list, parse ordering |
| `clerk-webhook-effects` | 17 | `user.updated`/`user.deleted`/session events, no provisioning, idempotency |
| `env`, `security`, `auth`, `schema`, `subdomains`, `audit`, `views` | 126 | inherited controls (8+40+22+13+25+8+10), still green after the auth rewrite |
| `dns-validate` | 66 | record rules: in-zone names, foreign/apex/suffix-trick/wildcard/underscore/reserved/numeric labels, 13 blocked IPv4 ranges, 9 blocked IPv6 ranges (incl. IPv4-mapped), CNAME URL/IP/port/apex/`.local` targets, 6 refused record types, unsupported TTL |
| `dns-client` | 30 | zone verified / mismatched / not-active / unknown, 401 + 403 → auth, 429 → rate_limited, non-JSON body, `success=false` on HTTP 200, read retry after 5xx and after a transport failure, token never logged, 40-char scrub, path/identifier guards, forbidden type refused by the client |
| `dns-lifecycle` | 53 | create → id persisted → audit, duplicate, cross-subdomain name, name outside the subdomain, private IPv4, **create 5xx not retried**, **write-landed-then-timeout reconciled without a duplicate**, update (+ private address, wrong domain), delete (ownership, single record, unmanaged record survives, soft delete, audited, idempotent, cross-domain refused), sync (unmanaged preserved, externally deleted reported, never recreated), provider availability incl. fail-open |
| `dns-security` | 12 | factory degrades to null without credentials, token-without-zone refused, admin guard, CSRF ordering, no token in page source or template, no mutation control, plaintext base URL refused, interface unchanged |
| `reservations` | 101 | strict validation (10 rejection classes), local-state availability, unmanaged-record blocking, TXT not blocking, fail-closed provider error (and not cached), cache reuse, request/duplicate/quota/cancel/expire, approval → `pending_dns` + one queued job, unverifiable record never activating, job cancel/retry, identity scoping, quota admin, two-account race, no web DNS write path |
| `dashboard` | 77 | Task 05: status map equals the schema's CHECK constraint, release rules, timeline built only from recorded events, provisioning lookups carry no secret or operator error, quota view equals the enforced rule, ownership in SQL, POST/CSRF discipline on every page, no page can call the provider |
| `views` (mount-prefix) | 6 | a filesystem path can never become a URL prefix |
| **Total** | **634** | all green, no network, isolated SQLite |
| `tests/dashboard-render.php` | **88 checks** | every dashboard page rendered in-process: statistics match the database, zero/full/over quota, a foreign id yields the not-found page, timeline has no invented timestamp, and a forbidden-needle sweep proves no secret, provider id, operator error, Clerk identifier, filesystem path or stack trace reaches the HTML |
| `tests/http-dashboard.py` | **83 checks** | real HTTP against a seeded database: the auth boundary on all six pages, marker-scoped statistics, cross-account isolation on list/detail/history, junk ids, CSRF (`419`, state unchanged), GET never mutates, releasing an active name refused in words, logout + post-logout denial, a second session unaffected |
| `tests/http-clerk.sh` | **110 checks** | real HTTP: both pages render, no password field, every config key the JS reads, guest redirects, bridge hardening, full handoff, replay, admin denial, webhook signature/replay/idempotency, administrator migration, DNS diagnostics page (admin-only, no token, CSRF, no mutation, unconfigured-safe), availability API (free/reserved/malformed), reserve → stored pending → no longer available, release refused for a foreign id, reservation queue and quota controls (admin-only, CSRF, no token), logout |

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

---

## 8. Final review — endpoint conformance and remaining manual steps

### 8.1 Endpoints and parameters, against Clerk's documented API

| What we call / expose | Documented shape | Our use | Verified how |
|---|---|---|---|
| `POST /v1/clients/verify {token}` | returns a Client with `last_active_session_id` | `ClerkApiClient::verifyClientToken` | shape implemented, **not exercised live** |
| `GET /v1/users/{id}` | a User object | authoritative record after token verification | harness + live-only |
| `GET /v1/users?email_address=&limit=` | array, or `{data: []}` | `findUsersByEmail` for the CLI | both shapes handled |
| `GET /v1/sessions/{id}` | a Session with `status` | periodic revocation check | harness + live-only |
| `POST /v1/sessions/{id}/revoke` | revokes | logout | harness + live-only |
| `POST /v1/sign_in_tokens {user_id, expires_in_seconds}` | a SignInToken | available, **not used** by this flow | unused |
| `GET /v1/me` | instance record | `clerk-check.php` key validation | live-only |
| `GET {frontendApi}/.well-known/jwks.json` | `{keys: [{kid, kty, n, e, alg, use}]}` | RS256 verification | test JWKS + live-only |
| `https://{frontendApi}/npm/@clerk/clerk-js@<ver>/dist/clerk.browser.js` | browser bundle | `assets/js/clerk-auth.js` | URL shape only |
| `session.getToken({template, skipCache})` | `Promise<string \| null>` | the handoff token | types inspected; live-only |
| Webhook headers `svix-id`, `svix-timestamp`, `svix-signature` | HMAC-SHA256 over `{id}.{timestamp}.{body}` | `ClerkWebhook::verify` | implemented + tested |

No endpoint is invented: every path above is from Clerk's documented Backend API
or the svix webhook spec.

### 8.2 Ticket redemption versus token verification — the distinction

These are two different things and the code keeps them apart:

* **Token verification** (`ClerkTokenVerifier::verify`) answers *"did Clerk issue
  this, for this instance, for this subject, and is it current?"* It is
  cryptographic, stateless, and has no notion of a user id supplied by the
  browser: the only identity it produces is the `sub` **inside the signed
  payload**.
* **Ticket redemption** (`ClerkHandoff::redeem`) answers *"may this PHP session
  finish signing in as the account this ticket was minted for?"* It is stateful:
  a hashed row, a TTL, an atomic single-use claim, a re-check that the row is
  still `active`, and a re-check that the row's `clerk_user_id` still matches the
  identity the ticket was issued for.

The browser never supplies a user id or email to either one. The only
browser-supplied identifier anywhere in the flow is the opaque 64-hex ticket, and
that is looked up by hash.

### 8.3 Manual steps that require the live Clerk dashboard or API

None of these can be done from this environment; each is a hard prerequisite for
`READY FOR PRODUCTION`:

1. **Create the `freesub_handoff` JWT template** (Configure → JWT Templates →
   Blank). Without it `session.getToken({template})` resolves to `null` and the
   page reports `template_missing`.
2. **Copy the instance keys** into `.env` and confirm `php database/clerk-check.php`
   exits `0` — this is the only step that proves the secret key is accepted by
   `GET /v1/me` (which needs `api.clerk.com`).
3. **Set `CLERK_AUTHORIZED_PARTIES` and `APP_URL`** to the exact origin browsed
   to; a mismatch makes every bridge request `403 origin_rejected`.
4. **Enable Google** under SSO connections if the OAuth path is required, and
   confirm the redirect URI Clerk shows matches the deployment.
5. **Register the webhook endpoint** (`https://…/clerk/webhook.php`, events
   `user.*` and `session.*`) and store its `whsec_…`; then delete a throwaway user
   and confirm the row is unlinked rather than deleted.
6. **Link each administrator** with `php database/link-clerk.php` (see §4 F12) and
   confirm the admin area opens.
7. **Run the full browser walkthrough** in README → Verifying against Clerk,
   including a second user, to confirm no duplicate rows appear.

Until 1–2 and 7 have been done on a networked machine, the integration is
unproven, however green the local suites are.

### 8.4 Composer dependencies and production claims

* **Runtime requirement: none.** No file under `includes/`, `clerk/`, `auth/` or
  the root entry points loads `vendor/autoload.php` or references
  `Clerk\Backend\`. Identity verification is native RS256 via `openssl`, and the
  Backend API is called with PHP streams. `composer.json` declares
  `clerkinc/backend-php: ^3.0` for deployments that want it, and
  `composer.lock.md` documents the swap. **The official SDK is not used by the
  code in this repository, and this report does not claim that it is.**
* **Test-only keys.** `tests/fixtures/*.pem` are throwaway RSA key pairs, are
  never referenced outside `tests/`, and are unreachable over HTTP (`404` from
  both `router.php` and the `.htaccess` rule for `/tests`). Verified during this
  review.
* **Production mode.** `APP_ENV=production` + `APP_DEBUG=false` hides error
  detail; the end-to-end suite was re-run in that configuration and passed.
  `SECURITY_FORCE_HTTPS=true` (or a trusted `X-Forwarded-Proto: https`) forces
  HTTPS and makes the session cookie `Secure` automatically; TLS itself is the
  web server's job.

---

## 9. Verdict

**`READY FOR LIVE CLERK TEST`** (Task 02), **`DNS ENGINE READY FOR LIVE
CLOUDFLARE TEST`** (Task 03) and **`RESERVATION ENGINE READY FOR LIVE CLOUDFLARE
TEST`** (Task 04) — none is production-ready, for the same single reason: no part
of this system has been exercised against the live services it talks to.

What that means concretely:

* Every control these tasks asked for is implemented, and each one has a
  regression test that would fail if it were removed. 634 unit/integration
  assertions, 88 render assertions, 83 dashboard HTTP checks and 110 end-to-end
  HTTP checks pass, the latter two also with
  `APP_DEBUG=false`.
* Twenty-six defects were found across implementation and review, including one
  **Critical** (an administrator row could be inherited by a web sign-in, §4 F12)
  and two that would have broken sign-in entirely (§4 F1, F11). Seven came from
  Task 04 (§4c), including one that would have blocked every reservation (§4c R4)
  and one that let a reservation trust a stale "free" (§4c R1). All are fixed and
  covered.
* No live Clerk call has been made. That is the only reason this is not
  production-ready: the integration is unproven at the one boundary the local
  suites cannot reach — a real instance minting a real `freesub_handoff` token and
  a real secret key being accepted by the Backend API.

**Task 03 (DNS engine) status, reported separately:**

| Claim | Status |
|-------|--------|
| `MOCK TESTS PASSED` | **yes** — 161 DNS assertions, mocked transport, no credentials |
| `LIVE CONNECTION VERIFIED` | **no** — no Cloudflare credentials here, `api.cloudflare.com` unreachable |
| `DNS MUTATIONS VERIFIED` | **no** — not attempted; no web path writes DNS in this build |

The engine is safe to ship in this state precisely because it cannot write from
the web: `DNS_PROVIDER=none` (or a missing token/zone) leaves every operation
disabled, and the admin page can only read.

**Task 04 (reservation engine) status, reported separately:**

| Claim | Status |
|-------|--------|
| `MOCK TESTS PASSED` | **yes** — 101 reservation assertions (634 total), scripted Cloudflare transport, no credentials |
| `END-TO-END HTTP` | **yes** — 110 checks, including reserve → approve → queue → run, plus 83 dashboard checks |
| `MIGRATIONS APPLIED` | **yes** — fresh install, pre-Task-04 upgrade, duplicate-block path, idempotent re-run; MySQL DDL proven separately |
| `LIVE ZONE READ FOR AVAILABILITY` | **no** — no Cloudflare credentials here |
| `DNS PROVISIONING VERIFIED AGAINST A REAL ZONE` | **no** — deliberately not attempted |

`RESERVATION ENGINE READY FOR LIVE CLOUDFLARE TEST` — for the same reason as
Task 03, and with the same mitigation: no web page can write a record, no
reservation can activate a name, and with `DNS_PROVIDER=none` the queue refuses
to run at all. The failure mode of this build in an unconfigured environment is
"nothing happens and the queue says why", which is the only acceptable one.

For Task 03, the equivalent next step is an operator run of
`FREESUB_DNS_LIVE=1 … php tests/run.php dns-live` followed by
`php database/dns-sync.php` against the real `kharcha.info` zone — both read-only.
Only after that should anyone consider a deliberate create/read-back/delete on a
throwaway name, and only then may `DNS MUTATIONS VERIFIED` be claimed.

For Task 04, the sequence is: set `DNS_PROVIDER=cloudflare`, a token, a zone id
and `DNS_PROVISIONING_A`; confirm the zone with `php database/dns-sync.php
--dry-run`; reserve a throwaway label and approve it; then
`php database/provision.php --run --job=<id>` and verify the record exists in the
dashboard *and* resolves. Only then may `DNS PROVISIONING VERIFIED` be claimed.

To move to `READY FOR PRODUCTION`, complete §8.3 items 1–7 on a networked
machine. Nothing in the code should need to change; if something does, it is
most likely a configuration value (`iss`/`azp` mismatch) or the template name,
and §8.1 plus the troubleshooting table name the specific cause.
