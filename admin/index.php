<?php

declare(strict_types=1);

/**
 * Admin overview: platform health and the queues that need a human.
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$admin = Auth::requireAdmin();

$reservations = Reservation::pending(25);
$reports = AbuseReport::recent(10);

$metrics = [
    'users' => User::count(),
    'domains' => Domain::count(),
    'active_domains' => Domain::countActive(),
    'subdomains' => Subdomain::countAll(),
    'active_subdomains' => Subdomain::countActive(),
    'pending_reservations' => Reservation::countByStatus('pending'),
    'open_reports' => AbuseReport::countOpen(),
    'pending_dns' => DnsRecord::countPending(),
];

$dnsProvider = DnsProvider::current();

view('admin/overview', [
    'authUser' => $admin,
    'reservations' => $reservations,
    'reports' => $reports,
    'metrics' => $metrics,
    'dnsProviderName' => $dnsProvider->name(),
    'dnsConfigured' => $dnsProvider->isConfigured(),
    'pageTitle' => 'Admin',
    'activeNav' => 'admin',
], 'layouts/admin');
