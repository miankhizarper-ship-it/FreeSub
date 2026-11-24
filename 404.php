<?php

declare(strict_types=1);

/**
 * Friendly 404 page (also used as the .htaccess ErrorDocument target).
 */

require_once __DIR__ . '/includes/bootstrap.php';

http_response_code(404);

view('errors/404', ['pageTitle' => 'Page not found'], 'layouts/public');
