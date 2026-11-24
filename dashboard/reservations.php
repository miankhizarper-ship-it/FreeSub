<?php

declare(strict_types=1);

/**
 * Reservations & history (Task 05 §8).
 *
 * Scoped to the signed-in account with `Reservation::history($userId, …)`, which
 * filters on `user_id` inside the SQL. A user can never read someone else's
 * request, including through the `status` filter, because the filter only runs
 * over the rows that query already returned.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

$user = Auth::requireAuth('/dashboard/reservations.php');
$userId = (int) $user['id'];

if (is_post()) {
    csrf_check();

    if ((string) input('action', '') === 'cancel_reservation') {
        $result = ReservationService::cancel($user, (int) input('id', 0));
        flash($result['ok'] ? 'success' : 'error', (string) $result['message']);
        redirect('/dashboard/reservations.php');
    }

    flash('error', 'That action is not available.');
    redirect('/dashboard/reservations.php');
}

$rows = ReservationService::history($userId, 200);

// The subdomain a reservation turned into, resolved in one query rather than a
// lookup per row (Task 05 §21).
$linked = [];
$reservationIds = array_values(array_filter(array_map(
    static fn (array $row): int => (int) ($row['id'] ?? 0),
    $rows
)));

if ($reservationIds !== []) {
    $subdomains = Subdomain::forUser($userId, 500);

    foreach ($subdomains as $subdomain) {
        $reservationId = (int) ($subdomain['reservation_id'] ?? 0);

        if ($reservationId > 0) {
            $linked[$reservationId] = $subdomain;
        }
    }
}

$counts = ['all' => count($rows)];

foreach (DashboardStatus::reservationStates() as $state) {
    $counts[$state] = 0;
}

foreach ($rows as $row) {
    $state = (string) $row['status'];

    if (isset($counts[$state])) {
        $counts[$state]++;
    }
}

$filter = (string) input('status', 'all');
$allowed = array_merge(['all'], DashboardStatus::reservationStates());

if (!in_array($filter, $allowed, true)) {
    $filter = 'all';
}

$visible = $filter === 'all'
    ? $rows
    : array_values(array_filter($rows, static fn (array $row): bool => (string) $row['status'] === $filter));

view('dashboard/reservations', [
    'authUser' => $user,
    'reservations' => $visible,
    'linked' => $linked,
    'counts' => $counts,
    'total' => count($rows),
    'filter' => $filter,
    'domains' => Domain::available(),
    'pageTitle' => 'Reservations',
    'pageSubtitle' => 'Every name you have requested, and what happened to it.',
    'activeNav' => 'reservations',
], 'layouts/dashboard');
