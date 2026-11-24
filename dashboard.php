<?php

declare(strict_types=1);

/**
 * Authenticated dashboard: reserve, release and track subdomains.
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

        $normalised = SubdomainService::normaliseLabel($label);
        $error = Validator::subdomainLabel($normalised);

        if ($error !== null) {
            flash('error', $error);
            redirect('/dashboard.php#reserve');
        }

        if ($domainId <= 0 && $domains !== []) {
            $domainId = (int) $domains[0]['id'];
        }

        $result = SubdomainService::reserve($user, $normalised, $domainId, $purpose !== '' ? $purpose : null);

        flash($result['ok'] ? 'success' : 'error', (string) $result['message']);
        redirect('/dashboard.php' . ($result['ok'] ? '#reservations' : '#reserve'));
    }

    if ($action === 'cancel_reservation') {
        $id = (int) input('id', 0);
        $reservation = Reservation::find($id);

        if ($reservation === null || (int) $reservation['user_id'] !== $userId) {
            flash('error', 'That reservation could not be found.');
            redirect('/dashboard.php');
        }

        if ((string) $reservation['status'] !== 'pending') {
            flash('warning', 'Only pending reservations can be cancelled.');
            redirect('/dashboard.php');
        }

        Reservation::updateStatus($id, 'cancelled', null, 'Cancelled by the user.');
        audit_log('subdomain.reservation_cancelled', $userId, ['reservation_id' => $id], 'reservation', $id, 'Reservation cancelled');

        flash('success', 'Reservation cancelled.');
        redirect('/dashboard.php');
    }

    if ($action === 'release_subdomain') {
        $id = (int) input('id', 0);
        $subdomain = Subdomain::findForUser($id, $userId);

        if ($subdomain === null) {
            flash('error', 'That subdomain could not be found.');
            redirect('/dashboard.php');
        }

        if ((string) $subdomain['status'] === 'active') {
            flash('warning', 'Active subdomains are released by support so DNS records are cleaned up safely. Please contact us.');
            redirect('/dashboard.php');
        }

        Subdomain::deleteForUser($id, $userId);
        audit_log('subdomain.released', $userId, ['fqdn' => $subdomain['fqdn']], 'subdomain', $id, 'Subdomain released');

        flash('success', 'Subdomain released.');
        redirect('/dashboard.php');
    }

    flash('error', 'Unknown action.');
    redirect('/dashboard.php');
}

$prefillLabel = SubdomainService::normaliseLabel((string) input('label', ''));
$prefillDomainId = (int) input('domain_id', 0);

$subdomains = Subdomain::forUser($userId);
$reservations = Reservation::forUser($userId);
$quotaLimit = (int) ($domains[0]['subdomain_quota'] ?? config('subdomains.default_quota', 5));
$quotaUsed = Subdomain::countForUser($userId);

view('dashboard', [
    'authUser' => $user,
    'subdomains' => $subdomains,
    'reservations' => $reservations,
    'domains' => $domains,
    'quotaLimit' => $quotaLimit,
    'quotaUsed' => $quotaUsed,
    'welcome' => input('welcome') === '1',
    'prefillLabel' => $prefillLabel,
    'prefillDomainId' => $prefillDomainId,
    'pageTitle' => 'Dashboard',
    'activeNav' => 'dashboard',
], 'layouts/app');
