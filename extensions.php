<?php

declare(strict_types=1);

/**
 * Public list of root domains / extensions.
 */

require_once __DIR__ . '/includes/bootstrap.php';

$domains = Domain::available();
$extensions = Domain::extensions();

view('extensions', [
    'domains' => $domains,
    'extensions' => $extensions,
    'pageTitle' => 'Extensions',
    'metaDescription' => 'Every root domain currently open for free subdomain registration on FreeSub.',
    'activeNav' => 'extensions',
], 'layouts/public');
