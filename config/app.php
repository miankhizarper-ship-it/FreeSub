<?php

declare(strict_types=1);

/**
 * Application configuration.
 *
 * Values are resolved once per request from the environment (.env + real
 * environment variables). Nothing in this file may ever be echoed to the
 * browser or exposed through public JavaScript.
 */

require_once __DIR__ . '/env.php';

if (!defined('FREESUB_ROOT')) {
    define('FREESUB_ROOT', dirname(__DIR__));
}

env_load(FREESUB_ROOT . '/.env');

$appEnv = (string) env('APP_ENV', 'production');
$appDebug = env_bool('APP_DEBUG', false);
$appUrl = trim((string) env('APP_URL', ''));

return [
    'name' => (string) env('APP_NAME', 'FreeSub'),
    'env' => $appEnv,
    'debug' => $appDebug,
    'url' => $appUrl !== '' ? rtrim($appUrl, '/') : null,
    'timezone' => (string) env('APP_TIMEZONE', 'UTC'),
    'key' => (string) env('APP_KEY', ''),

    'session' => [
        'name' => (string) env('SESSION_NAME', 'freesub_session'),
        'lifetime' => env_int('SESSION_LIFETIME', 120),
        'secure' => (string) env('SESSION_SECURE', 'auto'),
        'same_site' => (string) env('SESSION_SAMESITE', 'Lax'),
        'path' => '/',
        'save_path' => FREESUB_ROOT . '/storage/sessions',
    ],

    'security' => [
        'force_https' => env_bool('SECURITY_FORCE_HTTPS', false),
        'hsts' => env_bool('SECURITY_HSTS', false),
        'trusted_proxies' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TRUSTED_PROXIES', ''))
        ))),
        'login_max_attempts' => env_int('RATE_LIMIT_LOGIN_ATTEMPTS', 5),
        'login_window' => env_int('RATE_LIMIT_LOGIN_WINDOW', 900),
        'register_max_attempts' => env_int('RATE_LIMIT_REGISTER_ATTEMPTS', 5),
        'register_window' => env_int('RATE_LIMIT_REGISTER_WINDOW', 3600),
    ],

    'subdomains' => [
        'min_length' => env_int('SUBDOMAIN_MIN_LENGTH', 3),
        'max_length' => env_int('SUBDOMAIN_MAX_LENGTH', 32),
        'default_quota' => env_int('SUBDOMAIN_DEFAULT_QUOTA', 5),
        // Labels that must never be handed out, regardless of the root domain.
        'reserved_labels' => [
            'www', 'mail', 'smtp', 'imap', 'pop', 'pop3', 'webmail', 'ftp', 'sftp', 'ssh',
            'ns', 'ns1', 'ns2', 'ns3', 'dns', 'mx', 'mx1', 'mx2', 'autodiscover', 'autoconfig',
            'admin', 'administrator', 'root', 'system', 'support', 'help', 'billing', 'abuse',
            'api', 'app', 'apps', 'cdn', 'static', 'assets', 'img', 'images', 'media', 'files',
            'panel', 'cpanel', 'dashboard', 'status', 'dev', 'staging', 'test', 'demo', 'beta',
            'freesub', 'kharcha', 'secure', 'login', 'register', 'signup', 'account', 'my',
            'cloudflare', 'cpanel', 'whm', 'localhost', 'ip', 'server', 'host', 'hosting',
        ],
    ],

    'clerk' => [
        // Clerk publishes the frontend API host inside the publishable key, so a
        // single value is enough to boot the browser SDK.
        'publishable_key' => trim((string) env('CLERK_PUBLISHABLE_KEY', '')),
        // Server-side only. Never rendered, never logged, never in the database.
        'secret_key' => trim((string) env('CLERK_SECRET_KEY', '')),
        // Origin allowlist for the `azp` claim. Only our own origins belong here.
        'authorized_parties' => array_values(array_filter(array_map('trim', explode(
            ',',
            (string) env('CLERK_AUTHORIZED_PARTIES', '')
        )))),
        'webhook_secret' => trim((string) env('CLERK_WEBHOOK_SECRET', '')),
        // Space/comma separated Clerk audience values, if the instance sets any.
        'audiences' => array_values(array_filter(array_map('trim', preg_split(
            '/[\s,]+/',
            (string) env('CLERK_AUDIENCES', '')
        ) ?: []))),
        // JWT template that the browser mints the handoff token from. Must exist
        // in the Clerk dashboard (see README) or sign-in reports `template_missing`.
        // Pinned version of the official browser SDK loaded from the instance's
        // own frontend API host (no third-party CDN dependency at runtime).
        'js_version' => (string) env('CLERK_JS_VERSION', '6.46.3'),
        'jwt_template' => (string) env('CLERK_JWT_TEMPLATE', 'freesub_handoff'),
        'frontend_api_host' => trim((string) env('CLERK_FRONTEND_API', '')),
        'backend_api_url' => rtrim((string) env('CLERK_BACKEND_API', 'https://api.clerk.com'), '/'),
        'jwks_url' => trim((string) env('CLERK_JWKS_URL', '')),
        'clock_skew' => env_int('CLERK_CLOCK_SKEW', 5),
        'jwks_cache_ttl' => env_int('CLERK_JWKS_CACHE_TTL', 300),
        // How long a locally established session is trusted before Clerk is
        // asked again whether the session is still alive.
        'session_recheck' => env_int('CLERK_SESSION_RECHECK', 60),
        'handoff_ttl' => env_int('CLERK_HANDOFF_TTL', 120),
        'ticket_ttl' => env_int('CLERK_TICKET_TTL', 60),
        'auto_provision' => env_bool('CLERK_AUTO_PROVISION', true),
        'break_glass' => env_bool('CLERK_BREAK_GLASS', false),
    ],

    'dns' => [
        // Integration is intentionally not implemented yet; the interface exists
        // so the Cloudflare driver can be dropped in without touching callers.
        'provider' => (string) env('DNS_PROVIDER', 'none'),
        'cloudflare' => [
            'api_token' => (string) env('CLOUDFLARE_API_TOKEN', ''),
            'account_id' => (string) env('CLOUDFLARE_ACCOUNT_ID', ''),
            'base_url' => (string) env('CLOUDFLARE_API_BASE', 'https://api.cloudflare.com/client/v4'),
        ],
    ],

    'contact' => [
        'abuse' => (string) env('ABUSE_EMAIL', 'abuse@kharcha.info'),
        'support' => (string) env('SUPPORT_EMAIL', 'support@kharcha.info'),
    ],
];
