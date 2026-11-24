<?php

declare(strict_types=1);

/**
 * Authenticated dashboard: search, reserve, track and cancel.
 *
 * Every read and write below is scoped to `Auth::id()` — the identity resolved
 * server-side from the Clerk session. There is no code path that accepts a user
 * id from the request.
 */

require_once __DIR__ . '/includes/bootstrap.php';

$user = Auth::requireAuth('/dashboard.php');
$userId = (int) $user['id'];
$domains = Domain::available();

if (is_post()) {
    csrf_check();

    $action = (string) input('action', '');

    if ($action === 'reserve') {
        $label = (string) input('label', '');
        $domainId = (int) input('domain_id', 0);
        $purpose = (string) input('purpose', '');

        $domain = $domainId > 0 ? Domain::find($domainId) : ($domains[0] ?? null);

        if ($domain === null || !($domain['is_active'] ?? false)) {
            flash('error', 'That extension is not available.');
            redirect('/dashboard.php#reserve');
        }

        // Server-side throttle: the form is not the only way in, so the limit is
        // applied to the action, not to the widget. Keyed by the authenticated
        // user id, never by anything the browser sent.
        if (!rate_limit_hit('reserve:' . $userId, 10, 300)) {
            flash('error', 'You are sending requests too quickly. Please wait a few minutes and try again.');
            redirect('/dashboard.php#reserve');
        }

        $result = ReservationService::create($user, $label, $domain, $purpose);

        if ($result['ok']) {
            audit_log('subdomain.reservation_created', $userId, ['fqdn' => $result['fqdn'] ?? null], 'reservation', (int) $result['reservation_id'], 'Reservation created');
            flash('success', (string) $result['message']);
            redirect('/dashboard.php#reservations');
        }

        flash('error', (string) $result['message']);

        if (isset($result['errors']) && is_array($result['errors'])) {
            flash_errors($result['errors']);
        }

        flash_old_input(['label' => $label, 'purpose' => $purpose, 'domain_id' => (string) $domain['id']]);
        redirect('/dashboard.php#reserve');
    }

    if ($action === 'cancel_reservation') {
        $result = ReservationService::cancel($user, (int) input('id', 0));
        flash($result['ok'] ? 'success' : 'error', (string) $result['message']);
        redirect('/dashboard.php#reservations');
    }

    if ($action === 'release_subdomain') {
        $id = (int) input('id', 0);

        // Scoped lookup: another user's id returns null, so nothing is disclosed.
        $subdomain = Subdomain::findForUser($id, $userId);

        if ($subdomain === null) {
            flash('error', 'That subdomain could not be found.');
            redirect('/dashboard.php');
        }

        if ((string) $subdomain['status'] === 'active') {
            // Releasing a live name needs DNS cleanup, which is a support action
            // in this build. Saying so is better than silently leaving a record.
            flash('warning', 'Active subdomains are released by support so the DNS record is removed safely. Please contact us.');
            redirect('/dashboard.php');
        }

        ProvisioningQueue::cancelForSubdomain($id, null);
        Subdomain::deleteForUser($id, $userId);
        audit_log('subdomain.released', $userId, ['fqdn' => $subdomain['fqdn']], 'subdomain', $id, 'Subdomain released');

        flash('success', 'Release requested. The name will free up shortly.');
        redirect('/dashboard.php');
    }

    flash('error', 'Unknown action.');
    redirect('/dashboard.php');
}

$prefillLabel = (string) input('label', '');
$prefillDomainId = (int) input('domain_id', 0);
$selectedDomain = null;

foreach ($domains as $candidate) {
    if ($prefillDomainId > 0 && (int) $candidate['id'] === $prefillDomainId) {
        $selectedDomain = $candidate;
    }
}

$selectedDomain ??= $domains[0] ?? null;
$quota = SubdomainQuota::summary($user, $selectedDomain);
$errors = errors();
$buckets = ReservationService::dashboard($userId);

view('dashboard', [
    'authUser' => $user,
    'subdomains' => $buckets['active'],
    'reservations' => $buckets['pending'],
    'rejected' => $buckets['rejected'],
    'historyRows' => $buckets['history'],
    'domains' => $domains,
    'quota' => $quota,
    'quotaLimit' => $quota['limit'],
    'quotaUsed' => $quota['used'],
    'welcome' => input('welcome') === '1',
    'errors' => $errors,
    'prefillLabel' => $prefillLabel,
    'prefillDomainId' => $prefillDomainId,
    'pageTitle' => 'Dashboard',
    'activeNav' => 'dashboard',
], 'layouts/app');
