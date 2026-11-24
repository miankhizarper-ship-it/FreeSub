<?php

declare(strict_types=1);

/**
 * Admin: abuse report triage.
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$admin = Auth::requireAdmin();

if (is_post()) {
    csrf_check();

    $id = (int) input('id', 0);
    $action = (string) input('action', '');
    $note = trim((string) input('note', ''));

    $map = ['review' => 'reviewing', 'resolve' => 'resolved', 'dismiss' => 'dismissed'];

    if (isset($map[$action])) {
        Database::execute(
            'UPDATE abuse_reports
             SET status = :status, handled_by = :handled_by, handled_at = :handled_at, resolution_note = :note, updated_at = :updated_at
             WHERE id = :id',
            [
                'status' => $map[$action],
                'handled_by' => (int) $admin['id'],
                'handled_at' => now(),
                'note' => $note !== '' ? mb_substr($note, 0, 1000) : null,
                'updated_at' => now(),
                'id' => $id,
            ]
        );

        audit_log('admin.abuse_' . $action, (int) $admin['id'], [], 'abuse_report', $id, 'Abuse report ' . $map[$action]);
        flash('success', 'Report updated.');
    } else {
        flash('error', 'Unknown action.');
    }

    redirect('/admin/abuse.php');
}

$reports = AbuseReport::recent(50);

view('admin/abuse', [
    'authUser' => $admin,
    'reports' => $reports,
    'pageTitle' => 'Abuse reports',
    'activeNav' => 'admin-abuse',
], 'layouts/admin');
