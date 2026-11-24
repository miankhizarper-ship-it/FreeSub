<?php

declare(strict_types=1);

/**
 * Throwaway sign-in for local previews of the dashboard (Task 05).
 *
 * The real sign-in path is Clerk, which needs network access and real keys. To
 * look at the dashboard while working on it, this page establishes the *same*
 * PHP session a completed Clerk handoff would establish, for one of the seeded
 * preview accounts.
 *
 * Safety rails, all deliberate:
 *   * it refuses to run unless APP_ENV is explicitly `local` or `testing`;
 *   * it refuses to run unless the database is the seeded preview database
 *     (`storage/dashboard-preview.sqlite`, created by tests/seed-dashboard.php);
 *   * it only ever signs in the two seed accounts by e-mail, never an id or a
 *     role taken from the request;
 *   * it is a GET, so it cannot be triggered by a cross-site form post.
 *
 * It is not part of the product and nothing in the app links to it. Delete the
 * file (and the preview database) when you are done.
 */

require_once __DIR__ . '/includes/bootstrap.php';

$environment = strtolower((string) config('env', 'production'));
// The database config lives in its own file (not the `app` config), so it has
// to be read from there rather than through config('database.…').
$databasePath = (string) (require FREESUB_ROOT . '/config/database.php')['sqlite_path'];
$isPreviewDatabase = str_contains($databasePath, 'dashboard-preview');

if (!in_array($environment, ['local', 'testing'], true) || !$isPreviewDatabase) {
    http_response_code(404);
    view('errors/404', ['pageTitle' => 'Page not found'], 'layouts/public');

    return;
}

$who = (string) input('a', 'owner');
$email = $who === 'other' ? 'other@example.test' : 'ayesha@example.test';

$user = User::findByEmail($email);

if ($user === null) {
    http_response_code(404);
    echo 'Seed the preview database first: php tests/seed-dashboard.php';
    return;
}

$clerkUserId = (string) ($user['clerk_user_id'] ?? '');

if (!User::isClerkUserId($clerkUserId)) {
    http_response_code(500);
    echo 'That seed account has no Clerk identity.';
    return;
}

// Same bookkeeping a real handoff performs — including the fingerprint and
// activity keys, without which Auth::startSession() correctly treats the
// session as hijacked and signs it straight back out.
session_regenerate_id(true);
$_SESSION[Auth::SESSION_KEY] = (int) $user['id'];
$_SESSION[Auth::CLERK_KEY] = $clerkUserId;
$_SESSION[Auth::FINGERPRINT_KEY] = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));
$_SESSION['_last_activity'] = time();
$_SESSION['_started_at'] = time();

flash('info', 'Preview sign-in as ' . (string) $user['email'] . '.');

redirect('/dashboard.php');
