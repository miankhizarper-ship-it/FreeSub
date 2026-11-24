<?php

declare(strict_types=1);

/**
 * Sign out (POST only, CSRF protected).
 */

require_once __DIR__ . '/includes/bootstrap.php';

if (!is_post()) {
    // Never sign anyone out on a GET/HEAD request (link prefetchers, scanners).
    redirect(Auth::check() ? '/dashboard.php' : '/');
}

csrf_check();

Auth::logout();

flash('success', 'You have been signed out.');

redirect('/login.php');
