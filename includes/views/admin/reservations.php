<?php
/**
 * @var list<array<string, mixed>> $reservations
 * @var list<array<string, mixed>> $jobs
 * @var array{pending:int, running:int, failed:int, done:int} $jobCounts
 * @var string $status
 * @var string $search
 * @var int $domainFilter
 * @var list<array<string, mixed>> $domains
 * @var bool $dnsEnabled
 */
$tabs = [
    'pending' => 'Pending',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'cancelled' => 'Cancelled',
    'expired' => 'Expired',
    'all' => 'All',
];

$failures = (int) $jobCounts['failed'];
?>
<section class="page-header">
    <div class="container">
        <div class="row align-items-end g-3">
            <div class="col-lg-8">
                <nav class="breadcrumb-mini" aria-label="Breadcrumb">
                    <a href="<?= e(app_url('/admin/index.php')) ?>">Admin</a>
                    <i data-lucide="chevron-right" aria-hidden="true" style="width:12px;height:12px"></i>
                    <span>Reservations</span>
                </nav>
                <h1>Reservation queue</h1>
                <p>
                    Approving creates a <strong>pending-DNS</strong> subdomain and one queued job. It never
                    writes DNS and never marks a name live.
                </p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <form method="post" action="<?= e(app_url('/admin/reservations.php')) ?>" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="expire_lapsed">
                    <button type="submit" class="btn btn-outline-secondary" data-confirm="Expire every reservation past its expiry date?">
                        <i data-lucide="timer-reset" aria-hidden="true"></i> Expire lapsed
                    </button>
                </form>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 mt-3">
            <?php foreach ($tabs as $key => $label): ?>
                <a class="btn btn-sm <?= $status === $key ? 'btn-primary' : 'btn-outline-secondary' ?>"
                   href="<?= e(app_url('/admin/reservations.php?status=' . $key)) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>

        <form method="get" action="<?= e(app_url('/admin/reservations.php')) ?>" class="row g-2 mt-2 align-items-end">
            <input type="hidden" name="status" value="<?= e($status) ?>">
            <div class="col-sm-5 col-lg-4">
                <label class="form-label" for="admin-res-q">Search</label>
                <input type="search" class="form-control form-control-sm" id="admin-res-q" name="q"
                       value="<?= e($search) ?>" placeholder="label or email">
            </div>
            <div class="col-sm-5 col-lg-3">
                <label class="form-label" for="admin-res-domain">Extension</label>
                <select class="form-select form-select-sm" id="admin-res-domain" name="domain_id">
                    <option value="0">All extensions</option>
                    <?php foreach ($domains as $domain): ?>
                        <option value="<?= e((string) $domain['id']) ?>" <?= $domainFilter === (int) $domain['id'] ? 'selected' : '' ?>>
                            <?= e((string) $domain['root_domain']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-2 col-lg-2">
                <button type="submit" class="btn btn-sm btn-outline-secondary w-100">Filter</button>
            </div>
        </form>
    </div>
</section>

<section class="section-tight">
    <div class="container">
        <?php partial('partials/flashes'); ?>

        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-xl-3">
                <div class="stat-card">
                    <div class="stat-label"><i data-lucide="list-checks" aria-hidden="true"></i> Queued jobs</div>
                    <div class="stat-value"><?= (int) $jobCounts['pending'] ?></div>
                    <div class="stat-hint">waiting to be run by an operator</div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="stat-card">
                    <div class="stat-label"><i data-lucide="loader" aria-hidden="true"></i> Running</div>
                    <div class="stat-value"><?= (int) $jobCounts['running'] ?></div>
                    <div class="stat-hint">claimed by a worker</div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="stat-card">
                    <div class="stat-label"><i data-lucide="triangle-alert" aria-hidden="true"></i> Failed</div>
                    <div class="stat-value"><?= $failures ?></div>
                    <div class="stat-hint"><?= $failures > 0 ? 'needs attention' : 'nothing broken' ?></div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="stat-card">
                    <div class="stat-label"><i data-lucide="badge-check" aria-hidden="true"></i> Verified</div>
                    <div class="stat-value"><?= (int) $jobCounts['done'] ?></div>
                    <div class="stat-hint">record created and read back</div>
                </div>
            </div>
        </div>

        <?php if (!$dnsEnabled): ?>
            <div class="alert alert-warning" role="status">
                <i data-lucide="plug-zap" aria-hidden="true"></i>
                <div>
                    <strong>DNS provisioning is disabled.</strong>
                    Approvals still queue a job, but running one will defer until a provider is enabled
                    and configured. Nothing will be written to any public zone.
                </div>
            </div>
        <?php endif; ?>

        <div class="table-surface mb-5">
            <?php if ($reservations === []): ?>
                <div class="empty-state border-0">
                    <div class="empty-icon"><i data-lucide="inbox" aria-hidden="true"></i></div>
                    <h2>Nothing in this queue</h2>
                    <p>Try another status filter.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th scope="col">Hostname</th>
                                <th scope="col">User</th>
                                <th scope="col">Status</th>
                                <th scope="col">Requested</th>
                                <th scope="col">Expires</th>
                                <th scope="col">Purpose</th>
                                <th scope="col" class="text-end">Decision</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($reservations as $reservation): ?>
                            <?php
                            $statusValue = (string) $reservation['status'];
                            $statusClass = match ($statusValue) {
                                'approved' => 'is-active',
                                'pending' => 'is-pending',
                                'rejected', 'expired' => 'is-failed',
                                default => 'is-neutral',
                            };
                            $expiresAt = (string) ($reservation['expires_at'] ?? '');
                            $lapsed = $expiresAt !== '' && strtotime($expiresAt) !== false && strtotime($expiresAt) < time();
                            ?>
                            <tr>
                                <td class="mono fqdn"><?= e((string) $reservation['label']) ?>.<?= e((string) $reservation['root_domain']) ?></td>
                                <td>
                                    <div><?= e((string) $reservation['user_name']) ?></div>
                                    <div class="text-muted-2" style="font-size:.8rem"><?= e((string) $reservation['user_email']) ?></div>
                                </td>
                                <td><span class="badge-status <?= e($statusClass) ?>"><?= e(ucfirst($statusValue)) ?></span></td>
                                <td class="text-muted-2"><?= e(format_date((string) $reservation['created_at'])) ?></td>
                                <td class="text-muted-2">
                                    <?= e($expiresAt !== '' ? format_date($expiresAt) : '—') ?>
                                    <?php if ($lapsed && $statusValue === 'pending'): ?>
                                        <span class="badge-status is-failed ms-1">lapsed</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted-2" style="max-width:220px"><?= e(str_limit((string) ($reservation['purpose'] ?? '—'), 60)) ?></td>
                                <td class="text-end">
                                    <?php if ($statusValue === 'pending'): ?>
                                        <form method="post" action="<?= e(app_url('/admin/reservations.php')) ?>" class="d-flex flex-column gap-1 align-items-end">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= e((string) $reservation['id']) ?>">
                                            <input type="text" class="form-control form-control-sm" name="note"
                                                   placeholder="Note (optional)" maxlength="255" style="max-width:200px">
                                            <div class="d-flex gap-1">
                                                <button class="btn btn-sm btn-primary" type="submit" name="action" value="approve"
                                                        data-confirm="Approve this reservation and queue provisioning?">
                                                    Approve
                                                </button>
                                                <button class="btn btn-sm btn-outline-secondary" type="submit" name="action" value="reject"
                                                        data-confirm="Reject this reservation?">
                                                    Reject
                                                </button>
                                            </div>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-muted-2" style="font-size:.82rem"><?= e((string) ($reservation['admin_note'] ?? '—')) ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="d-flex align-items-center justify-content-between mb-3" id="provisioning">
            <h2 class="h5 mb-0">DNS provisioning queue</h2>
            <span class="pill pill-neutral"><?= count($jobs) ?> shown</span>
        </div>

        <p class="text-muted-2" style="font-size:.9rem">
            A job becomes a record only when someone runs it. The subdomain is marked
            <strong>active</strong> after the provider confirms the record exists and its value matches.
        </p>

        <div class="table-surface">
            <?php if ($jobs === []): ?>
                <div class="empty-state border-0">
                    <div class="empty-icon"><i data-lucide="server-cog" aria-hidden="true"></i></div>
                    <h3>No provisioning jobs</h3>
                    <p>Approving a reservation puts one here.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th scope="col">#</th>
                                <th scope="col">Hostname</th>
                                <th scope="col">Job</th>
                                <th scope="col">Subdomain</th>
                                <th scope="col">Tries</th>
                                <th scope="col">Last error</th>
                                <th scope="col" class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($jobs as $job): ?>
                            <?php
                            $jobStatus = (string) $job['status'];
                            $jobClass = match ($jobStatus) {
                                'done' => 'is-active',
                                'pending', 'running' => 'is-pending',
                                'failed' => 'is-failed',
                                default => 'is-neutral',
                            };
                            ?>
                            <tr>
                                <td class="text-muted-2"><?= (int) $job['id'] ?></td>
                                <td class="mono fqdn"><?= e((string) $job['fqdn']) ?></td>
                                <td><span class="badge-status <?= e($jobClass) ?>"><?= e(ucfirst($jobStatus)) ?></span></td>
                                <td class="text-muted-2"><?= e((string) ($job['subdomain_status'] ?? '—')) ?></td>
                                <td class="text-muted-2">
                                    <?= (int) $job['attempts'] ?>
                                    <span class="text-muted-2">/ <?= (int) ProvisioningQueue::maxAttempts() ?></span>
                                </td>
                                <td class="text-muted-2" style="max-width:280px">
                                    <?= e(str_limit((string) ($job['last_error'] ?? '—'), 70)) ?>
                                    <?php if ($jobStatus === 'pending' && (string) $job['available_at'] > now()): ?>
                                        <div style="font-size:.78rem">retry after <?= e(format_date((string) $job['available_at'])) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <form method="post" action="<?= e(app_url('/admin/reservations.php')) ?>" class="d-inline-flex gap-1">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= e((string) $job['id']) ?>">
                                        <?php if (in_array($jobStatus, ['pending', 'running'], true)): ?>
                                            <button class="btn btn-sm btn-primary" type="submit" name="action" value="run_job"
                                                    data-confirm="Create the DNS record for <?= e((string) $job['fqdn']) ?> now?">
                                                Run
                                            </button>
                                        <?php endif; ?>
                                        <?php if (in_array($jobStatus, ['failed', 'cancelled'], true)): ?>
                                            <button class="btn btn-sm btn-outline-secondary" type="submit" name="action" value="retry_job">
                                                Retry
                                            </button>
                                        <?php endif; ?>
                                        <?php if (in_array($jobStatus, ['pending', 'failed', 'running'], true)): ?>
                                            <button class="btn btn-sm btn-outline-danger" type="submit" name="action" value="cancel_job"
                                                    data-confirm="Cancel this job? No record will be created.">
                                                Cancel
                                            </button>
                                        <?php endif; ?>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>
