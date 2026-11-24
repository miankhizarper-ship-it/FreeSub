# FreeSub — Tasks

Status legend: **Done** · **Next** · **Later**

## Task 01 — Foundation (this delivery)

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

## Task 02 — DNS automation (Next)

- [ ] `CloudflareDnsProvider implements DnsProviderInterface` (token from `.env`, zone id from `domains.zone_reference`)
- [ ] Reconciliation CLI (`php database/dns-sync.php`) that walks `dns_records` where `status = 'pending'`
- [ ] Verify the record through a DNS lookup before flipping a subdomain to `active`
- [ ] Retry/backoff and `last_error` reporting; idempotent create/update/delete
- [ ] Deletion workflow for released subdomains (remove remote records first, then mark `terminated`)
- [ ] Admin UI to inspect per-subdomain DNS state and re-run sync

## Task 03 — Accounts & email (Next)

- [ ] Email verification for new accounts (`email_verified_at` already exists)
- [ ] Password reset with single-use, expiring tokens (new table)
- [ ] Transactional email for reservation status changes
- [ ] Optional 2FA (TOTP) for administrators
- [ ] Session/device management ("sign out everywhere")

## Task 04 — Operations & hardening (Later)

- [ ] Nginx sample configuration with the same denies and headers
- [ ] Structured logging + log rotation; retention policy for `audit_logs`
- [ ] Health endpoint (`/health.php`) for uptime checks
- [ ] Admin audit-log browser with filters and CSV export
- [ ] Bulk operations in the reservation queue
- [ ] Database backup/restore documentation
- [ ] CI pipeline: lint (`php -l`), test suite, optional MySQL service container
- [ ] Screenshot/visual regression tests once a headless browser is available

## Task 05 — Product polish (Later)

- [ ] User-facing API keys for programmatic reservation management
- [ ] Public status page for DNS incidents
- [ ] i18n scaffolding and RTL stylesheet pass
- [ ] Dark mode option for the dashboard
- [ ] Onboarding checklist for first-time users
- [ ] Subdomain "parking" page template so reserved names resolve to something helpful
