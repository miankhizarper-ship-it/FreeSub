<?php

declare(strict_types=1);

/**
 * Sign out (POST only, CSRF protected).
 *
 * Two sessions exist and both must end:
 *
 *   1. the local PHP session  — destroyed here, immediately;
 *   2. the Clerk browser session — ended by clerk-auth.js after the redirect,
 *      because only the browser can clear Clerk's own cookies.
 *
 * If the Clerk SDK cannot load, the local session is still gone, so the user is
 * signed out from FreeSub's point of view either way.
 */

require_once __DIR__ . '/includes/bootstrap.php';

if (!is_post()) {
    // Never sign anyone out on a GET/HEAD request (link prefetchers, scanners).
    redirect(Auth::check() ? '/dashboard.php' : '/');
}

csrf_check();

$clerkSessionId = $_SESSION[Auth::CLERK_SESSION_KEY] ?? null;

Auth::logout();

// Best effort: revoke the Clerk session server-side as well. A failure here is
// harmless — the local session is already invalid and the browser clears its
// own Clerk session on the next page — so it must never block the sign-out.
if (is_string($clerkSessionId) && $clerkSessionId !== '' && ClerkConfig::secretKeyConfigured()) {
    try {
        (new ClerkApiClient())->revokeSession($clerkSessionId);
    } catch (Throwable $exception) {
        error_log('[freesub] clerk session revoke skipped: ' . $exception->getMessage());
    }
}

flash('success', 'You have been signed out.');

redirect(ClerkConfig::isConfigured() ? '/login.php?signed_out=1' : '/login.php');
