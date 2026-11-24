<?php

declare(strict_types=1);

/**
 * Read-only DNS inspection.
 *
 *   php database/dns-sync.php                      inspect the configured root domain
 *   php database/dns-sync.php --domain=kharcha.info
 *   php database/dns-sync.php --json                machine readable output
 *   php database/dns-sync.php --dry-run             (default; accepted for clarity)
 *
 * What it does: verifies the Cloudflare zone and compares it with
 * `dns_records`. What it never does: create, update or delete anything. There is
 * deliberately no --apply flag in this build — provisioning stays manual until
 * the reservation flow is enabled, and the engine refuses to write outside the
 * service layer.
 *
 * This is the command the documentation points at for a live read-only check.
 * It needs real credentials (CLOUDFLARE_API_TOKEN + CLOUDFLARE_ZONE_ID) and will
 * exit non-zero when they are absent, rather than silently reporting success.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$options = getopt('', ['domain::', 'json', 'dry-run', 'help']);

if (isset($options['help'])) {
    echo "Usage: php database/dns-sync.php [--domain=example.com] [--json] [--dry-run]\n";
    exit(0);
}

$json = isset($options['json']);
$domainName = isset($options['domain']) ? strtolower(trim((string) $options['domain'])) : '';

$manager = new DnsManager();

if ($domainName === '') {
    $domainName = $manager->rootDomain();
}

$emit = static function (array $payload, int $exitCode) use ($json): never {
    if ($json) {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        foreach ($payload['lines'] ?? [] as $line) {
            echo $line . "\n";
        }
    }

    exit($exitCode);
};

$lines = [];
$lines[] = 'FreeSub — DNS inspection (read-only)';
$lines[] = 'Root domain: ' . $domainName;
$lines[] = 'Provider:    ' . (string) config('dns.provider', 'none');

$domain = Domain::findByRoot($domainName);

if ($domain === null) {
    $lines[] = '';
    $lines[] = '! ' . $domainName . ' is not a root domain in this installation.';

    $emit(['ok' => false, 'reason' => 'unknown_domain', 'lines' => $lines], 2);
}

if (!$manager->isEnabled()) {
    $lines[] = '';
    $lines[] = '! DNS automation is not configured.';
    $lines[] = '  Set DNS_PROVIDER=cloudflare, CLOUDFLARE_API_TOKEN and CLOUDFLARE_ZONE_ID.';
    $lines[] = '  Nothing was read from Cloudflare and nothing was written.';

    $emit(['ok' => false, 'reason' => 'not_configured', 'lines' => $lines], 2);
}

$status = $manager->status($domain, false);
$lines[] = 'Zone id:     ' . ($status['zone_id'] !== '' ? (string) $status['zone_id'] : '(missing)');
$lines[] = 'API base:    ' . (string) $status['api_base'];
$lines[] = '';

$lines[] = 'Verifying the zone…';
$zone = $manager->provider()->verifyZone($domainName);

$lines[] = '  ' . ($zone['ok'] ? 'OK   ' : 'FAIL ') . $zone['message'];

if ($zone['zone_name'] !== null) {
    $lines[] = '  zone name:    ' . $zone['zone_name'];
    $lines[] = '  zone status:  ' . (string) $zone['status'];
    $lines[] = '  nameservers:  ' . ($zone['nameservers'] === [] ? '—' : implode(', ', $zone['nameservers']));
}

if (!$zone['ok']) {
    $emit([
        'ok' => false,
        'reason' => $zone['code'],
        'zone' => $zone,
        'lines' => $lines,
    ], 1);
}

$lines[] = '';
$lines[] = 'Comparing dns_records with the live zone…';

// writeAudit = false: an inspection run should not fill the audit trail.
$sync = $manager->sync($domain, null, false);

if (!$sync['ok']) {
    $lines[] = '  ! ' . $sync['message'];
    $emit(['ok' => false, 'reason' => 'sync_failed', 'lines' => $lines], 1);
}

$lines[] = '  ' . $sync['message'];

foreach (['missing' => 'Missing from the zone', 'modified' => 'Changed outside FreeSub', 'unknown' => 'Unmanaged (never deleted)'] as $key => $label) {
    $rows = $sync[$key];

    $lines[] = '';
    $lines[] = $label . ': ' . count($rows);

    foreach (array_slice($rows, 0, 25) as $row) {
        $lines[] = sprintf(
            '  - %-40s %-6s %s',
            (string) $row['name'],
            (string) $row['type'],
            (string) ($row['actual'] ?? $row['expected'] ?? $row['content'] ?? '')
        );
    }
}

$lines[] = '';
$lines[] = 'No changes were made. This command is read-only by design.';

$emit([
    'ok' => true,
    'reason' => 'ok',
    'zone' => $zone,
    'summary' => [
        'checked' => $sync['checked'],
        'in_sync' => count($sync['ok_records']),
        'missing' => count($sync['missing']),
        'modified' => count($sync['modified']),
        'unknown' => count($sync['unknown']),
    ],
    'lines' => $lines,
], 0);
