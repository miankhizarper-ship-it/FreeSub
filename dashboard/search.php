<?php

declare(strict_types=1);

/**
 * Find a subdomain / reserve it (Task 05 §9).
 *
 * Availability is *only* decided by the server: this page renders a form, the
 * browser asks `/api/check.php` and later posts `action=reserve`, and
 * `ReservationService::create()` re-runs the whole check — validation,
 * availability against the database and the live zone, quota, and the
 * transactional uniqueness guard — before writing anything. Nothing here
 * duplicates that logic, and the client cannot pre-approve a name.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

$user = Auth::requireAuth('/dashboard/search.php');
$userId = (int) $user['id'];
$domains = Domain::available();

if (is_post()) {
    csrf_check();

    if ((string) input('action', '') !== 'reserve') {
        flash('error', 'That action is not available.');
        redirect('/dashboard/search.php');
    }

    $label = (string) input('label', '');
    $domainId = (int) input('domain_id', 0);
    $purpose = (string) input('purpose', '');

    $domain = $domainId > 0 ? Domain::find($domainId) : ($domains[0] ?? null);

    if ($domain === null || !($domain['is_active'] ?? false)) {
        flash('error', 'That extension is not available.');
        flash_old_input(['label' => $label, 'purpose' => $purpose]);
        redirect('/dashboard/search.php');
    }

    // Server-side throttle keyed by the authenticated account, not by anything
    // the browser sent.
    if (!rate_limit_hit('reserve:' . $userId, 10, 300)) {
        flash('error', 'You are sending requests too quickly. Please wait a few minutes and try again.');
        flash_old_input(['label' => $label, 'purpose' => $purpose, 'domain_id' => (string) $domain['id']]);
        redirect('/dashboard/search.php');
    }

    $result = ReservationService::create($user, $label, $domain, $purpose);

    if ($result['ok']) {
        audit_log(
            'subdomain.reservation_created',
            $userId,
            ['fqdn' => $result['fqdn'] ?? null],
            'reservation',
            (int) $result['reservation_id'],
            'Reservation created'
        );

        flash('success', (string) $result['message']);
        redirect('/dashboard/reservations.php?status=pending');
    }

    flash('error', (string) $result['message']);

    if (isset($result['errors']) && is_array($result['errors'])) {
        flash_errors($result['errors']);
    }

    flash_old_input(['label' => $label, 'purpose' => $purpose, 'domain_id' => (string) $domain['id']]);
    redirect('/dashboard/search.php');
}

$prefillLabel = (string) input('label', old('label'));
$prefillDomainId = (int) input('domain_id', (int) old('domain_id', '0'));

$selectedDomain = null;

foreach ($domains as $candidate) {
    if ($prefillDomainId > 0 && (int) $candidate['id'] === $prefillDomainId) {
        $selectedDomain = $candidate;
    }
}

$selectedDomain ??= $domains[0] ?? null;

$quota = SubdomainQuota::overview($user, $domains);
$byDomain = SubdomainQuota::byDomain($user, $domains);
$errors = errors();

// Per-domain remaining, so the form can explain a refusal before it happens.
$remainingByDomain = [];

foreach ($byDomain as $row) {
    $remainingByDomain[$row['domain_id']] = $row;
}

view('dashboard/search', [
    'authUser' => $user,
    'domains' => $domains,
    'quota' => $quota,
    'byDomain' => $remainingByDomain,
    'selectedDomain' => $selectedDomain,
    'prefillLabel' => $prefillLabel,
    'errors' => $errors,
    'pageTitle' => 'Find a subdomain',
    'pageSubtitle' => 'Check availability, then send a reservation for review.',
    'activeNav' => 'search',
], 'layouts/dashboard');
