# FreeSub — Tasks

Status legend: **Done** · **Next** · **Later**

## Task 01 — Foundation (Done)

### Completed

**Project & infrastructure**
- [x] Modular PHP 8.2+ application: `admin/`, `api/`, `assets/`, `config/`, `database/`, `includes/`, `storage/`, `tests/`
- [x] PDO connection factory with MySQL/MariaDB primary driver and a development-only SQLite fallback
- [x] Environment configuration (`config/app.php`, `config/database.php`, `config/env.php`, `.env.example`)
- [x] CLI installer (`database/install.php`, idempotent, `--fresh` guarded) and admin provisioning (`database/create-admin.php`)
- [x] `php -S` router with correct 404s, dot-file and internal-directory blocking (`router.php`)
- [x] Styled `419` (expired CSRF token) page served through the normal layout

**Database**
- [x] `users`, `domains`, `subdomains`, `dns_records`, `reservations`, `audit_logs`, `abuse_reports`
- [x] Primary keys, foreign keys, unique constraints, indexes, `created_at`/`updated_at`
- [x] Generated `extension` column, per-domain quota, `zone_reference` (never a token)
- [x] `kharcha.info` seeded idempotently
- [x] SQLite mirror schema for local development and tests

**Frontend**
- [x] Responsive landing page: nav, hero, live search, extensions, how it works, features, FAQ, CTA, footer
- [x] Design system in `assets/css/app.css` (tokens, components, 8 breakpoints, reduced-motion, print)
- [x] Vanilla JS: Lucide hydration, sticky header, scroll reveal, debounced search with `AbortController`, password toggle + strength meter, confirm dialogs
- [x] Self-hosted Bootstrap 5.3, Lucide 1.78 and Plus Jakarta Sans (no CDN)
- [x] Extensions page, abuse form, 404/403 pages, setup fallback page, favicon/OG assets, `robots.txt`, `sitemap.xml`
- [x] Accessibility pass: skip link, landmarks, labels, focus rings, semantic headings, no inline handlers

**Security**
- [x] `password_hash()` / `password_verify()` / rehash-on-login, uniform failure timing
- [x] CSRF tokens with `hash_equals()` on every POST; `419` on failure
- [x] Prepared statements everywhere, emulation disabled, strict SQL mode
- [x] Output escaping helper used project-wide
- [x] Hardened sessions (strict mode, HttpOnly, SameSite, Secure on HTTPS, idle timeout, fingerprint, rotation)
- [x] Rate limiting: login, registration, abuse, API
- [x] Security headers + `.htaccess` rules disabling directory listing and blocking internals/dot files
- [x] Audit logging for auth, subdomain and admin actions
- [x] No secrets in the database or front-end code

**Features**
- [x] Registration, login, logout (POST-only)
- [x] Dashboard with quota, reservation form, subdomain list, reservation history, cancellation/release
- [x] Availability API (`api/check.php`) with validation, suggestions and rate limiting
- [x] Admin: overview metrics, reservation queue (approve → `pending_dns` only), root-domain CRUD-lite, user suspension, abuse triage
- [x] Multi-root-domain support via data only

**Tests & docs**
- [x] `tests/run.php`: 112 assertions across schema, security, auth, subdomains, audit, views
- [x] `tests/http-journeys.php`: 56 end-to-end HTTP assertions
- [x] README with setup, configuration, architecture, security model and troubleshooting
- [x] All 168 assertions green on PHP 8.5 / SQLite; MySQL DDL verified against MariaDB 13.1

### Deliberately not implemented

- Cloudflare API calls (interface + null driver only)
- Wildcard DNS assumptions
- Customer hosting: File Manager, PHP accounts, MySQL hosting, email
- Fake provisioning or "successful registration" states

---

## Task 02 — Clerk authentication (Done, pending live verification)

Native password authentication was replaced with Clerk. The credentials
(registration, sign-in, Google OAuth, e-mail verification, password recovery)
now live entirely in Clerk; FreeSub stores only the link to a Clerk user id.

### Completed

