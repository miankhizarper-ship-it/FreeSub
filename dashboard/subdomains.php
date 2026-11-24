<?php

declare(strict_types=1);

/**
 * My subdomains (Task 05 §5).
 *
 * Lists every non-terminated subdomain this account owns, with the canonical
 * backend status and the provisioning state. Ownership is in the query
 * (`user_id = Auth::id()`), so there is no id in the request at all.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

$user = Auth::requireAuth('/dashboard/subdomains.php');
$userId = (int) $user['id'];

$filter = (string) input('status', 'all');
$allowedFilters = ['all', 'active', 'pending_dns', 'failed', 'suspended', 'terminated'];

if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'all';
}

// Released names are read-only history: they are only loaded when the owner asks
// to see them, so the default list is exactly the names they can still manage.
$includeTerminated = $filter === 'terminated' || $filter === 'all';

$all = Subdomain::forUser($userId, 200, $includeTerminated);

$counts = ['active' => 0, 'pending_dns' => 0, 'failed' => 0, 'suspended' => 0, 'terminated' => 0];

foreach ($all as $row) {
    $status = (string) $row['status'];

    if (isset($counts[$status])) {
        $counts[$status]++;
    }
}

// A released name cannot be managed, so the default view leaves it out but the
// counts (and the filter) still offer it.
$managed = array_values(array_filter($all, static fn (array $row): bool => (string) $row['status'] !== 'terminated'));

$visible = match ($filter) {
    'all' => $managed,
    'terminated' => array_values(array_filter($all, static fn (array $row): bool => (string) $row['status'] === 'terminated')),
    default => array_values(array_filter($managed, static fn (array $row): bool => (string) $row['status'] === $filter)),
};

$provisioning = Subdomain::provisioningForMany(array_map(
    static fn (array $row): int => (int) $row['id'],
    $visible
));

view('dashboard/subdomains', [
    'authUser' => $user,
    'subdomains' => $visible,
    'total' => count($managed),
    'totalWithReleased' => count($all),
    'provisioning' => $provisioning,
    'counts' => $counts,
    'filter' => $filter,
    'domains' => Domain::available(),
    'pageTitle' => 'My subdomains',
    'pageSubtitle' => 'Every name on your account, with the state of its DNS record.',
    'activeNav' => 'subdomains',
], 'layouts/dashboard');
