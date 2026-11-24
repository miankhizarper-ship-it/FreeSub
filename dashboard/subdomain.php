<?php

declare(strict_types=1);

/**
 * Subdomain detail (Task 05 §6, §7).
 *
 * Authorisation is a single question asked in SQL: does this subdomain belong
 * to the signed-in account? `Subdomain::findForUser($id, Auth::id())` answers
 * it, and a foreign id is indistinguishable from a missing one — the response
 * is a 404 with no hint that the row exists. That is deliberate: a 403 would
 * confirm the id is real and turn the URL into an enumeration oracle.
 *
 * Nothing secret is loaded: no provider tokens, no API responses, no internal
 * Clerk identifiers. The operator-facing `last_error` column is not even
 * selected by Subdomain::provisioningFor().
 */

require_once __DIR__ . '/../includes/bootstrap.php';

$user = Auth::requireAuth('/dashboard/subdomains.php');
$userId = (int) $user['id'];

$rawId = (string) input('id', '');
$id = ctype_digit($rawId) ? (int) $rawId : 0;

$subdomain = $id > 0 ? Subdomain::findForUser($id, $userId) : null;

if ($subdomain === null) {
    http_response_code(404);
    view('dashboard/subdomain-missing', [
        'authUser' => $user,
        'pageTitle' => 'Subdomain not found',
        'pageSubtitle' => null,
        'activeNav' => 'subdomains',
    ], 'layouts/dashboard');

    return;
}

$status = (string) $subdomain['status'];
$state = DashboardStatus::subdomain($status);
$provisioning = Subdomain::provisioningFor($id);
$job = $provisioning['job'];
$records = $provisioning['records'];

$reservation = null;

if (($subdomain['reservation_id'] ?? null) !== null) {
    $candidate = Reservation::find((int) $subdomain['reservation_id']);

    // Belt and braces: the reservation is loaded only when it belongs to the
    // same account as the subdomain. A mismatched link is treated as absent
    // rather than displayed.
    if ($candidate !== null && (int) ($candidate['user_id'] ?? 0) === $userId) {
        $reservation = $candidate;
    }
}

$timeline = DashboardStatus::timeline($subdomain, $reservation, $records, $job);
$canRelease = DashboardStatus::canReleaseFromDashboard($status);
$releaseBlocked = DashboardStatus::releaseBlockedReason($status);

// The record we can show the owner: type, name and the target we asked for.
// Provider record ids and raw API payloads stay server-side.
$primaryRecord = null;

foreach ($records as $record) {
    if ((string) ($record['status'] ?? '') === 'active') {
        $primaryRecord = $record;
        break;
    }

    $primaryRecord ??= $record;
}

view('dashboard/subdomain', [
    'authUser' => $user,
    'subdomain' => $subdomain,
    'state' => $state,
    'job' => $job,
    'records' => $records,
    'primaryRecord' => $primaryRecord,
    'reservation' => $reservation,
    'timeline' => $timeline,
    'canRelease' => $canRelease,
    'releaseBlocked' => $releaseBlocked,
    'status' => $status,
    'pageTitle' => (string) $subdomain['fqdn'],
    'pageSubtitle' => 'DNS and provisioning detail for this name.',
    'activeNav' => 'subdomains',
], 'layouts/dashboard');
