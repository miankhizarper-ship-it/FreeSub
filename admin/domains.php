<?php

declare(strict_types=1);

/**
 * Admin: root domain management.
 *
 * Adding a root domain here is intentionally a data operation: the public
 * search, the quota logic and the extension listing all read from the table.
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$admin = Auth::requireAdmin();
$errors = errors();
$values = ['root_domain' => old('root_domain'), 'label' => old('label'), 'description' => old('description')];

if (is_post()) {
    csrf_check();

    $action = (string) input('action', '');

    if ($action === 'create_domain') {
        $rootDomain = mb_strtolower(trim((string) input('root_domain', '')));
        $label = trim((string) input('label', ''));
        $description = trim((string) input('description', ''));
        $quota = (int) input('subdomain_quota', (int) config('subdomains.default_quota', 5));

        if (preg_match('/^(?=.{4,190}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $rootDomain) !== 1) {
            $errors['root_domain'] = 'Enter a valid apex domain, for example example.com (no protocol, no subdomain).';
        } elseif (Domain::findByRoot($rootDomain) !== null) {
            $errors['root_domain'] = 'That root domain already exists.';
        }

        if ($label === '') {
            $label = $rootDomain;
        }

        if ($quota < 1 || $quota > 500) {
            $errors['subdomain_quota'] = 'Quota must be between 1 and 500.';
        }

        if ($errors === []) {
            try {
                Database::execute(
                    'INSERT INTO domains (root_domain, label, description, dns_provider, registration_status, is_active, is_public, subdomain_quota, sort_order, created_at, updated_at)
                     VALUES (:root_domain, :label, :description, :provider, :status, 1, 1, :quota, :sort_order, :created_at, :updated_at)',
                    [
                        'root_domain' => $rootDomain,
                        'label' => mb_substr($label, 0, 100),
                        'description' => $description !== '' ? mb_substr($description, 0, 255) : null,
                        'provider' => (string) config('dns.provider', 'none'),
                        'status' => 'draft',
                        'quota' => $quota,
                        'sort_order' => (int) Domain::count(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );

                $newId = Database::lastInsertId();
                audit_log('admin.domain_created', (int) $admin['id'], ['root_domain' => $rootDomain], 'domain', $newId, 'Root domain added');
                flash('success', 'Root domain added as a draft. Activate it once DNS is pointed at FreeSub.');
                clear_old_input();
                redirect('/admin/domains.php');
            } catch (PDOException $exception) {
                $errors['root_domain'] = Database::errorCode($exception) === 'duplicate'
                    ? 'That root domain already exists.'
                    : 'The root domain could not be saved.';
            }
        }

        flash_errors($errors);
        flash_old_input($_POST);
        redirect('/admin/domains.php');
    }

    if ($action === 'toggle_domain') {
        $domainId = (int) input('id', 0);
        $domain = Domain::find($domainId);

        if ($domain !== null) {
            $newState = !$domain['is_active'];

            Database::execute(
                'UPDATE domains SET is_active = :active, updated_at = :updated_at WHERE id = :id',
                ['active' => $newState ? 1 : 0, 'updated_at' => now(), 'id' => $domainId]
            );

            audit_log('admin.domain_toggled', (int) $admin['id'], ['is_active' => $newState], 'domain', $domainId, 'Domain availability changed');
            flash('success', $newState ? 'Domain enabled.' : 'Domain disabled.');
        }

        redirect('/admin/domains.php');
    }

    if ($action === 'set_status') {
        $domainId = (int) input('id', 0);
        $status = (string) input('status', '');
        $allowed = ['draft', 'ready', 'maintenance', 'retired'];

        if (Domain::find($domainId) !== null && in_array($status, $allowed, true)) {
            Database::execute(
                'UPDATE domains SET registration_status = :status, updated_at = :updated_at WHERE id = :id',
                ['status' => $status, 'updated_at' => now(), 'id' => $domainId]
            );

            audit_log('admin.domain_status', (int) $admin['id'], ['status' => $status], 'domain', $domainId, 'Domain status changed');
            flash('success', 'Domain status updated.');
        } else {
            flash('error', 'Unknown domain or status.');
        }

        redirect('/admin/domains.php');
    }

    flash('error', 'Unknown action.');
    redirect('/admin/domains.php');
}

$allDomains = Database::select('SELECT * FROM domains ORDER BY sort_order ASC, id ASC');

view('admin/domains', [
    'authUser' => $admin,
    'domains' => $allDomains,
    'errors' => $errors,
    'values' => $values,
    'pageTitle' => 'Root domains',
    'activeNav' => 'admin-domains',
], 'layouts/admin');
