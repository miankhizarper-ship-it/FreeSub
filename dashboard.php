<?php

declare(strict_types=1);

/**
 * Dashboard overview (Task 05 §4).
 *
 * This file is the canonical "signed in" landing page and keeps its URL, so
 * every existing link, the post-login redirect and the pre-Task-05 tests keep
 * working. It presents the same backend state as the rest of the dashboard and
 * owns no rules of its own:
 *
 *   * quota numbers come from SubdomainQuota (the service the reservation
 *     endpoint enforces), never from the view;
 *   * statuses come from the database and are described by DashboardStatus;
 *   * every read is scoped to Auth::id() — no request value selects a user.
 *
 * Mutations stay POST-only with a CSRF token. `release_subdomain` and
 * `cancel_reservation` live here because they were here before; they are
 * re-validated server-side (ownership in SQL, lifecycle rules in the services).
 */

require_once __DIR__ . '/includes/bootstrap.php';

$user = Auth::requireAuth('/dashboard.php');
$userId = (int) $user['id'];
$domains = Domain::available();

if (is_post()) {
    csrf_check();

    $action = (string) input('action', '');

    if ($action === 'cancel_reservation') {
        $result = ReservationService::cancel($user, (int) input('id', 0));
        flash($result['ok'] ? 'success' : 'error', (string) $result['message']);
        redirect('/dashboard/reservations.php');
    }

    if ($action === 'release_subdomain') {
        $id = (int) input('id', 0);

        // Ownership is in the SQL, so another account's id behaves exactly like
        // a non-existent one: nothing is disclosed, nothing is touched.
        $subdomain = $id > 0 ? Subdomain::findForUser($id, $userId) : null;

        if ($subdomain === null) {
            flash('error', 'That subdomain could not be found.');
            redirect('/dashboard/subdomains.php');
        }

        $status = (string) $subdomain['status'];
        $blocked = DashboardStatus::releaseBlockedReason($status);

        if ($blocked !== null) {
            // Refused, not silently done: an active name needs the audited DNS
            // cleanup path, which is a support action in this build.
            flash('warning', $blocked);
            redirect('/dashboard/subdomain.php?id=' . (int) $subdomain['id']);
        }

        if (!rate_limit_hit('release:' . $userId, 10, 300)) {
            flash('error', 'You are sending requests too quickly. Please wait a few minutes and try again.');
            redirect('/dashboard/subdomain.php?id=' . (int) $subdomain['id']);
        }

        ProvisioningQueue::cancelForSubdomain((int) $subdomain['id'], null);
        Subdomain::deleteForUser((int) $subdomain['id'], $userId);
        audit_log('subdomain.released', $userId, ['fqdn' => $subdomain['fqdn']], 'subdomain', (int) $subdomain['id'], 'Subdomain released from dashboard');

        flash('success', 'Released ' . (string) $subdomain['fqdn'] . '. The label is available again.');
        redirect('/dashboard/subdomains.php');
    }

    // Unknown actions are a programming error, not user input: say so plainly
    // and never fall through to a read.
    flash('error', 'That action is not available.');
    redirect('/dashboard.php');
}

/* ---------------------------------------------------------------------- */
/* Read model — three queries, no loops over the database.                */
/* ---------------------------------------------------------------------- */

$buckets = ReservationService::dashboard($userId);
$quota = SubdomainQuota::overview($user, $domains);
$byDomain = SubdomainQuota::byDomain($user, $domains);

$subdomains = $buckets['active'];
$pendingReservations = $buckets['pending'];

$provisioning = Subdomain::provisioningForMany(array_map(
    static fn (array $row): int => (int) $row['id'],
    array_slice($subdomains, 0, 6)
));

$counts = [
    'active' => 0,
    'pending_dns' => 0,
    'failed' => 0,
];

foreach ($subdomains as $row) {
    $status = (string) $row['status'];

    if (isset($counts[$status])) {
        $counts[$status]++;
    }
}

$firstName = trim(explode(' ', trim((string) $user['name']))[0] ?? '');
$accountState = DashboardStatus::account((string) ($user['status'] ?? 'active'));

view('dashboard/overview', [
    'authUser' => $user,
    'subdomains' => $subdomains,
    'provisioning' => $provisioning,
    'counts' => $counts,
    'pendingReservations' => $pendingReservations,
    'quota' => $quota,
    'quotaByDomain' => $byDomain,
    'domains' => $domains,
    'firstName' => $firstName !== '' ? $firstName : 'there',
    'accountState' => $accountState,
    'welcome' => input('welcome') === '1',
    'pageTitle' => 'Overview',
    'pageSubtitle' => 'Your subdomains, reservations and account at a glance.',
    'activeNav' => 'overview',
], 'layouts/dashboard');
