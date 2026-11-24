<?php

declare(strict_types=1);

/**
 * php database/provision.php [--run] [--job=ID] [--status] [--limit=N] [--actor=EMAIL]
 *
 * The only supported way to turn an approved reservation into a live DNS record.
 *
 * Why a CLI: DNS is public infrastructure. Writing it should be an explicit,
 * logged, human-initiated act with a terminal in front of it — not something a
 * web request or a cron timer can do silently. There is deliberately no
 * auto-provisioning and no "lazy provisioning on page load".
 *
 * Safety rules baked in:
 *   * Dry run is the default; nothing is written without --run.
 *   * Each job is claimed with a conditional UPDATE, so two terminals cannot
 *     work the same row.
 *   * A subdomain is marked active only after the record is read back from the
 *     provider and its value compared (ProvisioningQueue::provision()).
 *   * A failure is recorded with its reason and left visible to admins; it is
 *     never retried blindly.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$options = getopt('', ['run', 'job::', 'status', 'limit::', 'actor::', 'help']);
$args = $argv ?? [];

$run = array_key_exists('run', $options);
$statusOnly = array_key_exists('status', $options);
$limit = isset($options['limit']) ? max(1, min(50, (int) $options['limit'])) : 5;
$jobId = isset($options['job']) ? (int) $options['job'] : 0;

if (array_key_exists('help', $options)) {
    echo <<<TXT
    FreeSub DNS provisioning worker

    Usage:
      php database/provision.php --status              show the queue
      php database/provision.php                       dry run: what would happen
      php database/provision.php --run                 run up to 5 runnable jobs
      php database/provision.php --run --limit=20      run up to 20
      php database/provision.php --run --job=42        run one specific job
      php database/provision.php --run --actor=you@example.com

    Provisioning writes public DNS. The provider and its zone must be verified
    first (php database/dns-sync.php --dry-run shows the same zone).

    TXT;
    exit(0);
}

function provision_out(string $line = ''): void
{
    echo $line, PHP_EOL;
}

/* -------------------------------------------------------------------------
 * Guard rails before anything is attempted.
 * ---------------------------------------------------------------------- */

$manager = new DnsManager();
$counts = ProvisioningQueue::counts();
$enabled = $manager->isEnabled();

provision_out('FreeSub provisioning worker');
provision_out(str_repeat('=', 60));
provision_out('DNS engine:      ' . ($enabled ? 'enabled (' . $manager->provider()->name() . ')' : 'DISABLED (null driver)'));
provision_out('Queue:           ' . $counts['pending'] . ' pending, ' . $counts['running'] . ' running, '
    . $counts['failed'] . ' failed, ' . $counts['done'] . ' done');
provision_out('Targets:         ' . (ProvisioningQueue::targetsFor($manager->rootDomain()) === []
    ? 'NOT CONFIGURED (set DNS_PROVISIONING_A / DNS_PROVISIONING_AAAA)'
    : implode(', ', array_map(
        static fn (array $t): string => $t[0] . ' ' . $t[1],
        ProvisioningQueue::targetsFor($manager->rootDomain())
    ))));
provision_out();

if ($statusOnly) {
    $rows = ProvisioningQueue::recent($limit);

    if ($rows === []) {
        provision_out('The queue is empty.');
        exit(0);
    }

    $header = sprintf('%-5s %-8s %-6s %-34s %s', 'ID', 'STATUS', 'TRIES', 'HOSTNAME', 'LAST ERROR');
    provision_out($header);
    provision_out(str_repeat('-', 100));

    foreach ($rows as $row) {
        provision_out(sprintf(
            '%-5d %-8s %-6s %-34s %s',
            (int) $row['id'],
            (string) $row['status'],
            (string) $row['attempts'],
            (string) $row['fqdn'],
            str_limit((string) ($row['last_error'] ?? '—'), 48)
        ));
    }

    exit(0);
}

// A dry run writes nothing, so it must remain possible on a machine where DNS
// is not configured yet — that is precisely when an operator needs to see what
// *would* happen and why it cannot. Only a real run is refused.
if (!$enabled && $run) {
    provision_out('Nothing was done: the DNS engine is not enabled.');
    provision_out('Jobs stay queued, so enabling it later still provisions them.');
    exit(1);
}

$actor = null;

if (isset($options['actor'])) {
    $actorEmail = trim((string) $options['actor']);

    if ($actorEmail !== '') {
        $candidate = User::findByEmail($actorEmail);

        if ($candidate === null) {
            provision_out('Unknown actor: ' . $actorEmail);
            exit(1);
        }

        if (!in_array((string) $candidate['role'], ['admin', 'super_admin'], true)) {
            provision_out('The --actor account must be an administrator.');
            exit(1);
        }

        $actor = $candidate;
    }
}

if (!$run) {
    $candidates = Database::select(
        "SELECT * FROM provisioning_jobs
          WHERE status = 'pending' AND available_at <= :now AND attempts < :max
          ORDER BY id ASC LIMIT " . $limit,
        ['now' => now(), 'max' => ProvisioningQueue::maxAttempts()]
    );

    provision_out('DRY RUN — nothing will be written. Re-run with --run to apply.');

    if (!$enabled) {
        provision_out();
        provision_out('This run would be REFUSED: the DNS engine is not enabled.');
        provision_out('Enable DNS_PROVIDER=cloudflare with a token, a zone id and a target first.');
    } elseif (ProvisioningQueue::targetsFor($manager->rootDomain()) === []) {
        provision_out();
        provision_out('This run would be REFUSED: no provisioning target is configured.');
    }

    provision_out();

    if ($candidates === []) {
        provision_out('No runnable jobs.');
        exit(0);
    }

    foreach ($candidates as $candidate) {
        provision_out('  would provision  #' . (int) $candidate['id'] . '  ' . (string) $candidate['fqdn']);
    }

    exit(0);
}

/* -------------------------------------------------------------------------
 * Real run.
 * ---------------------------------------------------------------------- */

$processed = 0;
$succeeded = 0;
$failed = 0;

if ($jobId > 0) {
    $result = ProvisioningQueue::run($jobId, $actor);
    $processed = 1;
    $result['ok'] ? $succeeded++ : $failed++;

    provision_out(($result['ok'] ? 'OK   ' : 'FAIL ') . '#' . $jobId . ' ' . (string) $result['message']);
} else {
    for ($i = 0; $i < $limit; $i++) {
        $job = ProvisioningQueue::claim();

        if ($job === null) {
            break;
        }

        $result = ProvisioningQueue::provision($job, $actor);
        $processed++;
        $result['ok'] ? $succeeded++ : $failed++;

        provision_out(($result['ok'] ? 'OK   ' : 'FAIL ') . '#' . (int) $job['id'] . ' ' . (string) $job['fqdn']
            . ' — ' . (string) $result['message']);
    }
}

provision_out();
provision_out('Processed ' . $processed . ' job(s): ' . $succeeded . ' verified, ' . $failed . ' failed.');

if ($processed === 0) {
    provision_out('Nothing was runnable. Use --status to inspect the queue.');
}

// A non-zero exit code makes a failure visible to whatever invoked this, so a
// silent "success" cannot hide an unreconciled DNS state.
exit($failed > 0 ? 2 : 0);
