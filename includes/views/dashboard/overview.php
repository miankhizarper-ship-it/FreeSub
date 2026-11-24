<?php
/**
 * Overview (Task 05 §4).
 *
 * Every number on this page is read from the database in dashboard.php. The
 * view formats, it does not decide: statuses are described by DashboardStatus
 * and the quota figures come from SubdomainQuota, exactly as the reservation
 * endpoint computes them.
 *
 * @var array<string, mixed> $authUser
 * @var list<array<string, mixed>> $subdomains        Non-terminated subdomains
 * @var array<int, array<string, mixed>> $provisioning Latest job per subdomain id
 * @var array{active:int, pending_dns:int, failed:int} $counts
 * @var list<array<string, mixed>> $pendingReservations Pending + approved
 * @var array{used:int, limit:int, remaining:int, primary:array<string,mixed>|null} $quota
 * @var list<array{domain_id:int, root_domain:string, limit:int, used:int, remaining:int}> $quotaByDomain
 * @var list<array<string, mixed>> $domains
 * @var string $firstName
 * @var array{label:string, tone:string} $accountState
 * @var bool $welcome
 */
$total = count($subdomains);

// Round *down* so the bar never claims more progress than has happened.
$percent = $quota['limit'] > 0 ? (int) floor(($quota['used'] / $quota['limit']) * 100) : 0;
$percent = max(0, min(100, $percent));
$isFull = $quota['limit'] > 0 && $quota['used'] >= $quota['limit'];
$isOver = $quota['limit'] > 0 && $quota['used'] > $quota['limit'];

// One recent-activity feed assembled from real rows only: no synthetic entry,
// no guessed timestamp. Sorted newest first by the timestamp each row carries.
$activity = [];

foreach (array_slice($subdomains, 0, 5) as $row) {
    $activity[] = [
        'at' => (string) ($row['updated_at'] ?? $row['created_at'] ?? ''),
        'kind' => 'subdomain',
        'title' => (string) $row['fqdn'],
        'status' => (string) $row['status'],
        'href' => '/dashboard/subdomain.php?id=' . (int) $row['id'],
        'label' => 'Subdomain',
    ];
}

foreach (array_slice($pendingReservations, 0, 5) as $row) {
    $activity[] = [
        'at' => (string) ($row['created_at'] ?? ''),
        'kind' => 'reservation',
        'title' => (string) $row['label'] . '.' . (string) $row['root_domain'],
        'status' => (string) $row['status'],
        'href' => '/dashboard/reservations.php',
        'label' => 'Reservation',
    ];
}

usort($activity, static fn (array $a, array $b): int => strcmp($b['at'], $a['at']));
$activity = array_slice($activity, 0, 6);
?>
<?php if (!empty($welcome)): ?>
    <div class="alert alert-info" role="status">
        <i data-lucide="party-popper" aria-hidden="true"></i>
        <div>
            <strong>Your account is ready, <?= e($firstName) ?>.</strong>
            Find a name to start with, or read what happens between a request and a live record.
        </div>
    </div>
<?php endif; ?>

