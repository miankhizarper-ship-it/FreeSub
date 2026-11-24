<?php

declare(strict_types=1);

/**
 * Admin: user directory, account status and per-user subdomain quota.
 *
 * The quota override is the "give this customer more slots" control. It is
 * deliberately per-user (rather than per-domain) so a shared root domain can
 * still treat accounts differently, and it is audited on every change.
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$admin = Auth::requireAdmin();

if (is_post()) {
    csrf_check();

    $id = (int) input('id', 0);
    $action = (string) input('action', '');
    $target = User::find($id);

    if ($target === null) {
        flash('error', 'User not found.');
        redirect('/admin/users.php');
    }

    // Quota changes are allowed on any account, including your own: they grant
    // capacity rather than privilege, so there is no self-escalation here.
    if ($action === 'quota') {
        $raw = trim((string) input('quota', ''));
        // An empty field means "inherit the domain default" — clearing a
        // per-user override is a distinct action from setting 0.
        $limit = $raw === '' ? null : max(0, (int) $raw);
        $result = SubdomainQuota::setLimit((int) $target['id'], $limit, (int) $admin['id']);

        flash($result['ok'] ? 'success' : 'error', (string) $result['message']);
        redirect('/admin/users.php');
    }

    if ($id === (int) $admin['id']) {
        flash('error', 'You cannot change the status of your own account.');
        redirect('/admin/users.php');
    }

    $status = $action === 'suspend' ? 'suspended' : ($action === 'activate' ? 'active' : null);

    if ($status === null) {
        flash('error', 'Unknown action.');
        redirect('/admin/users.php');
    }

    Database::execute(
        'UPDATE users SET status = :status, updated_at = :updated_at WHERE id = :id',
        ['status' => $status, 'updated_at' => now(), 'id' => $id]
    );

    audit_log('admin.user_' . $action, (int) $admin['id'], ['target' => $id], 'user', $id, 'User status set to ' . $status);
    flash('success', 'Account status updated.');
    redirect('/admin/users.php');
}

$search = trim((string) input('q', ''));
$users = [];

if ($search !== '') {
    $users = Database::select(
        'SELECT id, name, email, role, status, subdomain_quota, created_at, last_login_at
         FROM users WHERE name LIKE :term OR email LIKE :term ORDER BY id DESC LIMIT 100',
        ['term' => '%' . $search . '%']
    );
} else {
    $users = Database::select(
        'SELECT id, name, email, role, status, subdomain_quota, created_at, last_login_at
           FROM users ORDER BY id DESC LIMIT 50'
    );
}

// Usage per row, computed with one grouped query instead of N.
$usage = [];

if ($users !== []) {
    $ids = array_map(static fn (array $row): int => (int) $row['id'], $users);
    $placeholders = implode(', ', array_fill(0, count($ids), '?'));

    $rows = Database::select(
        "SELECT u.id AS user_id,
                (SELECT COUNT(*) FROM subdomains s WHERE s.user_id = u.id AND s.status <> 'terminated') AS active_count,
                (SELECT COUNT(*) FROM reservations r WHERE r.user_id = u.id AND r.status IN ('pending', 'approved')) AS reserved_count
           FROM users u
          WHERE u.id IN ($placeholders)",
        $ids
    );

    foreach ($rows as $row) {
        $usage[(int) $row['user_id']] = [
            'active' => (int) $row['active_count'],
            'reserved' => (int) $row['reserved_count'],
        ];
    }
}

view('admin/users', [
    'authUser' => $admin,
    'users' => $users,
    'usage' => $usage,
    'defaultQuota' => (int) config('subdomains.initial_quota', 2),
    'search' => $search,
    'pageTitle' => 'Users',
    'activeNav' => 'admin-users',
], 'layouts/admin');
