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
