<?php
/**
 * @var array<string, mixed> $authUser
 * @var list<array<string, mixed>> $reservations
 * @var list<array<string, mixed>> $reports
 * @var array<string, int> $metrics
 * @var string $dnsProviderName
 * @var bool $dnsConfigured
 */
?>
<section class="page-header">
    <div class="container">
        <div class="row align-items-end g-3">
            <div class="col-lg-8">
                <nav class="breadcrumb-mini" aria-label="Breadcrumb">
                    <a href="<?= e(app_url('/admin/index.php')) ?>">Admin</a>
                    <i data-lucide="chevron-right" aria-hidden="true" style="width:12px;height:12px"></i>
                    <span>Overview</span>
                </nav>
                <h1>Platform overview</h1>
                <p>Queues, counts and integration state at a glance.</p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <span class="pill <?= $dnsConfigured ? 'pill-success' : 'pill-warning' ?>">
                    <i data-lucide="<?= $dnsConfigured ? 'check-circle-2' : 'triangle-alert' ?>" aria-hidden="true"></i>
                    DNS provider: <?= e($dnsProviderName) ?><?= $dnsConfigured ? '' : ' (not configured)' ?>
                </span>
            </div>
        </div>
    </div>
</section>

<section class="section-tight">
    <div class="container">
        <?php partial('partials/flashes'); ?>

        <div class="alert alert-warning" role="status">
            <i data-lucide="info" aria-hidden="true"></i>
            <div>
                <strong>DNS automation is disabled in this build.</strong>
                Approving a reservation creates a <span class="mono">pending_dns</span> subdomain row only — no records are written
                to any zone, and no hostname is advertised as live.
            </div>
        </div>

        <div class="row g-3 g-lg-4 mb-4">
            <?php
            $cards = [
                ['label' => 'Users', 'value' => $metrics['users'], 'icon' => 'users', 'hint' => 'registered accounts'],
                ['label' => 'Root domains', 'value' => $metrics['domains'], 'icon' => 'globe-2', 'hint' => $metrics['active_domains'] . ' active'],
                ['label' => 'Subdomains', 'value' => $metrics['subdomains'], 'icon' => 'globe', 'hint' => $metrics['active_subdomains'] . ' active'],
                ['label' => 'Pending reservations', 'value' => $metrics['pending_reservations'], 'icon' => 'hourglass', 'hint' => 'need review'],
                ['label' => 'Open abuse reports', 'value' => $metrics['open_reports'], 'icon' => 'shield-alert', 'hint' => 'new or reviewing'],
                ['label' => 'DNS records pending', 'value' => $metrics['pending_dns'], 'icon' => 'cloud-off', 'hint' => 'awaiting provider'],
            ];
            ?>
            <?php foreach ($cards as $card): ?>
                <div class="col-sm-6 col-lg-4 col-xl-2">
                    <div class="stat-card">
                        <div class="stat-label"><i data-lucide="<?= e($card['icon']) ?>" aria-hidden="true"></i> <?= e($card['label']) ?></div>
                        <div class="stat-value"><?= e((string) $card['value']) ?></div>
                        <div class="stat-hint"><?= e($card['hint']) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="row g-4">
            <div class="col-lg-8">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h2 class="h5 mb-0">Pending reservations</h2>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(app_url('/admin/reservations.php')) ?>">Open queue</a>
                </div>

                <div class="table-surface">
                    <?php if ($reservations === []): ?>
                        <div class="empty-state border-0">
                            <div class="empty-icon"><i data-lucide="check-check" aria-hidden="true"></i></div>
                            <h3>Queue is empty</h3>
                            <p>No reservation requests are waiting for review.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead>
                                    <tr><th scope="col">Requested</th><th scope="col">User</th><th scope="col">Received</th></tr>
                                </thead>
                                <tbody>
                                <?php foreach ($reservations as $reservation): ?>
                                    <tr>
                                        <td class="mono fqdn"><?= e((string) $reservation['label']) ?>.<?= e((string) $reservation['root_domain']) ?></td>
                                        <td>
                                            <div><?= e((string) $reservation['user_name']) ?></div>
                                            <div class="text-muted-2" style="font-size:.8rem"><?= e((string) $reservation['user_email']) ?></div>
                                        </td>
                                        <td class="text-muted-2"><?= e(format_date((string) $reservation['created_at'], 'M j, Y H:i')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-lg-4">
                <h2 class="h5 mb-3">Latest abuse reports</h2>
                <div class="card-surface p-0">
                    <?php if ($reports === []): ?>
                        <div class="p-4 text-center text-muted-2" style="font-size:.92rem">No reports yet.</div>
                    <?php else: ?>
                        <ul class="list-unstyled mb-0">
                            <?php foreach ($reports as $report): ?>
                                <li class="p-3 border-bottom">
                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                        <span class="mono fqdn" style="font-size:.86rem;word-break:break-all"><?= e((string) $report['reported_fqdn']) ?></span>
                                        <span class="badge-status <?= $report['status'] === 'new' ? 'is-pending' : 'is-neutral' ?>"><?= e(ucfirst((string) $report['status'])) ?></span>
                                    </div>
                                    <div class="text-muted-2" style="font-size:.8rem"><?= e(ucfirst((string) $report['category'])) ?> · <?= e(format_date((string) $report['created_at'])) ?></div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                <a class="btn btn-outline-secondary btn-sm w-100 mt-3" href="<?= e(app_url('/admin/abuse.php')) ?>">Review reports</a>
            </div>
        </div>
    </div>
</section>
