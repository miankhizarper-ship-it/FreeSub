<?php

declare(strict_types=1);

/**
 * Admin: review reservation requests.
 *
 * Approving a reservation creates a subdomain row in "pending_dns" state.
 * It never claims the hostname is live and never writes DNS.
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$admin = Auth::requireAdmin();

if (is_post()) {
    csrf_check();

    $action = (string) input('action', '');
    $id = (int) input('id', 0);
    $note = trim((string) input('note', ''));
    $reservation = Reservation::find($id);

    if ($reservation === null) {
        flash('error', 'Reservation not found.');
        redirect('/admin/reservations.php');
    }

    if ($action === 'approve') {
        if ((string) $reservation['status'] !== 'pending') {
            flash('warning', 'Only pending reservations can be approved.');
            redirect('/admin/reservations.php');
        }

        $domain = Domain::find((int) $reservation['domain_id']);

        if ($domain === null) {
            flash('error', 'The root domain for this reservation no longer exists.');
            redirect('/admin/reservations.php');
        }

        if (Subdomain::labelTaken((int) $domain['id'], (string) $reservation['label'])) {
            flash('error', 'That label is already provisioned as a subdomain.');
            redirect('/admin/reservations.php');
        }

        Database::transaction(static function () use ($reservation, $domain, $admin, $note): void {
            $subdomainId = Subdomain::create(
                (int) $reservation['user_id'],
                (int) $domain['id'],
                (string) $reservation['label'],
                $reservation['label'] . '.' . $domain['root_domain'],
                (int) $reservation['id']
            );

            Reservation::updateStatus((int) $reservation['id'], 'approved', (int) $admin['id'], $note !== '' ? $note : null);

            audit_log(
                'admin.reservation_approved',
                (int) $admin['id'],
                ['fqdn' => $reservation['label'] . '.' . $domain['root_domain']],
                'subdomain',
                $subdomainId,
                'Reservation approved; awaiting DNS'
            );
        });

        flash('success', 'Reservation approved. The subdomain is queued as pending DNS.');
        redirect('/admin/reservations.php');
    }

    if (in_array($action, ['reject', 'cancel'], true)) {
        Reservation::updateStatus($id, $action === 'reject' ? 'rejected' : 'cancelled', (int) $admin['id'], $note !== '' ? $note : null);
        audit_log('admin.reservation_' . $action, (int) $admin['id'], [], 'reservation', $id, 'Reservation ' . $action . 'ed');
        flash('success', 'Reservation updated.');
        redirect('/admin/reservations.php');
    }

    flash('error', 'Unknown action.');
    redirect('/admin/reservations.php');
}

$status = (string) input('status', 'pending');
$allowedStatuses = ['pending', 'approved', 'rejected', 'cancelled', 'expired', 'all'];

if (!in_array($status, $allowedStatuses, true)) {
    $status = 'pending';
}

if ($status === 'all') {
    $reservations = Database::select(
        'SELECT r.*, d.root_domain, u.name AS user_name, u.email AS user_email
         FROM reservations r
         INNER JOIN domains d ON d.id = r.domain_id
         INNER JOIN users u ON u.id = r.user_id
         ORDER BY r.id DESC LIMIT 100'
    );
} else {
    $reservations = Database::select(
        'SELECT r.*, d.root_domain, u.name AS user_name, u.email AS user_email
         FROM reservations r
         INNER JOIN domains d ON d.id = r.domain_id
         INNER JOIN users u ON u.id = r.user_id
         WHERE r.status = :status
         ORDER BY r.id DESC LIMIT 100',
        ['status' => $status]
    );
}

view('admin/reservations', [
    'authUser' => $admin,
    'reservations' => $reservations,
    'status' => $status,
    'pageTitle' => 'Reservations',
    'activeNav' => 'admin-reservations',
], 'layouts/admin');
