<?php

declare(strict_types=1);

/**
 * Application bootstrap.
 *
 * Every public entry point (pages, API endpoints, admin screens, cron) includes
 * this file first. It establishes the error handling policy, hardens the
 * response headers, starts the session securely and wires the autoloader.
 */

if (defined('FREESUB_BOOTSTRAPPED')) {
    return;
}

define('FREESUB_BOOTSTRAPPED', true);
define('FREESUB_START', microtime(true));

$root = dirname(__DIR__);

if (!defined('FREESUB_ROOT')) {
    define('FREESUB_ROOT', $root);
}

require_once FREESUB_ROOT . '/config/app.php';

require_once FREESUB_ROOT . '/includes/database.php';
require_once FREESUB_ROOT . '/includes/functions.php';
require_once FREESUB_ROOT . '/includes/validation.php';

Database::configure(require FREESUB_ROOT . '/config/database.php');

date_default_timezone_set((string) config('timezone', 'UTC'));

mb_internal_encoding('UTF-8');

/* -------------------------------------------------------------------------
 * Error policy: log everything, expose details only while debugging locally.
 * ---------------------------------------------------------------------- */
ini_set('display_errors', app_debug() ? '1' : '0');
ini_set('display_startup_errors', app_debug() ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', FREESUB_ROOT . '/storage/logs/php-error.log');
error_reporting(E_ALL);

/* -------------------------------------------------------------------------
 * Simple PSR-4 style autoloader for includes/ classes.
 * ---------------------------------------------------------------------- */
spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'FreeSub\\' => FREESUB_ROOT . '/includes/',
    ];

    foreach ($prefixes as $prefix => $directory) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $relative = str_replace('\\', '/', $relative);

        // Support both StudlyCase folders (Services/UserService) and flat files.
        $candidates = [
            $directory . $relative . '.php',
            $directory . strtolower($relative) . '.php',
        ];

        $segments = explode('/', $relative);
        $last = array_pop($segments);
        $candidates[] = $directory . implode('/', array_map('strtolower', $segments))
            . ($segments !== [] ? '/' : '') . $last . '.php';

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                require_once $candidate;

                return;
            }
        }
    }
});

/* -------------------------------------------------------------------------
 * Ensure writable runtime directories exist. Fail loudly in development,
 * silently degrade in production (never write into the document root).
 * ---------------------------------------------------------------------- */
foreach (['storage/logs', 'storage/cache', 'storage/sessions'] as $directory) {
    $path = FREESUB_ROOT . '/' . $directory;

    if (!is_dir($path)) {
        @mkdir($path, 0770, true);
    }

    if (!is_dir($path) || !is_writable($path)) {
        if (app_debug()) {
            error_log('[freesub] runtime directory is not writable: ' . $path);
        }
    }
}

/* -------------------------------------------------------------------------
 * Last-resort handlers: never leak stack traces or paths in production.
 * ---------------------------------------------------------------------- */
set_exception_handler(static function (Throwable $exception): void {
    error_log(sprintf(
        '[freesub] uncaught %s: %s in %s:%d',
        $exception::class,
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));

    if (headers_sent()) {
        echo app_debug() ? e($exception->getMessage()) : 'An unexpected error occurred.';

        return;
    }

    http_response_code(500);

    if (app_debug()) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<h1 style="font-family:system-ui">Application error</h1>';
        echo '<p style="font-family:ui-monospace">' . e($exception->getMessage()) . '</p>';
        echo '<pre style="font-family:ui-monospace;white-space:pre-wrap">' . e($exception->getTraceAsString()) . '</pre>';

        return;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Something went wrong</title>'
        . '<meta name="viewport" content="width=device-width, initial-scale=1"></head>'
        . '<body style="font-family:system-ui;background:#f8fafc;color:#0f172a;display:grid;place-items:center;min-height:100vh;margin:0">'
        . '<main style="text-align:center;padding:2rem"><h1>Something went wrong</h1>'
        . '<p style="color:#475569">We could not complete that request. Please try again in a moment.</p>'
        . '<p><a href="' . e(app_url('/')) . '">Return to FreeSub</a></p></main></body></html>';
});