<!-- ============================== Stat cards ============================== -->
<section aria-labelledby="stats-heading">
    <h2 class="visually-hidden" id="stats-heading">Account statistics</h2>

    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-label"><i data-lucide="globe-2" aria-hidden="true"></i> Active subdomains</div>
                <div class="stat-value"><?= (int) $counts['active'] ?></div>
                <div class="stat-hint">
                    <?php if ($counts['active'] === 0): ?>
                        None live yet
                    <?php else: ?>
                        DNS record verified
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-label"><i data-lucide="loader-circle" aria-hidden="true"></i> Pending provisioning</div>
                <div class="stat-value"><?= (int) $counts['pending_dns'] ?></div>
                <div class="stat-hint">
                    <?php if ($counts['failed'] > 0): ?>
                        <?= (int) $counts['failed'] ?> failed — see the details
                    <?php elseif ($counts['pending_dns'] === 0): ?>
                        Nothing waiting on DNS
                    <?php else: ?>
                        Approved, waiting on DNS
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-label"><i data-lucide="inbox" aria-hidden="true"></i> Pending reservations</div>
                <div class="stat-value"><?= count($pendingReservations) ?></div>
                <div class="stat-hint">
                    <?php if ($pendingReservations === []): ?>
                        Nothing awaiting review
                    <?php else: ?>
                        <a href="<?= e(app_url('/dashboard/reservations.php')) ?>">Review your requests</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-label"><i data-lucide="gauge" aria-hidden="true"></i> Available quota</div>
                <div class="stat-value"><?= (int) $quota['remaining'] ?></div>
                <div class="stat-hint">
                    <?php if ($quota['remaining'] === 0): ?>
                        All <?= (int) $quota['limit'] ?> slots in use
                    <?php else: ?>
                        of <?= (int) $quota['limit'] ?> total
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<div class="row g-3 g-lg-4">
    <!-- ============================ Quota ============================== -->
    <div class="col-lg-5">
        <section class="panel h-100" aria-labelledby="quota-heading">
            <div class="panel-body">
                <div class="section-head">
                    <h2 id="quota-heading">Quota</h2>
                    <span class="pill pill-neutral"><?= $isFull ? 'Full' : 'In use' ?></span>
                </div>

                <div class="quota-meter">
                    <div class="quota-meter-top">
                        <span class="quota-figure">
                            <?= (int) $quota['used'] ?> <small>/ <?= (int) $quota['limit'] ?> used</small>
                        </span>
                        <span class="quota-remaining">
                            <?php if ($isOver): ?>
                                <?= (int) ($quota['used'] - $quota['limit']) ?> over the limit
                            <?php else: ?>
                                <?= (int) $quota['remaining'] ?> left
                            <?php endif; ?>
                        </span>
                    </div>

                    <div class="progress-thin<?= $isOver ? ' is-over' : ($isFull ? ' is-full' : '') ?>"
                         role="progressbar"
                         aria-valuemin="0" aria-valuemax="<?= (int) $quota['limit'] ?>"
                         aria-valuenow="<?= (int) $quota['used'] ?>"
                         aria-valuetext="<?= (int) $quota['used'] ?> of <?= (int) $quota['limit'] ?> slots used"
                         aria-label="Subdomain quota used">
                        <span style="width: <?= $percent ?>%"></span>
                    </div>

                    <p class="stat-hint mb-0">
                        Counts every name you hold plus every request awaiting review. A quota of 0 is never
                        served silently — the reservation form refuses with an explanation.
                    </p>
                </div>

                <?php if ($quotaByDomain !== []): ?>
                    <hr class="divider-soft my-3">
                    <div class="section-head mb-2">
                        <h3 class="h6 mb-0" style="font-size:.85rem">Per extension</h3>
                    </div>
                    <dl class="detail-list" style="grid-template-columns:minmax(0,1fr) auto">
                        <?php foreach ($quotaByDomain as $row): ?>
                            <dt class="mono">.<?= e($row['root_domain']) ?></dt>
                            <dd class="text-end">
                                <?= (int) $row['used'] ?> / <?= (int) $row['limit'] ?>
                                <?php if ($row['remaining'] === 0): ?>
                                    <span class="badge-status is-pending ms-1">Full</span>
                                <?php endif; ?>
                            </dd>
                        <?php endforeach; ?>
                    </dl>
                <?php endif; ?>

                <?php if ($isFull): ?>
                    <div class="alert alert-warning mt-3 mb-0" role="status">
                        <i data-lucide="info" aria-hidden="true"></i>
                        <div>
                            <strong>No slots left.</strong>
                            Cancel a pending request, release a name you no longer need, or contact support
                            about an increase.
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <!-- =========================== Account ============================== -->
    <div class="col-lg-7">
        <section class="panel h-100" aria-labelledby="account-heading">
            <div class="panel-body">
                <div class="section-head">
                    <h2 id="account-heading">Account</h2>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(app_url('/dashboard/settings.php')) ?>">Manage</a>
                </div>

                <div class="d-flex align-items-center gap-3 mb-3">
                    <span class="dash-avatar" style="width:46px;height:46px;flex-basis:46px;font-size:.95rem" aria-hidden="true">
                        <?= e(initials((string) $authUser['name'])) ?>
                    </span>
                    <div class="min-w-0">
                        <div class="fw-bold"><?= e((string) $authUser['name']) ?></div>
                        <div class="text-muted-2" style="font-size:.86rem;overflow-wrap:anywhere"><?= e((string) $authUser['email']) ?></div>
                    </div>
                </div>

                <dl class="detail-list">
                    <dt>Account status</dt>
                    <dd><?php partial('partials/status-badge', ['status' => (string) ($authUser['status'] ?? 'active'), 'kind' => 'account']); ?></dd>

                    <dt>Sign-in provider</dt>
                    <dd>Clerk-managed sign-in</dd>

                    <dt>Member since</dt>
                    <dd><?= e(format_date((string) ($authUser['created_at'] ?? null))) ?></dd>

                    <dt>Subdomains</dt>
                    <dd><?= (int) $total ?> on file</dd>
                </dl>

                <p class="stat-hint mb-0 mt-3">
                    Your name and e-mail come from your verified Clerk identity. FreeSub never stores
                    a password for you.
                </p>
            </div>
        </section>
    </div>
</div>