**Integration**
- [x] `clerk-js` (official browser build) mounted on our own pages — no React, no Next.js
- [x] Privileged same-origin bridge (`clerk-bridge.php`): POST-only, Origin allowlist, per-page channel/state, IP rate limit, `{ok, token, redirect}` response
- [x] Ticket handoff: browser token → verified → single-use, hashed, TTL ticket → CSRF form → session (`auth/handoff.php`)
- [x] Native RS256 verification (`ClerkTokenVerifier`): `alg` pinning, `kid`, signature, `exp`, `nbf`, `iss`, `azp`, `sub`, JWKS cache + rotation
- [x] Backend API client (`ClerkApiClient`): `getUser`, `getSession`, `me`, `verifyClientToken`, `createSignInToken`, `revokeSession`, `findUsersByEmail`
- [x] `clerk/webhook.php`: svix HMAC verification, replay window, `user.*` and `session.*` handling, idempotent
- [x] Logout: POST + CSRF, Clerk session revoked server-side, local session destroyed

**Data**
- [x] `users.clerk_user_id` (unique), `username`, `avatar_url`, `auth_provider`, `last_login_at`; `password_hash` nullable
- [x] `clerk_handoffs` (hashed tokens only) and `clerk_sessions` (mirror)
- [x] `database/migrate.php`: guarded steps, `--status`, transactional SQLite rebuild with row-count verification
- [x] Existing user accounts link automatically by verified e-mail — ids, roles, FKs and audit history preserved
- [x] Administrator rows are **never** linked by a web sign-in (takeover guard); the operator links them with `database/link-clerk.php`, which requires a Clerk-verified address that matches the row
- [x] No duplicate rows: unique index + transactional upsert + explicit conflict handling

**Authorization**
- [x] Roles never come from Clerk metadata, token claims or form input
- [x] New users are always `user`; existing admins stay admins; no auto-promotion
- [x] Protected user and admin dashboards; unauthenticated requests redirect to `login.php`
- [x] Suspended/deleted/re-linked accounts lose access on the next request; client fingerprint enforced

**Removal & break-glass**
- [x] Web password login and registration deleted (no password field on any route)
- [x] CLI-only break-glass (`database/break-glass.php`): `CLERK_BREAK_GLASS=true`, admin only, local password when present, single-use ticket, TTL, audited
- [x] `Auth::loginBreakGlass()` requires a CLI handshake value, so it cannot be called into existence by other code

**Operations & docs**
- [x] `database/clerk-check.php` (exit 0/2/3), `database/link-clerk.php` (`--list`, `--unlinked`, `--email`, `--clerk-id`)
- [x] `.env.example` with every `CLERK_*` key documented; secret stays server-side
- [x] `composer.json` declaring `clerkinc/backend-php: ^3.0` + `composer.lock.md` explaining the native verifier and the SDK swap
- [x] README: flow diagram, verification table, reconciliation rules, webhook setup, break-glass, upgrade path, live-verification checklist
- [x] `SECURITY_AUDIT.md`: controls, 10 fixed findings, 5 open risks, test evidence

**Tests**
- [x] 8 new suites (`clerk-config`, `clerk-token`, `clerk-sync`, `clerk-privilege`, `clerk-handoff`, `clerk-session`, `clerk-webhook`, `clerk-webhook-effects`) — 161 assertions
- [x] Registration, login, logout, invalid/legacy session, expired token, unauthorized dashboard/admin, existing-admin migration, duplicate sync, relationship preservation
- [x] Real RS256 signatures via a committed test-only key pair; Backend API stubbed at the transport seam
- [x] `tests/http-clerk.sh`: 61 end-to-end HTTP checks against the real endpoints
- [x] 287 assertions total, all green

### Known limitations

- [ ] **Not verified against the live Clerk instance** — no secret key and no outbound access to Clerk from the build environment. Run the README checklist before calling this done.
- [ ] Google OAuth and e-mail delivery are Clerk's responsibility and need a real browser to exercise
- [ ] The official SDK is declared but not vendored (Packagist unreachable, vendoring rejected by instruction)

### Defects found and fixed during this delivery

