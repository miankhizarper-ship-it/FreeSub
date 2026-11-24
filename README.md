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
- [DNS engine (Cloudflare)](#dns-engine-cloudflare)
- [Subdomain availability & reservations](#subdomain-availability--reservations)
- [User dashboard](#user-dashboard)
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

**Accounts (Clerk)**

- Registration, sign-in, **Google OAuth**, e-mail verification and password
  recovery are handled by Clerk's components mounted on our own pages with the
  official `clerk-js` build. There is no password field anywhere in FreeSub.
- Sign-in completes through a one-time, single-use **handoff ticket** that PHP
  verifies server-side (see [Authentication (Clerk)](#authentication-clerk)).
- Existing accounts — including administrators — are linked to their Clerk
  identity by verified e-mail; ids, reservations and audit history survive.
- POST-only, CSRF-protected logout that also revokes the Clerk session.
- Sessions: `HttpOnly`, `SameSite`, `Secure` when on HTTPS, strict mode, custom
  save path, idle timeout, id rotation and a client fingerprint re-checked on
  every request.
- CLI-only break-glass path for emergencies (`php database/break-glass.php`).

**Dashboard**

- Quota meter, pending-request count, extension count, account summary.
- Reserve form with **inline, debounced availability** — the same engine behind
  the landing-page search, so the two can never disagree.
- Strict server-side validation of every submission (the browser check is a
  courtesy, not a control), quota enforcement, duplicate-request protection.
- Subdomain list with explicit statuses (`pending_dns`, `active`, `suspended`, `failed`, `terminated`),
  pending/approved/rejected buckets, full reservation history and cancellation.
- Everything is scoped to the Clerk identity resolved server-side; no request
  parameter selects the account.

**Admin**

- Overview with platform metrics and queue snapshots.
- Reservation queue: approve (creates a `pending_dns` subdomain **and one queued
  job**, never "active"), reject with a reason, filter by status/extension, search
  by label or e-mail, expire lapsed requests on demand.
- DNS provisioning queue: run / retry / cancel a job, see attempts and the last
  error for every failure. Nothing runs automatically.
- Per-user subdomain quota (override or reset to the default), with live usage.
- Root-domain management: add apex domains (created as `draft` so nothing is offered before DNS is ready), toggle availability, change registration status.
- User directory with search and suspend/activate (self-suspension blocked).
- Abuse triage: reviewing → resolved/dismissed with a resolution note.
- Every admin mutation is written to `audit_logs`.

**Data layer**

- Ten tables (eight domain/user tables plus `clerk_handoffs` and `clerk_sessions`) with primary keys, foreign keys, unique constraints, indexes, `created_at`/`updated_at` (with `ON UPDATE CURRENT_TIMESTAMP` on MySQL).
- `reservations.live_guard` gives MySQL a partial unique index: `(domain_id, label, live_guard)` with `1` for pending/approved and `NULL` otherwise, so a label can never be held by two live requests — while any number of rejected/cancelled/expired rows keep their history.
- `provisioning_jobs` is the DNS queue: one row per approved subdomain, with attempt count, backoff timestamp and last error.
- Multi-domain ready: extensions are rows, the UI and API read from the table, adding a domain needs no code change.
- Generated `extension` column (MySQL) derived from `root_domain`.
- DNS records stored as *desired state* only; `dns_records.provider_record_id` is reserved for the future Cloudflare sync.

**Security**

- PDO prepared statements everywhere, `ATTR_EMULATE_PREPARES = false`, strict SQL mode, exceptions on error.
- CSRF tokens on every state-changing form, verified with `hash_equals()`; failures return `419`.
- Output escaping through a single `e()` helper (`ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5`).
- Security headers (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, COOP, CSP via `.htaccess`), `X-Powered-By` removed.
- `.htaccess` rules block `config/`, `includes/`, `storage/`, `database/`, dot files and backups, with matching `403/404` handler and no directory listing.
- Secrets live only in `.env` (Clerk keys, webhook secret, DNS token); no schema column stores a token, and no Clerk secret is ever rendered, logged or committed.
- Clerk session tokens are verified natively (signature, `alg`, `kid`, `iss`, `exp`, `nbf`, `azp`, `sub`); webhook payloads are verified with the svix HMAC before parsing.
- Roles come only from the database — never from Clerk metadata, a token claim or form input.

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

# 4. Fill in DB_* in .env, then the Clerk keys (see Authentication below)
#    CLERK_PUBLISHABLE_KEY=pk_test_...
#    CLERK_SECRET_KEY=sk_test_...            (server-side only, never committed)
#    CLERK_AUTHORIZED_PARTIES=http://localhost:8000

# 5. Apply the Clerk identity migration
php database/migrate.php
php database/clerk-check.php        # must exit 0 before anyone can sign in

# 6. Create the first administrator. It is a local row with the admin role and
#    no Clerk identity; the first time that e-mail signs in through Clerk the
#    account is linked to it, keeping the role.
#    (prompts for a hidden local password; --password=... for CI)
php database/create-admin.php "Your Name" you@example.com

# 7. Run it
php -S localhost:8000 router.php
```

Open <http://localhost:8000>, choose **Sign in**, and register or sign in with
the e-mail address you used in step 6. Clerk's own component handles the
credentials; FreeSub then attaches your Clerk identity to the administrator row
and `/admin/index.php` is available.

**Permissions.** The web server user must be able to write to `storage/logs`, `storage/cache` and `storage/sessions` (and `storage/database.sqlite` if you use SQLite):

```bash
chmod -R 775 storage
```

**Subdirectory installs** work too — URLs are generated from the detected base path, so `https://example.com/freesub/` needs no configuration changes.

**SQLite quick start (development only).** Set `DB_DRIVER=sqlite` and `DB_SQLITE_PATH=storage/database.sqlite`, then run `php database/install.php`, which imports `database/schema.sqlite.sql` (which already includes the Clerk tables). Production must use MySQL/MariaDB — the SQLite schema exists so the app and tests can run without a database server, and a few MySQL-only features (generated column, `ON UPDATE CURRENT_TIMESTAMP`, JSON type) are emulated.

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
| `DNS_PROVIDER` | `none` (default) or `cloudflare`. Anything else, or a missing token/zone, degrades to `none` |
| `DNS_ROOT_DOMAIN` | The single apex this installation may write to (default `kharcha.info`). A zone that does not match it is refused before any record is touched |
| `CLOUDFLARE_API_TOKEN` | **Server-only** secret, scoped to one zone. Never stored in the database, never rendered, never logged |
| `CLOUDFLARE_ZONE_ID` | 32 hex characters from the zone's Overview page. A public identifier — safe in `domains.zone_reference` |
| `CLOUDFLARE_API_BASE_URL` | Defaults to `https://api.cloudflare.com/client/v4`; `http` is only accepted for loopback addresses |
| `CLOUDFLARE_*_TIMEOUT`, `CLOUDFLARE_MAX_RETRIES` | Connect/request timeouts and the read-only retry budget |
| `CLOUDFLARE_DEFAULT_TTL` | TTL for new records; `1` = automatic |

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
| `dns_records` | Desired DNS state per subdomain | `UNIQUE(subdomain_id, type, name)`, `domain_id` + `provider_record_id` + `last_synced_at`, status workflow `pending → active/failed → deleted` |
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
│   ├── css/app.css         Design system (tokens, components, dashboard shell, responsive rules)
│   ├── js/app.js           Icons, header, reveal, live search, busy-form + copy guards
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
│   ├── migrate.php                Applies (or --status shows) pending migrations
│   ├── dns-sync.php               Read-only DNS inspection (operator command)
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
│   │   ├── Dashboard/      DashboardStatus — canonical state → user-facing words
│   │   ├── Clerk/          ClerkConfig, ClerkTokenVerifier, ClerkApiClient,
│   │   │                   ClerkHandoff, ClerkWebhook, ClerkToken
│   │   ├── Dns/            CloudflareDnsProvider, CloudflareApiClient, DnsLog,
│   │   │                   DnsRecordValidator, DnsRecordRepository, DnsManager
│   │   ├── DnsProvider.php DnsProvider (interface, null driver, factory)
│   │   └── SubdomainService.php
│   └── views/              Layouts (public/app/dashboard/admin), partials
│                           (incl. clerk-auth.php, status-badge.php), pages, admin, dashboard/
├── clerk/
│   └── webhook.php         Clerk webhook receiver (svix signature verified)
├── storage/                Logs, cache (incl. JWKS cache), sessions (git-ignored)
├── tests/
│   ├── run.php             Test runner (all suites listed under Tests)
│   ├── clerk-suite.php     Clerk suites (verification, sync, handoff, sessions, webhooks)
│   ├── dns-suite.php       DNS suites (rules, client, lifecycle, security, optional live)
│   ├── support/ClerkTestHarness.php   RSA signing + stubbed Backend API
│   ├── support/CloudflareTestDouble.php   In-memory Cloudflare v4 API
│   ├── fixtures/           Test-only RSA key pairs (never used for anything real)
│   ├── dashboard-suite.php  Task 05 unit assertions (suite: dashboard)
│   ├── dashboard-render.php Renders every dashboard page and asserts on the HTML
│   ├── http-dashboard.py    Task 05 HTTP + cross-account isolation checks
│   ├── dashboard.sh         Runs all of the above, both debug modes
│   ├── seed-dashboard.php   Seeds the local preview database
│   ├── http-journeys.php   Foundation HTTP journeys via cURL
│   ├── http-clerk.sh       End-to-end Clerk flow over real HTTP
│   └── router-e2e.php      Router used only by the end-to-end run
├── index.php               Landing page
├── login.php register.php logout.php
├── clerk-bridge.php        Privileged same-origin handoff endpoint
├── auth/handoff.php        Redeems a handoff ticket into a session
├── dashboard.php           Signed-in overview
├── dashboard/              subdomains.php, subdomain.php, reservations.php,
│                           search.php, settings.php
├── preview-login.php        Local-only dashboard preview (guarded; 404 otherwise)
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
2. **Existing local account with the same *verified* email, `role = 'user'`** →
   attach the Clerk id to that row. An unverified address can never claim an
   existing account, and an address already linked to a different Clerk user
   produces a clear error instead of a merge.
3. **Otherwise** → provision a new account with `role = 'user'`.

**Administrator rows never link from a web sign-in.** Verifying an email address
proves control of a mailbox, not that the person is the operator who ran the
platform — addresses get recycled, re-registered after a lapse, or attached to a
second Clerk user by mistake. If step 2 would attach an `admin` row, the sign-in
is refused and the operator links it deliberately from the shell:

```bash
php database/link-clerk.php --email=admin@example.com --clerk-email=admin@example.com
```

That command requires Clerk to report the address as **verified**, refuses when
it does not match the local row, and never changes the role. Until it has run,
an administrator whose row predates Clerk cannot sign in — which is the intended
trade-off, and why `php database/link-clerk.php --unlinked` belongs in your
migration checklist.

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

Then link each administrator once (see [Account reconciliation](#account-reconciliation));
their dashboards stay closed to web sign-ins until you do.

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
   * sign in as an existing administrator **before** linking → refused with
     "managed offline" (this is the takeover guard, not a fault);
   * run `php database/link-clerk.php --email=… --clerk-email=…`, then sign in
     again → the admin area works and the role is unchanged;
   * `php database/link-clerk.php --list` shows the links;
   * optional: add the webhook endpoint in the dashboard
     (`https://your.domain/clerk/webhook.php`, events `user.*`, `session.*`),
     put the `whsec_…` value in `CLERK_WEBHOOK_SECRET`, then delete a test user in
     the dashboard and watch the row be unlinked, not deleted.

Only after those checks pass should the Clerk migration be called complete.

---

## DNS engine (Cloudflare)

The engine is **read-only in this build**. It can prove that a zone belongs to
`kharcha.info`, list its records, and compare them with what FreeSub believes it
owns. Nothing in the web application creates, changes or deletes a DNS record,
public subdomain registration stays disabled, and no subdomain is ever marked
`active` without a verified record.

### What exists

| Layer | File | Responsibility |
|-------|------|----------------|
| Contract | `includes/services/DnsProvider.php` | The original five methods, unchanged; `NullDnsProvider` (never claims success); the factory |
| Provider | `includes/services/Dns/CloudflareDnsProvider.php` | Implements the contract, adds `listRecords` / `findRecord` / `createRecord` / `updateRecord` / `deleteRecord` / `verifyZone` |
| HTTP | `includes/services/Dns/CloudflareApiClient.php` | cURL, timeouts, status and `success`/`errors` parsing, retry policy, error classification |
| Rules | `includes/services/Dns/DnsRecordValidator.php` | Names, IPv4/IPv6, private ranges, CNAME targets, TTLs, reserved labels |
| State | `includes/services/Dns/DnsRecordRepository.php` | `dns_records` reads/writes, always scoped to a root domain |
| Lifecycle | `includes/services/Dns/DnsManager.php` | create / update / delete / reconcile + audit |
| Admin | `admin/dns.php` + `includes/views/admin/dns.php` | Read-only diagnostics, connection test, comparison |
| CLI | `database/dns-sync.php` | Read-only inspection for operators |

### Getting the Zone ID and a restricted token

1. Cloudflare dashboard → the `kharcha.info` zone → **Overview** → *Zone ID*
   (32 hex characters). This is the value for `CLOUDFLARE_ZONE_ID`.
2. **My Profile → API Tokens → Create Token → Create Custom Token** with exactly:
   - `Zone` → `DNS` → `Edit`
   - `Zone` → `Zone` → `Read`
   - *Zone Resources*: **Include → Specific zone → kharcha.info**
   Do not use the Global API Key, and do not grant "All zones".
3. Put the token in `.env` (never in the database, never in a commit):

```dotenv
DNS_PROVIDER=cloudflare
DNS_ROOT_DOMAIN=kharcha.info
CLOUDFLARE_API_TOKEN=…      # server-side only
CLOUDFLARE_ZONE_ID=…        # public identifier
```

A token that can see other zones is harmless: the engine verifies the zone name
against `DNS_ROOT_DOMAIN` on every operation and refuses a mismatch
(`zone_mismatch`) before touching a record.

### Running it

```bash
# Read-only inspection: verifies the zone, then compares records. Writes nothing.
php database/dns-sync.php
php database/dns-sync.php --json | python3 -m json.tool
```

Then **Admin → DNS diagnostics**: connection state, the verified zone and its
nameservers, record counts, recent sync errors and the DNS operation history.
The page has no token, no header and no mutation control — the only network call
it can make is the read-only *Test connection* action.

### Rules the engine enforces

- Only `A`, `AAAA` and `CNAME`. `NS`, `MX`, `TXT` and `CAA` can never be written
  through this engine, by any caller.
- Names must be inside `DNS_ROOT_DOMAIN`, at most three labels deep, never the
  apex, never a wildcard, never a reserved label (`www`, `admin`, `mail`, `ns1`, …
  from `subdomains.reserved_labels`).
- Addresses must be public: RFC1918, loopback, link-local (including
  `169.254.169.254`), CGNAT, multicast, TEST-NET and the IPv6 equivalents
  (ULA, link-local, documentation, IPv4-mapped private) are all refused.
- A CNAME may not share a name with anything else and may not point at an IP,
  a URL, a port, `.local`/`.internal` or the zone apex.
- `proxied` is always `false`; a user cannot enable the orange cloud.
- Proxied/CDN behaviour, nameservers and wildcard records are never changed.

### Failure policy

| Situation | Behaviour |
|-----------|-----------|
| Read fails with 429/5xx/timeout | Retried up to `CLOUDFLARE_MAX_RETRIES` with exponential backoff, honouring `Retry-After` |
| **Create fails with a timeout or 5xx** | **Never retried.** The zone is re-read first; if our record is there with the same value it is adopted, otherwise the row is marked `failed` with the reason |
| Delete | Exactly one Cloudflare id, taken from our own row. A name-based sweep is never used |
| Sync | Reports missing / modified / unmanaged records. **Never** deletes or recreates anything |

### Verifying the integration

Be precise about which of these has actually been done:

| Claim | How to prove it | Done here? |
|-------|-----------------|------------|
| `MOCK TESTS PASSED` | `php tests/run.php` (suite `dns-*`, mocked transport, no credentials) | **yes** |
| `LIVE CONNECTION VERIFIED` | `FREESUB_DNS_LIVE=1 CLOUDFLARE_API_TOKEN=… CLOUDFLARE_ZONE_ID=… php tests/run.php dns-live`, or `php database/dns-sync.php` | **no** — no Cloudflare credentials in the delivery environment |
| `DNS MUTATIONS VERIFIED` | A deliberate create → read-back → delete against the zone, on a throwaway name, by an operator | **no** — not attempted; the engine has no web path that writes in this build |

The mocked suites cover zone verification (match, mismatch, non-active, unknown),
401/403/429/5xx, transport timeouts, invalid IPv4/IPv6, private-IP rejection,
invalid CNAME targets, duplicate and cross-subdomain conflicts, reserved labels,
create, delete ownership, unknown-record preservation, response parsing and
audit logging — but they prove the engine is *correct against the documented
API*, not that a live zone accepts it.

---

## Subdomain availability & reservations

The reservation engine answers one question — *may this label be taken, right
now?* — and then holds the answer while a human reviews it. It is deliberately
boring: one engine, one queue, no background daemon.

### Who decides "available"

`includes/services/Subdomain/AvailabilityEngine.php` checks, cheapest first, and
stops at the first decisive answer:

| # | Source | Meaning |
|---|--------|---------|
| 1 | Validation rules | reserved label, wildcard, apex, malformed, wrong root |
| 2 | `subdomains` | already provisioned, or already waiting for DNS |
| 3 | `dns_records` | we created a record for this name |
| 4 | `reservations` | a live request holds it |
| 5 | Cloudflare (live zone) | somebody else created a record here |

Two rules matter more than speed:

- **Never a false "available".** If step 5 cannot be answered and a zone is
  configured, the answer is `unknown` (unavailable, HTTP `503` from the API), not
  "free". An outage must never look like an opportunity.
- **Never a Cloudflare call per keystroke.** Answers are cached per
  `(zone, name)` in `storage/cache/availability` with a TTL, failures are never
  cached, and the reservation path bypasses the cache and re-reads the zone.

TXT/MX records do not block a name (SPF and verification records are normal);
A/AAAA/CNAME/NS do.

### Statuses

| Status | Where it comes from | Shown to the user as |
|--------|---------------------|----------------------|
| `available` | engine, after a live check | "available" |
| `taken` | subdomains / records / live zone | "already taken" |
| `pending` | a live reservation or `pending_dns` subdomain | "reserved / waiting for DNS" |
| `reserved` | policy (reserved list, apex, wildcard) | "reserved, pick another" |
| `invalid` | validation | the specific reason |
| `unknown` | provider unreachable or zone not configured | "could not confirm, try again" |
| `blocked` | root domain closed for registration | "not accepting names" |

### The lifecycle

```
search ──► reserve (pending, TTL 168h) ──► admin approve ──► subdomain pending_dns
                  │                                              │
                  ├─ user cancels ──► cancelled                  │  admin/CLI runs the job
                  ├─ review rejects ──► rejected                 ▼
                  └─ TTL lapses ──► expired            record created ──► read back ──► active
```

- A reservation **never** creates a record and **never** marks a name active.
- Approval creates a `pending_dns` subdomain and exactly one `provisioning_jobs` row.
- The subdomain becomes `active` only after the record is read back from the
  provider and its value compared — in `ProvisioningQueue::provision()`, the only
  place that writes `active`.
- A failure is recorded on the job with its reason and stays visible to admins.
  Automatic retries are bounded (`PROVISIONING_MAX_ATTEMPTS`, default 5, with
  exponential backoff); a create is never blindly retried.

### Quota

`SubdomainQuota` resolves the allowance per user: `users.subdomain_quota`
(admin override) → `domains.subdomain_quota` → `subdomains.initial_quota`
(default 2). Usage counts non-terminated subdomains **plus** pending/approved
reservations, so queueing more requests than you could ever hold is impossible.
An administrator can raise, lower or clear an override from **Admin → Users**;
every change is audited (`admin.quota_updated`).

### Provisioning (admin/CLI only)

```
php database/provision.php --status                 # inspect the queue
php database/provision.php                          # dry run (default)
php database/provision.php --run --limit=5          # run up to five jobs
php database/provision.php --run --job=42           # run one job
php database/provision.php --run --actor=you@x.com  # attribute the audit entry
```

- Dry run is the **default**; nothing is written without `--run`.
- Each job is claimed with a conditional `UPDATE`, so two terminals cannot work
  the same row, and a crashed worker's lock is recovered after
  `PROVISIONING_LOCK_TIMEOUT`.
- The worker refuses to run when the engine is disabled or no target is
  configured — it does not guess an address. Where a subdomain points is set by
  `DNS_PROVISIONING_A` / `DNS_PROVISIONING_AAAA` / `DNS_PROVISIONING_CNAME` on the
  server; a user picks a name, never a destination.
- Exit codes: `0` nothing failed, `2` at least one job failed, `1` refused to run.
- The admin queue offers the same actions per job (run / retry / cancel) behind
  admin auth and CSRF.

### Removing a name

Releasing a `pending_dns` or failed name cancels its queued jobs immediately. An
**active** name is released through support, so the DNS record is deleted by the
same audited path that created it. Releasing an active name from the browser is
deliberately not offered: that would orphan a live record.

### Verifying the reservation engine

| Claim | How to prove it | Done here? |
|-------|-----------------|------------|
| `MOCKED TESTS PASSED` | `php tests/run.php reservations` — 101 assertions against SQLite + a scripted Cloudflare transport | **yes** |
| `MIGRATIONS APPLIED` | `php database/migrate.php` on a copy of a pre-Task-04 database; rows, ids and foreign keys preserved | **yes** (SQLite, plus MySQL DDL proven separately) |
| `LIVE CLOUDFLARE READ USED FOR AVAILABILITY` | a real token in `.env`, then `curl '/api/check.php?label=…'` and see `"authoritative": true` | **no** — no Cloudflare credentials in the delivery environment |
| `DNS PROVISIONING VERIFIED AGAINST A REAL ZONE` | `php database/provision.php --run --job=…` on a throwaway name | **no** — not attempted; no credentials |

The mocked suite covers strict validation (10 rejection classes), local-state
availability, unmanaged-record blocking, TXT not blocking, fail-closed on
provider error, cache behaviour, request/duplicate/quota/cancel/expire flows,
approval creating `pending_dns` only, a record that cannot be read back never
activating, job cancellation and retry, identity scoping, quota administration
and a two-account race on one label.

---

## User dashboard

The signed-in product. Every page is server-rendered PHP with Bootstrap 5 and a
little vanilla JavaScript; there is no client framework, no build step, and no
second state store — the database is the only source of truth.

| Page | File | What it shows |
|------|------|---------------|
| Overview | `dashboard.php` | Account, the four headline statistics, quota with a bar, recent activity |
| My subdomains | `dashboard/subdomains.php` | Every name on the account, filterable by canonical status |
| Subdomain detail | `dashboard/subdomain.php?id=…` | Basic info, DNS/provisioning state, timeline, release |
| Reservations | `dashboard/reservations.php` | Every request this account ever made, and its outcome |
| Find a subdomain | `dashboard/search.php` | Availability check + reservation form |
| Account | `dashboard/settings.php` | Profile, quota, security facts, sign-out |

`preview-login.php` is a **local-only** helper for looking at these pages without
Clerk credentials; it refuses to run unless `APP_ENV` is `local`/`testing` *and*
the database is the seeded preview file. See [Previewing the dashboard](#previewing-the-dashboard).

### The shell

`includes/views/layouts/dashboard.php` replaces the public navbar for signed-in
pages only (`layouts/public` and `layouts/admin` are untouched). One navigation
definition drives two renderings:

- **≥ 992px** — a fixed sidebar with the account block and sign-out at the foot.
- **< 992px** — the same links inside a Bootstrap `offcanvas` panel opened from a
  top-bar button, so focus trapping, Escape and the backdrop are Bootstrap's own
  behaviour rather than a hand-rolled imitation.

There is exactly one `<nav>` per breakpoint and both are in the document, so the
shell works with JavaScript disabled at desktop width and the off-canvas is the
only thing that needs it.

### Where the numbers come from

Nothing on the dashboard is hardcoded:

- **Statistics** are counted from `subdomains` and `reservations` scoped to
  `Auth::id()`.
- **Quota** is `SubdomainQuota::overview()` — the same service the reservation
  endpoint enforces, so the page cannot advertise an allowance the server would
  refuse. `used` is the account-wide figure `ReservationService::create()`
  compares against the per-domain allowance; the per-extension breakdown is shown
  next to it so no single number appears without its context.
- **Timestamps** are printed only when the row carries one. A timeline step for
  an event that never happened is simply absent — no placeholder date is ever
  invented.

The list page costs a constant number of queries regardless of row count:
`Subdomain::provisioningForMany()` fetches the latest job for every row in one
statement.

### Statuses the user sees

`includes/services/Dashboard/DashboardStatus.php` is the single place where a
canonical backend state becomes words. It invents no state and decides no rule.

| Stored state | Label | Meaning shown |
|--------------|-------|---------------|
| `pending_dns` | Pending DNS | Approved and queued; record not created yet |
| `active` | Active | Record exists and was read back |
| `suspended` | Suspended | Paused; contact support |
| `failed` | Provisioning failed | Could not be created or verified; support can retry |
| `terminated` | Released | No longer yours; the label may be available again |

Reservations use the same treatment (`Awaiting review`, `Approved`, `Rejected`,
`Cancelled`, `Expired`). Every badge is a word **and** a colour, so status never
depends on colour alone, and the timeline repeats each step's state in text for
screen readers.

### What the dashboard deliberately does not do

- **No provisioning.** It cannot create, edit or trigger a DNS record. The only
  writer of `active` is `ProvisioningQueue::provision()`, reached from the admin
  queue or the CLI. The dashboard never calls the provider.
- **No availability logic in JavaScript.** The form asks `/api/check.php` for a
  courtesy verdict; `ReservationService::create()` re-runs validation,
  availability (including the live zone) and quota on POST. A stale "available"
  in the browser cannot book a taken name. With JavaScript off the form still
  posts and the server answers honestly.
- **No credential management.** The name and e-mail on the account are a verified
  copy of the Clerk identity, and FreeSub stores no password for web users. The
  Account page explains where to change them instead of offering a form that
  would either silently revert or let someone claim an unverified address.
- **No role or auth changes.** Nothing in the dashboard can alter a role,
  suspend an account or start a different kind of session.

### Ownership and error behaviour

Every read is scoped in SQL — `Subdomain::findForUser($id, Auth::id())`,
`ReservationService::history($userId)`, `Subdomain::forUser($userId)`. No request
value ever selects a user.

A subdomain that belongs to someone else is treated **exactly** like one that
does not exist: `404` with the same page. A `403` would confirm the id is real
and turn the URL into an enumeration oracle. Ids are validated as digits before
they are cast, so `/dashboard/subdomain.php?id=1e3` is a `404`, not an `int` cast
of `1`.

### Mutations

Two, both `POST` + CSRF, both re-validated server-side:

| Action | Where | Rules |
|--------|-------|-------|
| Reserve a name | `dashboard/search.php` | `ReservationService::create()` — validation, availability, quota, transactional uniqueness; rate-limited per account |
| Cancel a request | `dashboard/reservations.php` | `ReservationService::cancel()` — pending only, ownership in SQL, audited |
| Release a name | `dashboard.php` (POST) | `DashboardStatus::canReleaseFromDashboard()` — `pending_dns`/`failed`/`suspended` only |

Releasing an `active` name is refused with an explanation, because its record has
to be deleted through the audited DNS path; the same refusal the CLI documents.

### Previewing the dashboard

```
DB_SQLITE_PATH=storage/dashboard-preview.sqlite php database/install.php --fresh --force
DB_SQLITE_PATH=storage/dashboard-preview.sqlite php database/migrate.php
DB_SQLITE_PATH=storage/dashboard-preview.sqlite php tests/seed-dashboard.php

APP_ENV=local DB_SQLITE_PATH=storage/dashboard-preview.sqlite   php -S 127.0.0.1:8000 router.php
```

Then open `/preview-login.php?a=owner` (or `?a=other` for the second account used
by the isolation checks). The seed creates an active, a pending, a failed, a
suspended and a released subdomain, records in every reservation state, and a
second account whose rows must stay unreachable.

The preview database and the helper are for local work only; `preview-login.php`
returns `404` unless the environment and the database path both match. Delete
both when you are done (`php tests/seed-dashboard.php --reset`).

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
5. `includes/services/DnsProvider.php` defines the contract (`isHostnameAvailable`, `createHostname`, `deleteHostname`). Two drivers ship: `NullDnsProvider` (always "automation not enabled") and `CloudflareDnsProvider`, which is selected only when `DNS_PROVIDER=cloudflare` and both a token and a zone id are present. A half-configured environment degrades to the null driver.
6. DNS mutations are not reachable from the web in this build. See [DNS engine (Cloudflare)](#dns-engine-cloudflare).

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
| `clerk-token` | real RS256 **verification**: signature, tampering, foreign key, `alg` downgrade, expiry, `nbf`, wrong/missing `iss`, wrong `azp`, `aud`, unknown `kid`, unreachable JWKS, JWK→PEM, key rotation |
| `clerk-sync` | first sign-in, duplicate sync, legacy-administrator migration, FK preservation, unverified-email hijack, banned users, metadata never grants admin |
| `clerk-privilege` | account-takeover attempts: an admin row cannot be inherited by a web sign-in; normal linking and the CLI path still work |
| `clerk-handoff` | channel/state binding, single-use tickets, replay, expiry, origin enforcement, Clerk outage, break-glass tickets |
| `clerk-session` | session binding, invalid/legacy sessions, fingerprint mismatch, suspension, logout, admin retention |
| `clerk-webhook` | svix HMAC verification, tampering, replay window, key rotation |
| `clerk-webhook-effects` | `user.updated` / `user.deleted` / session events applied without creating or deleting accounts |
| `dns-validate` | record rules: in-zone names, foreign/apex/wildcard/reserved rejection, public-only IPv4 and IPv6, CNAME targets, allowed types and TTLs |
| `dns-client` | Cloudflare client: zone verification (match/mismatch/pending/unknown), 401/403/429/5xx mapping, read retries, `success=false` on HTTP 200, path/identifier guards, token never logged |
| `dns-lifecycle` | create → persist id → audit, duplicate/cross-subdomain conflict, ambiguous create reconciled (not retried), delete ownership, unknown-record preservation, sync reporting |
| `dns-security` | factory degrades without credentials, admin-only + CSRF-wrapped page, no token in source/view, no mutation on the page, interface unchanged |
| `dns-live` | optional read-only check against a real zone (needs `FREESUB_DNS_LIVE=1` and real credentials; skipped otherwise) |
| `subdomains` | labels, reservations, quota, duplicate fqdn, no DNS on reservation |
| `reservations` | Task 04 engine: strict validation, availability against local state and a scripted zone, fail-closed provider errors, not cached, cache reuse, request/cancel/approve/reject/expire, quota, provisioning verification (run/retry/cancel), CLI guard rules, identity scoping, two-account race |
| `dashboard` | Task 05: status map vs the schema, release rules, timeline built only from real events, provisioning lookups carry no secrets, quota view equals the enforced rule, ownership in SQL, POST/CSRF discipline, no provider call from any page |
| `audit` | audit trail and abuse reports |
| `views` | every view/link/redirect target exists, mount-prefix derivation |
| `http` | HTTP journeys (needs a running server) |

Current status: **634 assertions green** on PHP 8.5 with SQLite, plus **110
end-to-end HTTP checks** in `tests/http-clerk.sh` and **88 render + 83 HTTP
dashboard checks** in `tests/dashboard.sh`. Every one of them passes with
`APP_DEBUG=true` and `APP_DEBUG=false`. The MySQL schema in `database/schema.sql` was validated against a real MariaDB 13.1 engine (import, generated column, FKs, cascades, idempotent re-run).

The tests use a throwaway database file (`storage/test-<pid>.sqlite`) and delete it afterwards. They never touch production data.

The `reservations` suite reuses that double to drive provisioning end to end:
approve → queued job → run → record created → read back → `active`, plus the
negative cases (no target configured, a write that lands but whose response
dies, a job cancelled mid-flight).

The DNS suites need no network and no Cloudflare credential: the API is replaced
at `CloudflareApiClient::setDefaultTransport()` by an in-memory double
(`tests/support/CloudflareTestDouble.php`) that can script zones, records, rate
limits, 5xx responses and a "write landed but the response died" timeout. They
prove the engine behaves correctly against the documented API — not that a live
zone accepts it (see [Verifying the integration](#verifying-the-integration)).

The Clerk suites need no network and no real Clerk secret: tokens are signed with a committed **test-only** RSA key pair (`tests/fixtures/`), served to the verifier as a JWKS document, and the Backend API is replaced at `ClerkApiClient`'s transport seam. What they cannot prove is that a live Clerk instance issues tokens shaped the way we expect — that requires the owner's dashboard, see [Verifying against Clerk](#verifying-against-clerk).


### Verifying the dashboard

One command runs everything this page describes:

```bash
tests/dashboard.sh            # lint, unit suites, render harness, HTTP checks × 2
```

It builds its own throwaway SQLite database under `storage/`, seeds it, starts a
real server, and deletes it all on exit. It never touches `storage/dev.sqlite` or
a real installation.

| Harness | What it is | Checks |
|---------|------------|--------|
| `tests/dashboard-suite.php` (suite `dashboard`) | in-process assertions, part of `tests/run.php` | 77 |
| `tests/dashboard-render.php` | renders every page in-process and asserts on the HTML: statistics match the database, no secret appears, a foreign id yields the not-found page | 88 |
| `tests/http-dashboard.py` | real HTTP against a seeded database: auth boundary, cross-account isolation, CSRF, GET-never-mutates, release refusal, logout, post-logout denial | 83 |

`tests/http-dashboard.py` needs the seeded preview database and a running
server; `tests/dashboard.sh` arranges both.

| Claim | How to prove it | Done here? |
|-------|-----------------|------------|
| `DASHBOARD PAGES RENDER` | `tests/dashboard.sh` | **yes** |
| `OWNERSHIP ENFORCED` | cross-account checks in the render harness and the HTTP checks | **yes** |
| `CSRF ON EVERY MUTATION` | tokenless and forged-token POSTs return `419` and change nothing | **yes** |
| `NO SECRETS OR STACK TRACES` | forbidden-needle sweep over eight rendered pages | **yes** |
| `RESPONSIVE AT 320–1440` | structural (no fixed widths, cards instead of tables, wrapping hostnames) — **not visually confirmed**: no browser binary is reachable in the delivery environment | **no** |
| `LIVE CLOUDFLARE PROVISIONING SHOWN IN THE UI` | requires real credentials; the dashboard only displays what the database already recorded | **no** |

---

## Remaining work

Tracked in [`TASKS.md`](TASKS.md). Highlights:

1. **Enable public registration** — the engine, the queue and the CLI worker are
   built and tested against a scripted provider; what remains is an operator
   decision: a verified zone, a provisioning target, then `--run` on a throwaway
   name before opening the form.
2. **Scheduled worker** — nothing provisions on a timer today, deliberately. A
   cron entry that calls `php database/provision.php --run` would be safe (jobs
   are claimed atomically) but it makes DNS writes automatic, which this build
   refuses to do by default. Decide deliberately.
3. **Transactional email** for reservation/subdomain notifications (account e-mail verification and password reset are handled by Clerk).
4. **Public API tokens** for users (programmatic reservation management) with scoped keys.
5. **Admin audit log browser** with filtering and export.
6. **DNS pre-check** (wildcard detection, NS delegation check) before a domain is marked `ready`.
7. **i18n** and a full RTL stylesheet pass.
8. **Nginx sample config** in addition to `.htaccess`.
9. **Observability**: structured logs, health endpoint, retention policy for `audit_logs`.
10. **UI**: screenshot regression tests once a headless browser is available in CI.

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
| The sign-in form is blank or "Sign-in is not configured" | `CLERK_PUBLISHABLE_KEY` is missing/malformed. Run `php database/clerk-check.php` |
| Clerk renders, but signing in shows "reload the page and try again" | The page's channel expired (you left it open) — reload. If it persists, `CLERK_AUTHORIZED_PARTIES` does not match the origin in the address bar, or cookies are blocked |
| "Your Clerk instance is missing the freesub_handoff JWT template" | Create the template in the Clerk dashboard (Configure → JWT Templates), or set `CLERK_JWT_TEMPLATE` to the name you used |
| Sign-in succeeds but lands back on `/login.php` | `CLERK_SECRET_KEY` is wrong or missing, so the bridge refuses to mint a ticket (it returns `503`). `php database/clerk-check.php` exits `2` or `3` |
| "This email address is already linked to a different sign-in account" | Two Clerk identities claim one verified address. Resolve it with `php database/link-clerk.php --list`, then fix the duplicate in the Clerk dashboard |
| Webhook returns `503 not_configured` | `CLERK_WEBHOOK_SECRET` is empty. Copy the `whsec_…` value from the webhook endpoint in the dashboard |
| Webhook returns `401 invalid_signature` | The secret does not belong to that endpoint, the request was replayed (>5 min), or a proxy rewrote the body. Check `storage/logs/php-error.log` for the reason |
| "This account is managed offline" when signing in as an administrator | Expected: admin rows are never claimed by a web sign-in. Run `php database/link-clerk.php --email=… --clerk-email=…` from the server |
| Administrator lost access after the migration | The Clerk e-mail must be **verified** and must match the row exactly. Check `php database/link-clerk.php --unlinked`, then use `php database/break-glass.php` if needed |

---

## License

Project code: MIT. Bundled third-party assets keep their own licenses — see `assets/vendor/LICENSE.*.txt` and `assets/fonts/LICENSE.plus-jakarta-sans.txt` (Bootstrap MIT, Lucide ISC, Plus Jakarta Sans OFL).
