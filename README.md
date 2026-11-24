# FreeSub

Free, memorable subdomains for side projects, portfolios and prototypes — built with **PHP 8.2+, MySQL/MariaDB, PDO, Bootstrap 5, vanilla JavaScript and Lucide icons**.

FreeSub lets registered users search for a subdomain on a root domain the platform owns (initially **kharcha.info**) and reserve it. This repository is the **foundation**: schema, authentication, dashboard, admin tooling, public landing page and a DNS-provider abstraction that is deliberately **not wired to Cloudflare yet**.

> **Honesty by design:** nothing in this build creates DNS records. New names are stored as `pending_dns` and the UI says so. No fake "registration successful" screens, no pretend provisioning.

---

## Contents

- [What is implemented](#what-is-implemented)
- [Requirements](#requirements)
- [Local setup](#local-setup)
- [Configuration](#configuration)
- [Database](#database)
- [Project structure](#project-structure)
- [Authentication (Clerk)](#authentication-clerk)
- [Security model](#security-model)
- [How root domains work](#how-root-domains-work)
- [Tests](#tests)
- [Remaining work](#remaining-work)
- [Troubleshooting](#troubleshooting)

---

## What is implemented

**Public site**

- Polished, responsive SaaS-style landing page: sticky navigation, hero with live subdomain search, extensions grid, how-it-works, features, FAQ (native `<details>`), CTA and a rich footer.
- Live availability search (debounced `fetch`, stale requests aborted) against `api/check.php`, with alternative suggestions when a name is taken.
- Public extension listing (`extensions.php`) generated from the database — never hard-coded.
- Abuse report form with validation, rate limiting and audit logging.
- Accessible markup: skip link, landmarks, labelled controls, visible focus states, `prefers-reduced-motion` support, no inline event handlers (CSP-friendly).

**Accounts**

- Registration with server-side validation, `password_hash()` (bcrypt, cost 12), automatic rehash on login, uniform failure messages and timing.
- Login with per-account lockout after repeated failures plus IP/email rate limiting, session id rotation, and a user-agent fingerprint check.
- POST-only, CSRF-protected logout.
- Sessions: `HttpOnly`, `SameSite`, `Secure` when on HTTPS, strict mode, custom save path, idle timeout.

**Dashboard**

- Quota meter, pending-request count, extension count, account summary.
- Reservation form (validates, re-checks availability inside a transaction, enforces quota).
- Subdomain list with explicit statuses (`pending_dns`, `active`, `suspended`, `failed`, `terminated`), reservation history and cancellation.

**Admin**

- Overview with platform metrics and queue snapshots.
- Reservation queue: approve (creates a `pending_dns` subdomain, never "active"), reject, filter by status.
- Root-domain management: add apex domains (created as `draft` so nothing is offered before DNS is ready), toggle availability, change registration status.
- User directory with search and suspend/activate (self-suspension blocked).
- Abuse triage: reviewing → resolved/dismissed with a resolution note.
- Every admin mutation is written to `audit_logs`.

**Data layer**

- Seven tables with primary keys, foreign keys, unique constraints, indexes, `created_at`/`updated_at` (with `ON UPDATE CURRENT_TIMESTAMP` on MySQL).
- Multi-domain ready: extensions are rows, the UI and API read from the table, adding a domain needs no code change.
- Generated `extension` column (MySQL) derived from `root_domain`.
- DNS records stored as *desired state* only; `dns_records.provider_record_id` is reserved for the future Cloudflare sync.

**Security**

- PDO prepared statements everywhere, `ATTR_EMULATE_PREPARES = false`, strict SQL mode, exceptions on error.
- CSRF tokens on every state-changing form, verified with `hash_equals()`; failures return `419`.
- Output escaping through a single `e()` helper (`ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5`).
- Security headers (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, COOP, CSP via `.htaccess`), `X-Powered-By` removed.
- `.htaccess` rules block `config/`, `includes/`, `storage/`, `database/`, dot files and backups, with matching `403/404` handler and no directory listing.
- Secrets live only in `.env`; the DNS abstraction reads its token from the environment, and no schema column stores a token.

---

## Requirements

| Component | Minimum | Notes |
|-----------|---------|-------|
| PHP | 8.2 | 8.3 / 8.4 recommended. Extensions: `pdo_mysql`, `mbstring`, `json`, `session`, `openssl` |
| Database | MySQL 8.0 or MariaDB 10.6 | SQLite is supported for local development only |
| Web server | Apache 2.4 with `mod_rewrite`/`mod_headers`, or Nginx | `php -S` works with the bundled router |
| Composer / Node | Not required to run | Bootstrap, Lucide and the font are vendored in `assets/`. `composer install` is only needed if you want the official Clerk SDK instead of the native verifier |
| Clerk account | Required | Free development instance; see [Authentication (Clerk)](#authentication-clerk) |

---

## Local setup

```bash
# 1. Get the code
git clone <your-repository-url> freesub && cd freesub

# 2. Environment
cp .env.example .env
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"   # paste into APP_KEY

# 3. Database (MySQL / MariaDB)
mysql -u root -p -e "CREATE DATABASE freesub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p < database/schema.sql

#    ...or let the installer do it (reads .env):
php database/install.php

# 4. Fill in DB_* in .env, then create an administrator
#    (prompts for a hidden password; --password=... for CI)
php database/create-admin.php "Your Name" you@example.com

# 5. Run it
php -S localhost:8000 router.php
```

Open <http://localhost:8000>. Sign in with the administrator you just created and visit `/admin/index.php`.

**Permissions.** The web server user must be able to write to `storage/logs`, `storage/cache` and `storage/sessions` (and `storage/database.sqlite` if you use SQLite):

```bash
chmod -R 775 storage
```

**Subdirectory installs** work too — URLs are generated from the detected base path, so `https://example.com/freesub/` needs no configuration changes.

**SQLite quick start (development only).** Set `DB_DRIVER=sqlite` and `DB_SQLITE_PATH=storage/database.sqlite`, then run `php database/install.php`, which imports `database/schema.sqlite.sql`. Production must use MySQL/MariaDB — the SQLite schema exists so the app and tests can run without a database server, and a few MySQL-only features (generated column, `ON UPDATE CURRENT_TIMESTAMP`, JSON type) are emulated.

---

## Configuration

Everything is read from the environment (`.env` or real env vars). The full list with comments is in [`.env.example`](.env.example).

| Key | Purpose |
|-----|---------|
| `APP_ENV`, `APP_DEBUG` | `local` + `true` shows errors; **production must be `APP_ENV=production`, `APP_DEBUG=false`** |
| `APP_URL` | Absolute base URL (no trailing slash), e.g. `https://freesub.example.com`. Leave empty to auto-detect from the request — convenient locally, but set it explicitly in production so links, emails and redirects never depend on the `Host` header |
| `APP_KEY` | 32+ random characters; keep secret |
| `APP_TIMEZONE` | Storage is always UTC; this only affects display |
| `DB_DRIVER`, `DB_*` | `mysql` for production, `sqlite` for local experiments |
| `SESSION_*` | Cookie name, idle lifetime (minutes), `Secure` handling, `SameSite` |
| `SECURITY_FORCE_HTTPS`, `SECURITY_HSTS` | Redirect to HTTPS / emit HSTS once TLS is verified |
| `TRUSTED_PROXIES` | Comma-separated CIDRs; only then are `X-Forwarded-*` trusted |
| `RATE_LIMIT_*` | Login and registration throttles |
| `SUBDOMAIN_*` | Label length limits and per-domain default quota |
| `CLERK_PUBLISHABLE_KEY` | `pk_test_…` / `pk_live_…`. Public by design; the frontend API host is decoded from it |
| `CLERK_SECRET_KEY` | `sk_test_…` / `sk_live_…`. **Server-only**, never rendered or logged. A secret key passed as the publishable key is rejected |
| `CLERK_AUTHORIZED_PARTIES` | Comma-separated origins allowed to complete sign-in (defaults to `APP_URL`) |
| `CLERK_WEBHOOK_SECRET` | `whsec_…` from the Clerk webhook endpoint; without it `/clerk/webhook.php` refuses everything |
| `CLERK_FRONTEND_API`, `CLERK_JWKS_URL`, `CLERK_BACKEND_API` | Optional overrides (CNAME / proxy / test harness). Empty = derived from the publishable key |
| `CLERK_JWT_TEMPLATE` | JWT template name used for the handoff token (default `freesub_handoff`) |
| `CLERK_SESSION_RECHECK` | Seconds between server-side session-liveness checks (0 disables) |
| `CLERK_BREAK_GLASS` | `true` enables the CLI-only offline administrator path |
| `BREAK_GLASS_TTL` | Lifetime of a break-glass link in seconds (default 300) |
| `DNS_PROVIDER`, `CLOUDFLARE_*` | Reserved for the DNS integration (unused in this build) |

`config/app.php`, `config/database.php` and `config/env.php` only read these values; none of them may be served over HTTP (blocked by `.htaccess`).

---

## Database

`database/schema.sql` (MySQL/MariaDB) creates:

| Table | Purpose | Notable constraints |
|-------|---------|---------------------|
| `users` | Accounts | `UNIQUE(email)`, role/status enums, lockout fields |
| `domains` | Root domains owned by the platform | `UNIQUE(root_domain)`, generated `extension`, `dns_provider`, `zone_reference`, per-domain `subdomain_quota` |
| `reservations` | A user's request for a label | FKs to `users` (cascade) and `domains` (restrict), reviewed-by FK |
| `subdomains` | Provisioned hostnames | `UNIQUE(fqdn)`, `UNIQUE(domain_id, label)`, `UNIQUE(reservation_id)` |
| `dns_records` | Desired DNS state per subdomain | `UNIQUE(subdomain_id, type, name)`, provider record id |
| `audit_logs` | Append-only trail | FKs with `ON DELETE SET NULL`, JSON `context` |
| `abuse_reports` | Public reports | FK to `subdomains`, reviewer FK, status workflow |

`kharcha.info` is inserted as the initial root domain; the statement is idempotent (`ON DUPLICATE KEY UPDATE`).

Adding a root domain is a plain `INSERT`:

```sql
INSERT INTO domains (root_domain, label, description, dns_provider,
                     registration_status, is_active, is_public, subdomain_quota, sort_order)
VALUES ('example.dev', 'example.dev', 'Short and modern.', 'none', 'ready', 1, 1, 5, 1);
```

Or use **Admin → Root domains**, which inserts the row as `draft` so it is never offered before its zone is ready.

---

## Project structure

```
freesub/
├── admin/                  Admin screens (overview, domains, reservations, users, abuse)
├── api/check.php           Public JSON availability endpoint (read-only)
├── assets/
│   ├── css/app.css         Design system (tokens, components, responsive rules)
│   ├── js/app.js           Icons, header, reveal, live search, password UX
│   ├── vendor/             Bootstrap CSS/JS + Lucide (vendored, no CDN)
│   ├── fonts/              Self-hosted Plus Jakarta Sans
│   └── images/             Favicon, logo mark, social cover
├── config/
│   ├── app.php             Application config (env → array)
│   ├── database.php        PDO config
│   └── env.php             Tiny .env loader
├── database/
│   ├── schema.sql                 MySQL/MariaDB schema + seed
│   ├── schema.sqlite.sql          SQLite mirror for local development/tests
│   ├── migrations/
│   │   └── 2026_11_001_clerk_identity.sql
│   ├── install.php                CLI installer (idempotent; --fresh to reset)
│   ├── migrate.php                Applies (or --status shows) the Clerk migration
│   ├── create-admin.php           CLI administrator provisioning
│   ├── link-clerk.php             Attach/list Clerk identities for existing accounts
│   ├── clerk-check.php            Configuration + connectivity check (no secrets printed)
│   └── break-glass.php            CLI-only offline administrator link
├── includes/
│   ├── bootstrap.php       Error policy, autoloader, headers, session, wiring
│   ├── auth.php            Sessions, guards, Clerk login, break-glass gate
│   ├── database.php        PDO factory + query helpers
│   ├── functions.php       Escaping, URLs, CSRF, flash, audit, rate limiting, views
│   ├── validation.php      Label, profile and abuse validators
│   ├── models/             User, Domain, Reservation, Subdomain, DnsRecord, AbuseReport
│   ├── services/
│   │   ├── Clerk/          ClerkConfig, ClerkTokenVerifier, ClerkApiClient,
│   │   │                   ClerkHandoff, ClerkWebhook, ClerkToken
│   │   ├── DnsProvider.php DnsProvider (interface + null driver)
│   │   └── SubdomainService.php
│   └── views/              Layouts, partials (incl. clerk-auth.php), pages, admin screens
├── clerk/
│   └── webhook.php         Clerk webhook receiver (svix signature verified)
├── storage/                Logs, cache (incl. JWKS cache), sessions (git-ignored)
├── tests/
│   ├── run.php             Test runner (all suites listed under Tests)
│   ├── clerk-suite.php     Clerk suites (verification, sync, handoff, sessions, webhooks)
│   ├── support/ClerkTestHarness.php   RSA signing + stubbed Backend API
│   ├── fixtures/           Test-only RSA key pairs (never used for anything real)
│   ├── http-journeys.php   Foundation HTTP journeys via cURL
│   ├── http-clerk.sh       End-to-end Clerk flow over real HTTP
│   └── router-e2e.php      Router used only by the end-to-end run
├── index.php               Landing page
├── login.php register.php logout.php
├── clerk-bridge.php        Privileged same-origin handoff endpoint
├── auth/handoff.php        Redeems a handoff ticket into a session
├── dashboard.php
├── extensions.php abuse.php 404.php
├── admin/ api/
├── router.php              Router for `php -S` (404 handling, dot-file blocking)
├── composer.json           Declares clerkinc/backend-php for production (see composer.lock.md)
├── .env.example .htaccess README.md robots.txt sitemap.xml
```

**Layering.** Entry points (`*.php` in the project root, `admin/`, `api/`) only orchestrate: validate → call a model/service → redirect or render. All SQL lives in `includes/models/`, business rules in `includes/services/`, presentation in `includes/views/`. There is no framework; the single declared
Composer dependency is the official Clerk PHP SDK, documented in
[`composer.lock.md`](composer.lock.md) — the shipped code does not require
`vendor/` to be present.

---

## Authentication (Clerk)

Every credential lives in Clerk. FreeSub never sees a password and stores no
Clerk credentials — it only stores the link between a local account and a Clerk
user id.

### The sign-in flow (ticket handoff)

```
browser                                  FreeSub server                 Clerk
  |  GET /login.php                          |                             |
  |------------------------------------------>|                             |
  |   page embeds {publishableKey, channel,   |   ClerkHandoff::openChannel |
  |   state, bridgeUrl, handoffTemplate}      |   (channel+state in session)|
  |<------------------------------------------|                             |
  |  Clerk.load() + mountSignIn(...)          |                             |
  |------------------------------------------------------------------------>|  sign in / OAuth /
  |                                           |                             |  e-mail code / reset
  |<------------------------------------------------------------------------|
  |  session.getToken({template: 'freesub_handoff', skipCache: true})       |
  |------------------------------------------------------------------------>|  RS256 session token
  |  POST /clerk-bridge.php  {handoffToken, channel, state}                 |
  |  (same origin, no cookies needed)         |                             |
  |------------------------------------------>|  verify signature, kid, alg,|
  |                                           |  iss, exp, nbf, azp, sub    |
  |                                           |  + GET /v1/users/{sub}      |
  |                                           |  -> User::syncFromClerk()   |
  |   {ok, token, redirect}  (single-use,60 s)|                             |
  |<------------------------------------------|                             |
  |  POST /auth/handoff.php {handoff_token,_token}  (CSRF protected form)   |
  |------------------------------------------>|  ClerkHandoff::redeem()     |
  |   Set-Cookie, 302 /dashboard.php          |  Auth::loginWithClerk()     |
  |<------------------------------------------|                             |
```

Why a handoff instead of trusting Clerk's `__session` cookie:

* PHP never has to read a cookie it did not set, or parse a PSR-7 request.
* The browser token is used **once**, minutes at most, and never stored: only
  `sha256(token)` reaches the database, the ticket is single-use, and the row is
  consumed atomically (`UPDATE … WHERE state='pending'`), so a replay cannot win
  a race.
* The `channel` + `state` pair is minted per login page and kept in the server
  session, so a third-party page cannot drive the bridge and the ticket cannot
  be redeemed in someone else's browser.
* Nothing sensitive is ever in a URL: the ticket travels in a POST body or a
  hidden form field.

`login.php` and `register.php` only choose which Clerk component to mount; they
post no credentials of their own. Logout is a POST with CSRF, revokes the Clerk
session server-side (best effort) and then ends the local session.

### What is verified, and where

| Check | Where | Notes |
|-------|-------|-------|
| Signature (RS256 over the JWKS key for `kid`) | `ClerkTokenVerifier` | `openssl_verify` against `<frontendApi>/.well-known/jwks.json`, cached on disk with a TTL and refetched once on an unknown `kid` |
| `alg` pinning | `ClerkTokenVerifier` | Only `RS256`; `none` and HMAC algorithms are rejected before any crypto runs |
| `exp` / `nbf` | `ClerkTokenVerifier` | Small clock skew (default 5 s) |
| `iss` | `ClerkTokenVerifier` | Must equal `https://<frontendApiHost>` — **the official SDK does not check this**, which is one reason verification is implemented natively |
| `azp` | `ClerkTokenVerifier` | Must be in `CLERK_AUTHORIZED_PARTIES` when present |
| `sub` | `ClerkTokenVerifier` | `user_` + 1–190 url-safe characters |
| The user still exists / is not banned | `ClerkApiClient::getUser` | `GET /v1/users/{id}` with the secret key |
| Role, status | `User::syncFromClerk` | **Never** taken from the token or Clerk metadata: new accounts are `user`, admins keep what the database says |
| Session liveness | `Auth::clerkSessionWasRevoked` | Optional periodic `GET /v1/sessions/{id}` |

### Account reconciliation

`User::syncFromClerk($clerkUser)` is the only function that turns a Clerk
identity into a local row, and it always runs inside a transaction:

1. **Already linked** (`clerk_user_id` matches) → refresh name, avatar, verified
   email; never create a row.
2. **Existing local account with the same *verified* email** → attach the Clerk
   id to that row. This is how pre-Clerk accounts (including every administrator)
   migrate without losing their id, reservations or audit history. An unverified
   address can never claim an existing account, and an address already linked to
   a different Clerk user produces a clear error instead of a merge.
3. **Otherwise** → provision a new account with `role = 'user'`.

Roles are written only as PHP literals. `users.role` is never updated from
Clerk metadata, a token claim or a form field.

### Webhooks (optional but recommended)

`POST /clerk/webhook.php` accepts `user.created`, `user.updated`,
`user.deleted`, `session.created` and `session.ended|removed|revoked`.
`svix-id` / `svix-timestamp` / `svix-signature` are verified with
`CLERK_WEBHOOK_SECRET` (HMAC-SHA256, 5-minute replay window, key-rotation safe)
*before* the body is parsed.

* `user.deleted` **unlinks** the Clerk identity; the account, its reservations,
  subdomains and audit entries are preserved, so the person can register again
  and an administrator can re-link the row.
* Webhooks never create accounts and never change roles.
* Deliveries are idempotent: Clerk retries are acknowledged, not re-applied.

### Offline administrator access (break-glass)

There is **no web password form anywhere**. If Clerk is unavailable or
misconfigured, an operator with shell access can issue a one-time link:

```bash
php database/break-glass.php --email=admin@example.com     # prompts for the local password, if one exists
php database/break-glass.php --disable                    # revoke every outstanding link
```

Requires `CLERK_BREAK_GLASS=true`, an `admin` row, and — when the account still
has a password hash from before the migration — that password. The link is
single-use, hashed at rest, expires after `BREAK_GLASS_TTL`, is refused for
non-administrators and dies immediately if the account is demoted. Sessions
started this way carry a warning banner and are re-checked against
`CLERK_BREAK_GLASS` on every request.

### Upgrading an existing installation

```bash
php database/migrate.php --status   # show what would change, nothing is written
php database/migrate.php            # apply
php database/clerk-check.php        # configuration sanity, exit 0 = usable
php database/link-clerk.php --list  # who is linked to which Clerk user
```

The migration adds `clerk_user_id` (unique), `username`, `avatar_url`,
`auth_provider`, makes `password_hash` nullable, and creates `clerk_handoffs` and
`clerk_sessions`. On SQLite the `password_hash` change needs a table rebuild; it
runs inside a transaction with an explicit column list and a row-count check, so
existing accounts keep their ids and every foreign key still points at them. Run
it with a backup in hand on production MySQL.

### Verifying against Clerk

Everything in this repository is exercised against a **test double**: a
committed test-only RSA key signs tokens exactly as Clerk does, and the Backend
API is stubbed at `ClerkApiClient`'s transport seam. That proves the code paths,
but it does **not** prove the integration, because no live Clerk request has been
made from this environment (outbound access to `api.clerk.com` and the frontend
API is blocked here, and no secret key exists).

To verify for real, on a machine that can reach Clerk:

1. Create a development instance at <https://dashboard.clerk.com> and copy the
   publishable and secret keys into `.env`.
2. Configure → JWT Templates → **New template → Blank**, name it
   `freesub_handoff`. Keep the default session claims (`sub`, `sid`, `exp`,
   `iss`, `azp`); no custom claims are needed. The name must match
   `CLERK_JWT_TEMPLATE`.
3. Set `CLERK_AUTHORIZED_PARTIES` to the exact origin you browse to
   (`http://localhost:8000`, `https://your.domain`, …) and `APP_URL` to the same
   value.
4. Run `php database/clerk-check.php` — it must exit `0`.
5. Enable Google under SSO connections if you want the OAuth path.
6. Walk the flow and confirm each step:
   * register with an e-mail address → the e-mail code arrives → `/dashboard.php`
     appears with `clerk_user_id` set on your row;
   * sign out, sign in again → the **same** row is reused (no duplicate);
   * sign in with Google using the same address → the same row again;
   * sign out, then open `/dashboard.php` directly → redirected to `/login.php`;
   * sign in as a normal user, then open `/admin/index.php` → denied;
   * sign in as an existing administrator → the admin area still works;
   * `php database/link-clerk.php --list` shows the links;
   * optional: add the webhook endpoint in the dashboard
     (`https://your.domain/clerk/webhook.php`, events `user.*`, `session.*`),
     put the `whsec_…` value in `CLERK_WEBHOOK_SECRET`, then delete a test user in
     the dashboard and watch the row be unlinked, not deleted.

Only after those checks pass should the Clerk migration be called complete.

---

## Security model

**Authentication.** Clerk handles every credential: registration, sign-in, Google OAuth, e-mail verification and password recovery all happen inside Clerk's components mounted on our own pages with the official `clerk-js` browser build. FreeSub stores **no passwords for web users**. See [Authentication](#authentication-clerk) for the flow. Sessions regenerate on login/logout and are bound to a fingerprint of the client; the stored user is re-loaded from the database on every request, so a suspended, deleted or re-linked account loses access immediately.

**CSRF.** A 32-byte token per session, embedded by `csrf_field()` and verified by `csrf_check()` with `hash_equals()`. Every POST endpoint calls it; invalid tokens get a friendly `419` page. Logout is POST-only so link prefetchers cannot sign users out.

**Input and output.** Extension-less allowlists for enums, strict label regex with reserved-word blocking, `filter_var()` for emails, length caps everywhere. All dynamic output goes through `e()`; the API emits JSON via `json_encode()`.

**Rate limiting.** File-backed sliding windows for login (per email+IP), registration, abuse reports and the availability API. If the cache directory is unwritable the limiter fails open rather than locking users out.

**Sessions.** `session.use_strict_mode`, `use_only_cookies`, `cookie_httponly`, `SameSite=Lax` (or `Strict`), `Secure` on HTTPS, idle timeout, custom directory outside the document root's reach.

**Headers and files.** `X-Content-Type-Options`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy`, `Permissions-Policy`, COOP, optional HSTS, CSP (self-only assets) via `.htaccess`. `config/`, `includes/`, `storage/`, `database/` and dot files are denied at the web-server level and by `router.php` in development.

**Audit trail.** `auth.*`, `subdomain.*` and `admin.*` actions are appended to `audit_logs` with actor, IP, user agent and JSON context. Logging failures never break a request.

**Not implemented (by instruction):** Cloudflare API calls, wildcard DNS assumptions, customer hosting (File Manager / PHP / MySQL accounts), email sending.

---

## How root domains work

1. A root domain is a row in `domains` (`root_domain`, `extension`, `subdomain_quota`, `dns_provider`, `zone_reference`).
2. The landing page, `/extensions.php`, the dashboard and `api/check.php` all query that table — nothing is hard-coded.
3. A domain is offered publicly only when `is_active = 1 AND is_public = 1 AND registration_status = 'ready'`.
4. `zone_reference` stores a **public** identifier (e.g. a Cloudflare zone id). API tokens stay in `.env` and are never written to the database or shipped to the browser.
5. `includes/services/DnsProvider.php` defines the contract (`isHostnameAvailable`, `createHostname`, `deleteHostname`). The shipped driver is `NullDnsProvider`, which always answers "automation not enabled" — the Cloudflare driver can be added without touching callers.

---

## Tests

No PHPUnit required:

```bash
php tests/run.php                 # every suite, isolated SQLite DB, no network
php tests/run.php security        # one suite (see the list below)
php tests/run.php clerk-token     # Clerk verification only
```

Optional end-to-end journeys (needs a running server):

```bash
php -S localhost:8000 router.php &
FREESUB_TEST_BASE_URL=http://localhost:8000 php tests/run.php http

# Full Clerk flow over real HTTP, with the in-process Clerk test double
tests/http-clerk.sh 8099
```

Suites:

| Suite | What it proves |
|-------|----------------|
| `schema` | tables, indexes, foreign keys, seeding |
| `env` | `.env.example` parses and ships sane values |
| `security` | escaping, CSRF, validation, rate limiting, blocked paths |
| `auth` | local account helpers, hashing, suspended accounts |
| `clerk-config` | publishable-key decoding, issuer/JWKS/authorized-party derivation, diagnostics never leak the secret |
| `clerk-token` | real RS256 **verification**: signature, tampering, foreign key, `alg` downgrade, expiry, `nbf`, wrong/missing `iss`, wrong `azp`, unknown `kid`, JWK→PEM, key rotation |
| `clerk-sync` | first sign-in, duplicate sync, legacy-administrator migration, FK preservation, unverified-email hijack, banned users, metadata never grants admin |
| `clerk-handoff` | channel/state binding, single-use tickets, replay, expiry, origin enforcement, Clerk outage, break-glass tickets |
| `clerk-session` | session binding, invalid/legacy sessions, fingerprint mismatch, suspension, logout, admin retention |
| `clerk-webhook` | svix HMAC verification, tampering, replay window, key rotation |
| `clerk-webhook-effects` | `user.updated` / `user.deleted` / session events applied without creating or deleting accounts |
| `subdomains` | labels, reservations, quota, duplicate fqdn, no DNS on reservation |
| `audit` | audit trail and abuse reports |
| `views` | every view/link/redirect target exists |
| `http` | HTTP journeys (needs a running server) |

Current status: **266 assertions green** on PHP 8.5 with SQLite, plus **49 end-to-end HTTP checks** in `tests/http-clerk.sh`. The MySQL schema in `database/schema.sql` was validated against a real MariaDB 13.1 engine (import, generated column, FKs, cascades, idempotent re-run).

The tests use a throwaway database file (`storage/test-<pid>.sqlite`) and delete it afterwards. They never touch production data.

The Clerk suites need no network and no real Clerk secret: tokens are signed with a committed **test-only** RSA key pair (`tests/fixtures/`), served to the verifier as a JWKS document, and the Backend API is replaced at `ClerkApiClient`'s transport seam. What they cannot prove is that a live Clerk instance issues tokens shaped the way we expect — that requires the owner's dashboard, see [Verifying against Clerk](#verifying-against-clerk).

---

## Remaining work

Tracked in [`TASKS.md`](TASKS.md). Highlights:

1. **Cloudflare driver** implementing `DnsProviderInterface`, plus a queue/CLI worker that reconciles `dns_records` and flips subdomains to `active` only after verification.
2. **Transactional email** for reservation/subdomain notifications (account e-mail verification and password reset are handled by Clerk).
3. **Public API tokens** for users (programmatic reservation management) with scoped keys.
4. **Admin audit log browser** with filtering and export.
5. **DNS pre-check** (wildcard detection, NS delegation check) before a domain is marked `ready`.
6. **i18n** and a full RTL stylesheet pass.
7. **Nginx sample config** in addition to `.htaccess`.
8. **Observability**: structured logs, health endpoint, retention policy for `audit_logs`.
9. **UI**: screenshot regression tests once a headless browser is available in CI.

---

## Troubleshooting

| Symptom | Cause / fix |
|---------|-------------|
| "FreeSub is almost ready" page | The database is unreachable or empty. Import `database/schema.sql`, check `DB_*`, then reload |
| `SQLSTATE[HY000] [1045] Access denied` | Wrong `DB_USERNAME` / `DB_PASSWORD` |
| Login redirects straight back | Cookies blocked, `SESSION_SECURE=true` on plain HTTP, or the session directory is not writable |
| "Session expired" (419) on submit | The form was open across a session rotation — refresh and try again |
| 404 on `config/...` or `.env` | Expected: those paths are blocked on purpose |
| Styles missing | `assets/vendor/bootstrap.min.css` not deployed, or a strict CSP blocked a third-party CDN (everything is self-hosted) |
| Everything 200, even bogus URLs | Using `php -S` without `router.php`. Harmless for the app, but use the router (or Apache) for correct 404s |

---

## License

Project code: MIT. Bundled third-party assets keep their own licenses — see `assets/vendor/LICENSE.*.txt` and `assets/fonts/LICENSE.plus-jakarta-sans.txt` (Bootstrap MIT, Lucide ISC, Plus Jakarta Sans OFL).