set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    // Respect the @ operator and silenced reporting levels.
    if ((error_reporting() & $severity) === 0) {
        return false;
    }

    // Deprecations are logged (PHP's own logger) but must never break a
    // request: third-party extensions routinely emit them on new PHP versions.
    if (in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

/* -------------------------------------------------------------------------
 * Security headers (safe defaults; Apache adds the same set in .htaccess).
 * ---------------------------------------------------------------------- */
if (!headers_sent()) {
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('X-Permitted-Cross-Domain-Policies: none');

    if ((bool) config('security.hsts', false) && is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    if ((bool) config('security.force_https', false) && !is_https() && PHP_SAPI !== 'cli') {
        $target = 'https://' . request_host() . (string) ($_SERVER['REQUEST_URI'] ?? '/');
        header('Location: ' . $target, true, 301);
        exit;
    }
}

/* -------------------------------------------------------------------------
 * Session + auth.
 * ---------------------------------------------------------------------- */
require_once FREESUB_ROOT . '/includes/auth.php';

if (PHP_SAPI !== 'cli') {
    Auth::startSession();
}

/* -------------------------------------------------------------------------
 * Subdomain service wiring (schema/repository layer, no DNS side effects).
 * ---------------------------------------------------------------------- */
require_once FREESUB_ROOT . '/includes/models/User.php';
require_once FREESUB_ROOT . '/includes/models/Domain.php';
require_once FREESUB_ROOT . '/includes/models/Subdomain.php';
require_once FREESUB_ROOT . '/includes/models/Reservation.php';
require_once FREESUB_ROOT . '/includes/models/DnsRecord.php';
require_once FREESUB_ROOT . '/includes/models/AbuseReport.php';
// The interface first: every driver implements it.
require_once FREESUB_ROOT . '/includes/services/DnsProvider.php';
require_once FREESUB_ROOT . '/includes/services/Dns/DnsLog.php';
require_once FREESUB_ROOT . '/includes/services/Dns/CloudflareApiClient.php';
require_once FREESUB_ROOT . '/includes/services/Dns/DnsRecordValidator.php';
require_once FREESUB_ROOT . '/includes/services/Dns/CloudflareDnsProvider.php';
require_once FREESUB_ROOT . '/includes/services/Dns/DnsRecordRepository.php';
require_once FREESUB_ROOT . '/includes/services/Dns/DnsManager.php';

/* -------------------------------------------------------------------------
 * Subdomain availability & reservation engine (Task 04).
 * Order matters: the label rules are used by the availability engine, which is
 * used by the reservation service, which is used by the provisioning queue.
 * ---------------------------------------------------------------------- */
require_once FREESUB_ROOT . '/includes/services/Subdomain/SubdomainLabel.php';
require_once FREESUB_ROOT . '/includes/services/Subdomain/AvailabilityCache.php';
require_once FREESUB_ROOT . '/includes/services/Subdomain/AvailabilityEngine.php';
require_once FREESUB_ROOT . '/includes/services/Subdomain/SubdomainQuota.php';
require_once FREESUB_ROOT . '/includes/services/Subdomain/ProvisioningQueue.php';
require_once FREESUB_ROOT . '/includes/services/Subdomain/ReservationService.php';
require_once FREESUB_ROOT . '/includes/services/SubdomainService.php';

/* -------------------------------------------------------------------------
 * Clerk identity provider (authentication).
 * Order matters: the token verifier is a dependency of ClerkConfig.
 * ---------------------------------------------------------------------- */
require_once FREESUB_ROOT . '/includes/services/Clerk/ClerkToken.php';
require_once FREESUB_ROOT . '/includes/services/Clerk/ClerkTokenVerifier.php';
require_once FREESUB_ROOT . '/includes/services/Clerk/ClerkConfig.php';
require_once FREESUB_ROOT . '/includes/services/Clerk/ClerkApiClient.php';
require_once FREESUB_ROOT . '/includes/services/Clerk/ClerkHandoff.php';
require_once FREESUB_ROOT . '/includes/services/Clerk/ClerkWebhook.php';
