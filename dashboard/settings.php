<?php

declare(strict_types=1);

/**
 * Account & settings (Task 05 §13).
 *
 * Read-only apart from the sign-out form. That is the honest design: FreeSub has
 * no credential store for the user (Clerk owns sign-in, e-mail verification,
 * MFA and password reset), and the name and e-mail on the account are copies of
 * a *verified* Clerk identity. Editing them here would either be a no-op that
 * silently reverts on the next sign-in, or a way to spoof an address the user
 * never proved. Neither is acceptable, so this page explains where to change
 * them instead of pretending to.
 *
 * Nothing privileged is rendered: no `clerk_user_id`, no session id, no token,
 * no role control. Roles are PHP/MySQL state changed only by the audited admin
 * path.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

$user = Auth::requireAuth('/dashboard/settings.php');
$userId = (int) $user['id'];
$domains = Domain::available();

if (is_post()) {
    csrf_check();

    // The only mutation offered here is signing out, and /logout.php owns it.
    flash('error', 'That action is not available.');
    redirect('/dashboard/settings.php');
}

$quota = SubdomainQuota::overview($user, $domains);
$byDomain = SubdomainQuota::byDomain($user, $domains);
$subdomainCount = Subdomain::countForUser($userId);
$reservationCount = Reservation::countForUser($userId);
$accountState = DashboardStatus::account((string) ($user['status'] ?? 'active'));

// Safe metadata only: facts about the account that the owner can see, nothing
// that identifies the session or the upstream identity.
$metadata = [
    'Member since' => format_date((string) ($user['created_at'] ?? null), 'M j, Y'),
    'Last sign-in' => format_date((string) ($user['last_login_at'] ?? null), 'M j, Y H:i'),
    'E-mail verified' => ($user['email_verified_at'] ?? null) !== null
        ? format_date((string) $user['email_verified_at'], 'M j, Y')
        : null,
];

view('dashboard/settings', [
    'authUser' => $user,
    'accountState' => $accountState,
    'quota' => $quota,
    'byDomain' => $byDomain,
    'subdomainCount' => $subdomainCount,
    'reservationCount' => $reservationCount,
    'metadata' => $metadata,
    'pageTitle' => 'Account',
    'pageSubtitle' => 'Your profile, quota and sign-in details.',
    'activeNav' => 'settings',
], 'layouts/dashboard');