An administrator row could be inherited by a web sign-in (Critical); session
fingerprint was not enforced on resolution; the bridge read `$_POST` while the
client posts JSON; the Clerk SDK URL was never resolvable; `config()` mutated its
own cache so overrides leaked; `Auth::loginBreakGlass()` accepted no proof of CLI
authorisation; break-glass links could not be redeemed; the signing-key cache
ignored the instance; a JWKS outage was reported as a 401; the schema splitter
swallowed tables preceded by a comment. Details and regression tests are in
[`SECURITY_AUDIT.md` §4](SECURITY_AUDIT.md#4-findings-fixed-during-this-audit).

---

## Task 03 — DNS engine (Done, pending live verification)

The integration engine is built and covered by mocked tests. What is **not**
done — deliberately — is turning it on for users: public subdomain registration
stays disabled, and no subdomain is marked `active` by this work.

### Completed

- [x] `CloudflareDnsProvider implements DnsProviderInterface`; the original five
      methods keep their signatures, the record API
      (`listRecords`/`findRecord`/`createRecord`/`updateRecord`/`deleteRecord`/
      `verifyZone`) is additive
- [x] `CloudflareApiClient`: cURL with connect + request timeouts, HTTP status and
      `success`/`errors` parsing, `CloudflareApiException` with a stable
      `reason()`, structured `DnsLog` that scrubs the token
- [x] Retry policy: reads retry on 429/5xx/transport failure with exponential
      backoff and `Retry-After`; **writes are never retried**
- [x] Ambiguous create handling: the zone is re-read and a landed record is
      adopted instead of duplicated (`DnsManager::createForSubdomain`)
- [x] Zone verification against `DNS_ROOT_DOMAIN` before any operation, incl.
      `zone_mismatch` / `zone_pending` / `not_found` / `auth`
- [x] Record rules: A/AAAA/CNAME only, in-zone names, reserved and all-numeric
      labels refused, wildcards refused, public-only IPv4/IPv6 (RFC1918,
      loopback, link-local/metadata, CGNAT, multicast, TEST-NET, ULA,
      IPv4-mapped), CNAME target rules, supported TTLs, never proxied
- [x] Additive migration `2026_11_002`: `dns_records.domain_id` (FK, backfilled),
      `dns_records.last_synced_at`, two indexes — guarded in `database/migrate.php`
      for both MySQL and SQLite
- [x] Lifecycle in `DnsManager`: create (validate → conflict → zone → pending row
      → provider → persist id → audit), update, delete (owned id only, soft delete,
      audited), sync (report only — never deletes or recreates)
- [x] Admin page `admin/dns.php`: connection status, verified zone, record
      count, recent sync errors, operation history, read-only connection test and
      comparison; no token, no headers, no mutation control
- [x] Read-only CLI `database/dns-sync.php` (`--json`, `--domain`, no write flag)
- [x] Tests: `dns-validate`, `dns-client`, `dns-lifecycle`, `dns-security`
      (161 assertions) plus an optional `dns-live` read-only suite
- [x] End-to-end admin checks in `tests/http-clerk.sh` (page guards, CSRF, no
      token in HTML, unconfigured behaviour)

### Deliberately not implemented

- Public subdomain registration and any user-facing DNS write path
- Flipping a subdomain to `active` (needs a verified record *and* an owner
  decision to launch)
- Wildcard records, nameserver changes, proxying, `MX`/`TXT`/`CAA` management
- Automatic repair: sync reports discrepancies for an operator, it never applies
  them
- Bulk deletion of any kind

### Verification status

| Claim | Status |
|-------|--------|
| Mocked test suites | **passed** (Task 03's 161 DNS assertions; 634 in the full run after Task 05) |
| Live Cloudflare connection | **not verified** — no credentials in the delivery environment |
| DNS mutations against a real zone | **not verified** — not attempted |

To close the last two: follow [README → Verifying the integration](README.md#verifying-the-integration).

## Task 03b — DNS provisioning workflow (Done in Task 04)

Delivered as part of the reservation engine below:

- [x] `provisioning_jobs` queue plus the `ProvisioningQueue` service
- [x] CLI worker `database/provision.php` that flips a subdomain to `active`
      only after the record reads back from the zone and the value matches
- [x] Atomic job claiming, bounded backoff, stale-runner recovery, visible
      failures (`last_error`) and safe cancellation
- [x] Admin controls per job: run / retry / cancel, with counts and last error

Still open:

- [ ] Deletion workflow for released subdomains (remote record first, then
      `terminated`), with the same ownership rules — active names are released
      through support today
- [ ] Decide whether an operator may re-run sync with `--apply` for the
      `missing`/`modified` classes only

## Task 04 — Subdomain availability & reservation engine (Done, pending live verification)

### Completed

**Search & validation**
- [x] `SubdomainLabel`: strict normalisation and validation (length, charset,
      edge/double hyphens, wildcard, apex, reserved list + patterns, punycode,
      non-ASCII, numeric-only, wrong root domain). Rejects; never silently rewrites
- [x] `AvailabilityEngine`: ordered checks over `subdomains`, `dns_records`, live
      reservations and the authoritative zone, with statuses
      `available|taken|reserved|pending|invalid|unknown|blocked` and suggestions
- [x] `AvailabilityCache`: per-`(zone, name)` disk cache with TTLs; **errors are
      never cached**
- [x] Fail closed: an unreachable provider yields `unknown` (and HTTP `503` from
      `api/check.php`), never "available"
- [x] Public API and dashboard search share the same engine, so they cannot disagree

**Reservations**
- [x] `ReservationService`: create (re-validate, re-check without cache, quota,
      transaction, friendly duplicate handling), cancel (ownership in the WHERE),
      approve, reject, expiry sweep, dashboard buckets, history
- [x] `reservations.live_guard` + unique index: one live request per
      `(domain, label)`, unlimited history
- [x] Configurable TTL (`RESERVATION_TTL_HOURS`, default 168h) with an
      administrator-triggered sweep, idempotent
- [x] `SubdomainQuota`: per-user override → per-domain → platform default,
      count includes pending reservations, bounded and audited

**Provisioning**
- [x] `ProvisioningQueue`: idempotent enqueue per subdomain, atomic claim,
      run, retry (resets attempts, deliberately), cancel (refuses a completed
      job), bounded backoff, `last_error` surfaced to admins
- [x] Activation happens in exactly one place, after a read-back whose value is
      compared to the stored record
- [x] `database/provision.php`: dry run by default, `--status`, `--job`, `--limit`,
      `--actor`; exits non-zero when a job fails
- [x] **Admin/CLI triggered only** — no cron entry, no timer, no write on page load

**UI**
- [x] Dashboard: inline availability for the reserve form, quota meter, pending /
      approved / rejected buckets, history, cancel, release (pending only)
- [x] Admin reservation queue: filters, search, expiry sweep, approve/reject with
      a reason, provisioning queue with run/retry/cancel and error visibility
- [x] Admin users: per-account quota with live usage

**Data & safety**
- [x] Additive migration `2026_11_003` (+ `provisioning_jobs`): `users.subdomain_quota`,
      `reservations.live_guard` with backfill, expiry index, unique live-label key,
      `provisioning_jobs` table — SQLite and MySQL, idempotent, existing rows and
      foreign keys preserved
- [x] All scoping from the server-side Clerk identity; no request parameter
      selects an account
- [x] Tests: `reservations` suite (101 assertions) plus 23 new end-to-end HTTP checks

### Verification status

Separate lines, because they are very different claims:

| Claim | Status |
|-------|--------|
| Mocked test suites | **passed** — 634 assertions in `php tests/run.php` (101 of them Task 04) |
| End-to-end HTTP journeys | **passed** — 110 checks in `tests/http-clerk.sh` |
| Migration on a pre-Task-04 database | **passed** — SQLite fresh, legacy upgrade, duplicate-block and idempotent re-run; MySQL DDL proven 9/9 separately |
| Live Cloudflare read used for availability | **not verified** — no credentials in the delivery environment |
| DNS provisioning against a real zone | **not verified** — deliberately not attempted |

### Deliberately not implemented

- Automatic/scheduled provisioning (would make public DNS writes automatic)
- Wildcard DNS, nameserver changes, proxying
- Customer hosting of any kind (no file manager, PHP, MySQL or mail hosting)
- Releasing an `active` name from the browser (would orphan a live record)

## Task 05 — User dashboard & subdomain management (Done)

The signed-in product, built on the Task 01–04 backend. No new framework, no new
dependency, no business rule re-implemented in a template.

**Shell**
- [x] `includes/views/layouts/dashboard.php` — sidebar ≥992px, Bootstrap off-canvas <992px, one nav definition
- [x] Top bar with breadcrumb-style page title and a "New subdomain" action
- [x] Account block + POST sign-out in the sidebar foot and the off-canvas
- [x] `partials/status-badge.php` — one badge component, label always rendered as text

**Pages**
- [x] Overview — display name, e-mail, account status, four DB-backed statistics, quota meter, per-extension breakdown, recent activity, honest empty state
- [x] My subdomains — table on desktop, cards on mobile, filter by canonical status, released names read-only
- [x] Subdomain detail — basic info, DNS/provisioning state, timeline built only from recorded events, safe failure copy, release panel
- [x] Reservations — every request with outcome, review and expiry timestamps, linked subdomain, filter
- [x] Find a subdomain — availability check via the existing engine, reservation form, quota context
- [x] Account — profile, quota, security facts, sign-out; explains that Clerk owns credentials

**Backend additions (thin, reuse-first)**
- [x] `Subdomain::provisioningFor()` / `provisioningForMany()` — user-safe job state, constant query count for a list
- [x] `Subdomain::forUser($id, $limit, $includeTerminated)` — released names are opt-in history
- [x] `SubdomainQuota::overview()` / `byDomain()` — the dashboard's view of the rule the reservation service enforces
- [x] `includes/services/Dashboard/DashboardStatus.php` — canonical state → words, timeline builder, release rules

**Fixes found while building it**
- [x] `app_base_path()` could emit a filesystem path (leaking the install directory into asset URLs) when `SCRIPT_NAME` is absolute; extracted to a pure `app_base_path_from()` with two guards and six tests
- [x] `Auth::user()` relied on a caller having loaded `models/User.php` first; it now requires it explicitly
- [x] `is_active_path()` matched raw prefixes (`/subdomain.php` matched `/subdomain.php.bak`); now matches on segment boundaries

**Security**
- [x] Ownership in SQL on every read; a foreign id is a `404` identical to a missing one
- [x] Digit-validated ids; no cast of arbitrary input
- [x] CSRF on every mutation, POST only, GET never mutates
- [x] Release restricted to `pending_dns` / `failed` / `suspended`; an active name explains the support path
- [x] No provider token, record id, operator error, Clerk identifier or stack trace in any rendered page

**Tests**
- [x] `tests/run.php` → 634 assertions (was 549); new `dashboard` suite has 77
- [x] `tests/dashboard-render.php` → 88 assertions across eight rendered pages
- [x] `tests/http-dashboard.py` → 83 HTTP checks (auth boundary, isolation, CSRF, release, logout)
- [x] `tests/http-clerk.sh` → 110 checks (was 94), green under `APP_DEBUG=true` and `false`
- [x] `tests/dashboard.sh` runs all of it in one command

| Claim | Status |
|-------|--------|
| Mocked test suites | **passed** — 634 assertions (`dashboard`: 77) |
| Render harness | **passed** — 88 assertions |
| Dashboard HTTP checks | **passed** — 83, twice (debug on/off) |
| End-to-end Clerk flow | **passed** — 110 checks, twice |
| Responsive at 320/375/768/1024/1440 | **not visually verified** — no browser binary is reachable here; verified structurally instead |
| Live Cloudflare / live Clerk rendering of real data | **not verified** — no credentials |

### Deliberately not implemented

- Client-side provisioning or any "refresh DNS" button
- An e-mail/name editor that would contradict Clerk (or let someone claim an unverified address)
- Password management of any kind
- Releasing an `active` name from the browser (would orphan a live record)
- A second layout, a CSS framework, or any JavaScript framework

## Task 06 — Accounts & email (Next)

- [ ] Transactional email for reservation status changes (account e-mail verification and password reset are handled by Clerk)
- [ ] Optional 2FA (TOTP) for administrators (Clerk supports MFA; wire it into the instance policy)
- [ ] Session/device management ("sign out everywhere") — `ClerkApiClient::revokeSession` is already available

## Task 07 — Operations & hardening (Later)

- [ ] Nginx sample configuration with the same denies and headers
- [ ] Structured logging + log rotation; retention policy for `audit_logs`
- [ ] Health endpoint (`/health.php`) for uptime checks
- [ ] Admin audit-log browser with filters and CSV export
- [ ] Bulk operations in the reservation queue
- [ ] Database backup/restore documentation
- [ ] CI pipeline: lint (`php -l`), test suite, optional MySQL service container
- [ ] Screenshot/visual regression tests once a headless browser is available

## Task 08 — Product polish (Later)

- [ ] User-facing API keys for programmatic reservation management
- [ ] Public status page for DNS incidents
- [ ] i18n scaffolding and RTL stylesheet pass
- [ ] Dark mode option for the dashboard
- [ ] Onboarding checklist for first-time users
- [ ] Subdomain "parking" page template so reserved names resolve to something helpful
