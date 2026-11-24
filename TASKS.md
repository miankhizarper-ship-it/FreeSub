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
- [x] Existing accounts (and their admins) migrate by verified e-mail — ids, roles, FKs and audit history preserved
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
- [x] 7 new suites (`clerk-config`, `clerk-token`, `clerk-sync`, `clerk-handoff`, `clerk-session`, `clerk-webhook`, `clerk-webhook-effects`) — 141 assertions, plus 2 secret-hygiene assertions added to `security`
- [x] Registration, login, logout, invalid/legacy session, expired token, unauthorized dashboard/admin, existing-admin migration, duplicate sync, relationship preservation
- [x] Real RS256 signatures via a committed test-only key pair; Backend API stubbed at the transport seam
- [x] `tests/http-clerk.sh`: 58 end-to-end HTTP checks against the real endpoints
- [x] 266 assertions total, all green

### Known limitations

- [ ] **Not verified against the live Clerk instance** — no secret key and no outbound access to Clerk from the build environment. Run the README checklist before calling this done.
- [ ] Google OAuth and e-mail delivery are Clerk's responsibility and need a real browser to exercise
- [ ] The official SDK is declared but not vendored (Packagist unreachable, vendoring rejected by instruction)

### Defects found and fixed during this delivery

Session fingerprint was not enforced on resolution; the bridge read `$_POST`
while the client posts JSON; `Auth::loginBreakGlass()` accepted no proof of CLI
authorisation; break-glass links could not be redeemed; the signing-key cache
ignored the instance; the schema splitter swallowed tables preceded by a
comment. Details and regression tests are in
[`SECURITY_AUDIT.md` §4](SECURITY_AUDIT.md#4-findings-fixed-during-this-audit).

---

## Task 03 — DNS automation (Next)

- [ ] `CloudflareDnsProvider implements DnsProviderInterface` (token from `.env`, zone id from `domains.zone_reference`)
- [ ] Reconciliation CLI (`php database/dns-sync.php`) that walks `dns_records` where `status = 'pending'`
- [ ] Verify the record through a DNS lookup before flipping a subdomain to `active`
- [ ] Retry/backoff and `last_error` reporting; idempotent create/update/delete
- [ ] Deletion workflow for released subdomains (remove remote records first, then mark `terminated`)
- [ ] Admin UI to inspect per-subdomain DNS state and re-run sync

## Task 04 — Accounts & email (Next)

- [ ] Transactional email for reservation status changes (account e-mail verification and password reset are handled by Clerk)
- [ ] Optional 2FA (TOTP) for administrators (Clerk supports MFA; wire it into the instance policy)
- [ ] Session/device management ("sign out everywhere") — `ClerkApiClient::revokeSession` is already available

## Task 05 — Operations & hardening (Later)

- [ ] Nginx sample configuration with the same denies and headers
- [ ] Structured logging + log rotation; retention policy for `audit_logs`
- [ ] Health endpoint (`/health.php`) for uptime checks
- [ ] Admin audit-log browser with filters and CSV export
- [ ] Bulk operations in the reservation queue
- [ ] Database backup/restore documentation
- [ ] CI pipeline: lint (`php -l`), test suite, optional MySQL service container
- [ ] Screenshot/visual regression tests once a headless browser is available

## Task 06 — Product polish (Later)

- [ ] User-facing API keys for programmatic reservation management
- [ ] Public status page for DNS incidents
- [ ] i18n scaffolding and RTL stylesheet pass
- [ ] Dark mode option for the dashboard
- [ ] Onboarding checklist for first-time users
- [ ] Subdomain "parking" page template so reserved names resolve to something helpful
