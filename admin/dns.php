<?php

declare(strict_types=1);

/**
 * Admin: DNS diagnostics (Cloudflare).
 *
 * Read-only by default. The only network call this page can make is the
 * "Test connection" action, which performs a zone lookup and nothing else.
 *
 * Record mutations live behind the service layer (DnsManager) and are *not*
 * exposed here: public registration stays disabled in this build, so there is
 * no user-facing path that can create DNS. See TASKS.md.
 *
 * Nothing on this page renders the API token, an Authorization header or a raw
 * response body.
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$admin = Auth::requireAdmin();
$manager = new DnsManager();
$errors = errors();

$domains = Database::select(
    "SELECT * FROM domains WHERE dns_provider = 'cloudflare' OR root_domain = :root ORDER BY id ASC",
    ['root' => $manager->rootDomain()]
);

$selectedId = (int) input('domain_id', 0);
$domain = null;

foreach ($domains as $candidate) {
    if ($selectedId > 0 ? (int) $candidate['id'] === $selectedId : $domain === null) {
        $domain = $candidate;
    }
}

if ($domain === null) {
    $domain = Domain::findByRoot($manager->rootDomain());
}

$syncResult = null;

if (is_post()) {
    csrf_check();

    $action = (string) input('action', '');

    if ($domain === null) {
        flash('error', 'No root domain is configured for DNS.');
        redirect('/admin/dns.php');
    }

    if ($action === 'test_connection') {
        $test = $manager->testConnection($domain, (int) $admin['id']);
        flash($test['ok'] ? 'success' : 'error', $test['message']);
        redirect('/admin/dns.php?domain_id=' . (int) $domain['id']);
    }

    if ($action === 'sync') {
        $syncResult = $manager->sync($domain, (int) $admin['id']);
        flash($syncResult['ok'] ? 'success' : 'error', $syncResult['message']);
        // The detail is shown from the session so a refresh does not re-run it.
        $_SESSION['_dns_sync_result'] = $syncResult;
        redirect('/admin/dns.php?domain_id=' . (int) $domain['id']);
    }

    // Any other POST is a programming error: mutations are not exposed here.
    flash('error', 'Unknown DNS action.');
    redirect('/admin/dns.php');
}

if (isset($_SESSION['_dns_sync_result'])) {
    $syncResult = $_SESSION['_dns_sync_result'];
    unset($_SESSION['_dns_sync_result']);
}

$status = $manager->status($domain, false);
$records = $domain !== null
    ? DnsRecordRepository::forDomain((int) $domain['id'])
    : [];

$history = Database::select(
    "SELECT action, description, created_at, actor_type
       FROM audit_logs
      WHERE action LIKE 'dns.%'
      ORDER BY id DESC
      LIMIT 15"
);

$recentErrors = Database::select(
    "SELECT id, name, type, status, last_error, updated_at
       FROM dns_records
      WHERE last_error IS NOT NULL AND status = 'failed'
      ORDER BY updated_at DESC
      LIMIT 10"
);

view('admin/dns', [
    'authUser' => $admin,
    'domain' => $domain,
    'domains' => $domains,
    'status' => $status,
    'records' => $records,
    'history' => $history,
    'recentErrors' => $recentErrors,
    'syncResult' => $syncResult,
    'errors' => $errors,
    'pageTitle' => 'DNS diagnostics',
    'activeNav' => 'admin-dns',
], 'layouts/admin');
