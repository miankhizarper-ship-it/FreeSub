<?php

declare(strict_types=1);

/**
 * Admin: user directory and account status.
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
        'SELECT id, name, email, role, status, created_at, last_login_at
         FROM users WHERE name LIKE :term OR email LIKE :term ORDER BY id DESC LIMIT 100',
        ['term' => '%' . $search . '%']
    );
} else {
    $users = User::recent(50);
}

view('admin/users', [
    'authUser' => $admin,
    'users' => $users,
    'search' => $search,
    'pageTitle' => 'Users',
    'activeNav' => 'admin-users',
], 'layouts/admin');
