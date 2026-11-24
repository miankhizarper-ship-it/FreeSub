<?php

declare(strict_types=1);

/**
 * Admin: review reservation requests and drive the provisioning queue.
 *
 * Two invariants this page is built around:
 *
 *   * Approving never activates anything. It creates a `pending_dns` subdomain
 *     and one queued job; the name becomes `active` only after the queue's
 *     read-back from the provider succeeds.
 *   * Provisioning is triggered by an administrator pressing a button (or the
 *     CLI). Nothing here runs on a timer and nothing runs on a page load.
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$admin = Auth::requireAdmin();

if (is_post()) {
    csrf_check();

    $action = (string) input('action', '');
    $id = (int) input('id', 0);
    $note = trim((string) input('note', ''));

    switch ($action) {
        case 'approve':
        case 'reject':
            $result = $action === 'approve'
                ? ReservationService::approve($admin, $id, $note)
                : ReservationService::reject($admin, $id, $note);

            flash($result['ok'] ? 'success' : 'error', (string) $result['message']);
            redirect('/admin/reservations.php?status=' . ($action === 'approve' ? 'approved' : 'pending'));

        case 'run_job':
            $result = ProvisioningQueue::run($id, $admin);
            flash($result['ok'] ? 'success' : 'error', (string) $result['message']);
            redirect('/admin/reservations.php#provisioning');

        case 'retry_job':
            $result = ProvisioningQueue::retry($id, (int) $admin['id']);
            flash($result['ok'] ? 'success' : 'error', (string) $result['message']);
            redirect('/admin/reservations.php#provisioning');

        case 'cancel_job':
            $result = ProvisioningQueue::cancel($id, (int) $admin['id']);
            flash($result['ok'] ? 'success' : 'error', (string) $result['message']);
            redirect('/admin/reservations.php#provisioning');

        case 'expire_lapsed':
            $count = ReservationService::expireLapsed();
            flash('success', $count === 0
                ? 'No reservations were past their expiry.'
                : $count . ' reservation' . ($count === 1 ? '' : 's') . ' expired.');
            redirect('/admin/reservations.php?status=pending');

        default:
            flash('error', 'Unknown action.');
            redirect('/admin/reservations.php');
    }
}

$status = (string) input('status', 'pending');
$allowedStatuses = ['pending', 'approved', 'rejected', 'cancelled', 'expired', 'all'];

if (!in_array($status, $allowedStatuses, true)) {
    $status = 'pending';
}

$search = trim((string) input('q', ''));
$domainFilter = (int) input('domain_id', 0);

$where = [];
$params = [];

if ($status !== 'all') {
    $where[] = 'r.status = :status';
    $params['status'] = $status;
}

if ($domainFilter > 0) {
    $where[] = 'r.domain_id = :domain_id';
    $params['domain_id'] = $domainFilter;
}

if ($search !== '') {
    // Escape LIKE wildcards so a literal % cannot turn into a full scan.
    $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search));
    $where[] = "(LOWER(r.label) LIKE :q ESCAPE '\\' OR LOWER(u.email) LIKE :q ESCAPE '\\')";
    $params['q'] = '%' . $escaped . '%';
}

$sql = 'SELECT r.*, d.root_domain, u.name AS user_name, u.email AS user_email
          FROM reservations r
          INNER JOIN domains d ON d.id = r.domain_id
          INNER JOIN users u ON u.id = r.user_id';

if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY r.id DESC LIMIT 200';

$reservations = Database::select($sql, $params);

// Provisioning jobs, joined so the admin can see exactly which request each job
// belongs to and why a job is not progressing.
$jobs = Database::select(
    "SELECT j.*, u.email AS requester_email,
            s.status AS subdomain_status,
            d.root_domain
       FROM provisioning_jobs j
       LEFT JOIN users u ON u.id = j.requested_by
       LEFT JOIN subdomains s ON s.id = j.subdomain_id
       INNER JOIN domains d ON d.id = j.domain_id
      ORDER BY CASE j.status
                 WHEN 'failed' THEN 0
                 WHEN 'running' THEN 1
                 WHEN 'pending' THEN 2
                 ELSE 3
               END, j.id DESC
      LIMIT 100"
);

view('admin/reservations', [
    'authUser' => $admin,
    'reservations' => $reservations,
    'jobs' => $jobs,
    'jobCounts' => ProvisioningQueue::counts(),
    'status' => $status,
    'search' => $search,
    'domainFilter' => $domainFilter,
    'domains' => Domain::all(),
    'dnsEnabled' => (new DnsManager())->isEnabled(),
    'pageTitle' => 'Reservations',
    'activeNav' => 'admin-reservations',
], 'layouts/admin');
