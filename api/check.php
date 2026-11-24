<?php

declare(strict_types=1);

/**
 * GET /api/check.php?label=mysite[&domain_id=1][&format=json]
 *
 * Public availability endpoint used by the landing page search.
 * It performs read-only lookups and never mutates state.
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    json_error('Only GET requests are allowed.', 405);
}

// Throttle to keep the endpoint cheap and blunt scraping.
if (!rate_limit_hit('api-check', 60, 60)) {
    json_error('Too many lookups. Please slow down for a moment.', 429);
}

$label = (string) input('label', '');

if ($label === '' || mb_strlen($label) > 64) {
    json_error('Provide a subdomain between 1 and 64 characters.', 422, ['label' => 'Enter a name to check.']);
}

$domain = null;
$domainId = (int) input('domain_id', 0);

if ($domainId > 0) {
    $domain = Domain::find($domainId);

    if ($domain === null || !$domain['is_active']) {
        json_error('That extension is not available.', 404);
    }
}

$result = SubdomainService::check($label, $domain);

$response = [
    'ok' => $result['ok'],
    'label' => $result['label'],
    'fqdn' => $result['fqdn'],
    'domain' => $result['domain'],
    'available' => $result['available'],
    'status' => $result['status'],
    'message' => $result['message'],
    'suggestions' => $result['suggestions'],
    'reserve_url' => null,
];

if ($result['available']) {
    $reserveUrl = app_url('/dashboard.php?reserve=1&label=' . rawurlencode($result['label']));

    if ($domain !== null) {
        $reserveUrl .= '&domain_id=' . (int) $domain['id'];
    }

    $response['reserve_url'] = $reserveUrl;
}

header('Cache-Control: no-store, max-age=0');
json_response($response);