<!-- ======================= Subdomains snapshot ======================= -->
<section class="mt-4" aria-labelledby="subdomains-heading">
    <div class="section-head">
        <h2 id="subdomains-heading">Your subdomains</h2>
        <?php if ($total > 0): ?>
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(app_url('/dashboard/subdomains.php')) ?>">
                View all <?= $total ?> <i data-lucide="arrow-right" aria-hidden="true"></i>
            </a>
        <?php endif; ?>
    </div>

    <?php if ($total === 0): ?>
        <div class="empty-state">
            <div class="empty-icon"><i data-lucide="globe-2" aria-hidden="true"></i></div>
            <h3>No subdomains yet</h3>
            <p>
                Reserve a name and it will appear here immediately — as <em>pending DNS</em> until the
                record has been created and verified. We never show a name as live before that.
            </p>
            <a class="btn btn-primary" href="<?= e(app_url('/dashboard/search.php')) ?>">
                <i data-lucide="search" aria-hidden="true"></i> Find your name
            </a>
        </div>
    <?php else: ?>
        <div class="table-surface d-none d-md-block">
            <div class="table-responsive">
                <table class="table align-middle">
                    <caption class="visually-hidden">Your most recent subdomains</caption>
                    <thead>
                        <tr>
                            <th scope="col">Hostname</th>
                            <th scope="col">Status</th>
                            <th scope="col">Provisioning</th>
                            <th scope="col">Created</th>
                            <th scope="col" class="text-end"><span class="visually-hidden">Open</span></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach (array_slice($subdomains, 0, 5) as $row): ?>
                        <?php
                        $status = (string) $row['status'];
                        $job = $provisioning[(int) $row['id']] ?? null;
                        ?>
                        <tr>
                            <td><a class="fqdn mono" href="<?= e(app_url('/dashboard/subdomain.php?id=' . (int) $row['id'])) ?>"><?= e((string) $row['fqdn']) ?></a></td>
                            <td><?php partial('partials/status-badge', ['status' => $status, 'kind' => 'subdomain']); ?></td>
                            <td>
                                <?php if ($job !== null): ?>
                                    <?php partial('partials/status-badge', ['status' => (string) $job['status'], 'kind' => 'job']); ?>
                                <?php else: ?>
                                    <span class="text-muted-2">Not queued</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted-2"><?= e(format_date((string) ($row['created_at'] ?? null))) ?></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-secondary" href="<?= e(app_url('/dashboard/subdomain.php?id=' . (int) $row['id'])) ?>">Details</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Mobile equivalent: the same rows as cards, no horizontal scroll -->
        <div class="d-md-none">
            <?php foreach (array_slice($subdomains, 0, 5) as $row): ?>
                <?php $job = $provisioning[(int) $row['id']] ?? null; ?>
                <div class="sub-card">
                    <div class="sub-card-head">
                        <a class="sub-card-host mono" href="<?= e(app_url('/dashboard/subdomain.php?id=' . (int) $row['id'])) ?>"><?= e((string) $row['fqdn']) ?></a>
                        <?php partial('partials/status-badge', ['status' => (string) $row['status'], 'kind' => 'subdomain']); ?>
                    </div>
                    <div class="sub-card-meta">
                        <span>Created <?= e(format_date((string) ($row['created_at'] ?? null))) ?></span>
                        <?php if ($job !== null): ?>
                            <span>Provisioning: <?= e(DashboardStatus::job((string) $job['status'])['label']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<!-- ========================== Recent activity ========================== -->
<?php if ($activity !== []): ?>
    <section class="mt-4" aria-labelledby="activity-heading">
        <div class="section-head">
            <h2 id="activity-heading">Recent activity</h2>
        </div>

        <div class="panel">
            <div class="panel-body py-2">
                <ul class="list-unstyled mb-0">
                    <?php foreach ($activity as $item): ?>
                        <li class="d-flex flex-wrap align-items-center gap-2 py-2 border-bottom" style="border-color:var(--fs-line)!important">
                            <span class="badge-status is-neutral"><?= e($item['label']) ?></span>
                            <a class="mono fw-semibold text-decoration-none" href="<?= e(app_url($item['href'])) ?>"><?= e($item['title']) ?></a>
                            <?php partial('partials/status-badge', [
                                'status' => $item['status'],
                                'kind' => $item['kind'] === 'subdomain' ? 'subdomain' : 'reservation',
                            ]); ?>
                            <span class="ms-auto text-muted-2" style="font-size:.8rem">
                                <?= e(format_date($item['at'] !== '' ? $item['at'] : null, 'M j, Y H:i')) ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </section>
<?php endif; ?>

<!-- ============================ Next steps ============================= -->
<section class="mt-4" aria-labelledby="next-heading">
    <div class="section-head">
        <h2 id="next-heading">What happens between a request and a live name</h2>
    </div>

    <div class="panel">
        <div class="panel-body">
            <ol class="mb-0 ps-3" style="font-size:.9rem;color:var(--fs-ink-soft)">
                <li class="mb-1">You request a label. It is checked against our database and the live zone.</li>
                <li class="mb-1">A person reviews the request. Approved names are queued for provisioning.</li>
                <li class="mb-1">A worker creates the DNS record, then reads it back from the zone to verify it.</li>
                <li>Only after that verification does the name show as <strong>Active</strong> here.</li>
            </ol>
        </div>
    </div>
</section>
