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
| Composer / Node | Not required | Bootstrap, Lucide and the font are vendored in `assets/` |

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
| `APP_URL` | Absolute base URL (no trailing slash). Leave empty to auto-detect |
| `APP_KEY` | 32+ random characters; keep secret |
| `APP_TIMEZONE` | Storage is always UTC; this only affects display |
| `DB_DRIVER`, `DB_*` | `mysql` for production, `sqlite` for local experiments |
| `SESSION_*` | Cookie name, idle lifetime (minutes), `Secure` handling, `SameSite` |
| `SECURITY_FORCE_HTTPS`, `SECURITY_HSTS` | Redirect to HTTPS / emit HSTS once TLS is verified |
| `TRUSTED_PROXIES` | Comma-separated CIDRs; only then are `X-Forwarded-*` trusted |
| `RATE_LIMIT_*` | Login and registration throttles |
| `SUBDOMAIN_*` | Label length limits and per-domain default quota |
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
│   ├── schema.sql          MySQL/MariaDB schema + seed
│   ├── schema.sqlite.sql   SQLite mirror for local development/tests
│   ├── install.php         CLI installer (idempotent; --fresh to reset)
│   └── create-admin.php    CLI administrator provisioning
├── includes/
│   ├── bootstrap.php       Error policy, autoloader, headers, session, wiring
│   ├── auth.php            Sessions, hashing, login, guards
│   ├── database.php        PDO factory + query helpers
│   ├── functions.php       Escaping, URLs, CSRF, flash, audit, rate limiting, views
│   ├── validation.php      Registration, login, label and abuse validators
│   ├── models/             User, Domain, Reservation, Subdomain, DnsRecord, AbuseReport
│   ├── services/           DnsProvider (interface + null driver), SubdomainService
│   └── views/              Layouts, partials, pages, admin screens, error pages
├── storage/                Logs, cache, sessions (web-inaccessible, git-ignored)
├── tests/
│   ├── run.php             Test runner (schema, security, auth, subdomains, views)
│   └── http-journeys.php   End-to-end HTTP journeys via cURL
├── index.php               Landing page
├── login.php register.php logout.php
├── dashboard.php
├── extensions.php abuse.php 404.php
├── admin/ api/
├── router.php              Router for `php -S` (404 handling, dot-file blocking)
├── .env.example .htaccess README.md robots.txt sitemap.xml
```

**Layering.** Entry points (`*.php` in the project root, `admin/`, `api/`) only orchestrate: validate → call a model/service → redirect or render. All SQL lives in `includes/models/`, business rules in `includes/services/`, presentation in `includes/views/`. There is no framework and no Composer dependency, by design.

---

## Security model

**Authentication.** `password_hash(PASSWORD_DEFAULT, ['cost' => 12])`, `password_verify()` with a dummy hash for unknown emails (uniform timing), silent rehash when the algorithm changes. Sessions regenerate on login/logout; the stored user is re-loaded from the database on every request, so a suspended or deleted account loses access immediately.

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
php tests/run.php                 # unit + integration suites (isolated SQLite DB)
php tests/run.php security        # one suite: schema | security | auth | subdomains | audit | views
```

Optional end-to-end journeys (needs a running server):

```bash
php -S localhost:8000 router.php &
FREESUB_TEST_BASE_URL=http://localhost:8000 php tests/run.php http
```

Coverage highlights: schema shape, hashing/verification, HTML escaping, CSRF accept/reject, label and password rules, rate limiting, suspended-account lockout, quota enforcement, duplicate-fqdn rejection, "reservation never creates DNS", null-provider contract, multi-domain readiness, every redirect target and internal link existing on disk, blocked internal paths (`/config`, `/includes`, `/database`, `/tests`, dot files), and the full HTTP journey (landing → register → reserve → admin block → logout → login → abuse report).

Current status: **168 assertions green** (112 unit/integration + 56 HTTP) on PHP 8.5 with SQLite. The MySQL schema in `database/schema.sql` was validated against a real MariaDB 13.1 engine (import, generated column, FKs, cascades, idempotent re-run).

The tests use a throwaway database file (`storage/test-<pid>.sqlite`) and delete it afterwards. They never touch production data.

---

## Remaining work

Tracked in [`TASKS.md`](TASKS.md). Highlights:

1. **Cloudflare driver** implementing `DnsProviderInterface`, plus a queue/CLI worker that reconciles `dns_records` and flips subdomains to `active` only after verification.
2. **Email**: verification links, reservation/password-reset notifications (needs an SMTP/API integration).
3. **Password reset flow** and email change with re-verification.
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

---

## License

Project code: MIT. Bundled third-party assets keep their own licenses — see `assets/vendor/LICENSE.*.txt` and `assets/fonts/LICENSE.plus-jakarta-sans.txt` (Bootstrap MIT, Lucide ISC, Plus Jakarta Sans OFL).
