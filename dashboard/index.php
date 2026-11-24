<?php

declare(strict_types=1);

/**
 * `/dashboard/` — the dashboard directory has no index of its own.
 *
 * Everything under this directory is a distinct screen with its own file, and
 * the overview lives at `/dashboard.php` rather than `/dashboard/index.php`.
 * That makes a bare `/dashboard/` a request for a directory listing, which some
 * servers would answer with a listing and others with a 403; neither is useful,
 * so a directory request lands on the overview instead.
 *
 * The redirect is authoritative rather than relative so that it works the same
 * when the app is mounted under a sub-path. It is `302`, not `301`: the
 * dashboard's canonical URL is not a permanent contract, and a cached permanent
 * redirect would outlive any future change.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

Auth::requireAuth('/dashboard.php');

redirect('/dashboard.php');
