<?php

declare(strict_types=1);

/**
 * FreeSub — landing page.
 */

require_once __DIR__ . '/includes/bootstrap.php';

// If the schema has not been imported yet, show setup guidance instead of a
// fatal error page. This is the only place that degrades gracefully on a
// missing database: everything past this point requires a working connection.
try {
    $domains = Domain::available();
    $stats = [
        'domains' => Domain::countActive(),
        'subdomains' => Subdomain::countAll(),
        'users' => User::count(),
    ];
} catch (Throwable $exception) {
    error_log('[freesub] landing page database error: ' . $exception->getMessage());
    http_response_code(503);

    view('errors/setup', [
        'pageTitle' => 'Setup required',
        'message' => $exception->getMessage(),
    ], null);

    return;
}

$pageTitle = 'Free subdomains for your next idea';
$metaDescription = 'FreeSub gives you a free, memorable subdomain on ' . ($domains[0]['root_domain'] ?? 'kharcha.info') . '. Search availability, reserve your name and launch your project — no credit card required.';

view('home', [
    'domains' => $domains,
    'stats' => $stats,
    'pageTitle' => $pageTitle,
    'metaDescription' => $metaDescription,
    'activeNav' => null,
], 'layouts/public');
