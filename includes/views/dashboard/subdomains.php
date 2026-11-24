<?php
/**
 * My subdomains list (Task 05 §5).
 *
 * Desktop: a table. Mobile: cards. Both are rendered from the same rows — no
 * horizontal scrolling at 320px, and the card is not a shrunken table.
 *
 * @var list<array<string, mixed>> $subdomains  Filtered rows
 * @var list<array<string, mixed>> $visible      Same rows; the name the flow
 *                                               below reads (kept distinct from
 *                                               the filter key for clarity)
 * @var int $total                              Rows on the account
 * @var array<int, array<string, mixed>> $provisioning
 * @var array<string, int> $counts
 * @var string $filter
 * @var list<array<string, mixed>> $domains
 */
$filters = [
    'all' => ['label' => 'All', 'count' => $total],
    'active' => ['label' => 'Active', 'count' => $counts['active'] ?? 0],
    'pending_dns' => ['label' => 'Pending DNS', 'count' => $counts['pending_dns'] ?? 0],
    'failed' => ['label' => 'Failed', 'count' => $counts['failed'] ?? 0],
    'suspended' => ['label' => 'Suspended', 'count' => $counts['suspended'] ?? 0],
    // Read-only: a released name is shown so the owner can confirm what
    // happened, never so it can be managed again.
    'terminated' => ['label' => 'Released', 'count' => $counts['terminated'] ?? 0],
];

$totalWithReleased ??= $total;
?>
<div class="dash-page-actions mb-3" role="group" aria-label="Filter by status">
    <?php foreach ($filters as $key => $meta): ?>
        <?php if ($key !== 'all' && $meta['count'] === 0 && $filter !== $key) { continue; } ?>
        <a class="btn btn-sm <?= $filter === $key ? 'btn-primary' : 'btn-outline-secondary' ?>"
           href="<?= e(app_url('/dashboard/subdomains.php' . ($key === 'all' ? '' : '?status=' . $key))) ?>"
           <?= $filter === $key ? 'aria-current="true"' : '' ?>>
            <?= e($meta['label']) ?>
            <span class="pill <?= $filter === $key ? 'pill-light' : 'pill-neutral' ?> ms-1"><?= (int) $meta['count'] ?></span>
        </a>
    <?php endforeach; ?>
</div>

<?php
// The controller passes the filtered rows twice: once as `subdomains` (what the
// table renders) and once as `visible` (what decides table vs empty state).
$visible = $visible ?? $subdomains;
?>
<?php if ($total === 0): ?>
    <div class="empty-state">
        <div class="empty-icon"><i data-lucide="globe-2" aria-hidden="true"></i></div>
        <h2>No subdomains yet</h2>
        <p>
            A subdomain appears here as soon as you send a reservation, and becomes
            <strong>Active</strong> only after its DNS record has been verified.
        </p>
        <a class="btn btn-primary" href="<?= e(app_url('/dashboard/search.php')) ?>">
            <i data-lucide="search" aria-hidden="true"></i> Find your name
        </a>
    </div>
<?php elseif ($visible === []): ?>
    <div class="empty-state is-compact">
        <div class="empty-icon"><i data-lucide="filter" aria-hidden="true"></i></div>
        <h2>Nothing with that status</h2>
        <p>
            You have <?= (int) $total ?> active subdomain<?= $total === 1 ? '' : 's' ?><?php if ((int) $totalWithReleased > (int) $total): ?>
                and <?= (int) $totalWithReleased - (int) $total ?> released<?php endif; ?>,
            none of them matching this filter.
        </p>
        <a class="btn btn-outline-secondary" href="<?= e(app_url('/dashboard/subdomains.php')) ?>">Show all</a>
    </div>
<?php else: ?>
    <?php if ($filter === 'terminated'): ?>
        <p class="stat-hint mb-2">
            Released names are history: they no longer hold a quota slot and cannot be managed.
            Request the label again if you want it back.
        </p>
    <?php endif; ?>

    <!-- ============================ Desktop ============================ -->
    <div class="table-surface d-none d-md-block">
        <div class="table-responsive">
            <table class="table align-middle">
                <caption class="visually-hidden">
                    Subdomains on your account. Each row links to its details.
                </caption>
                <thead>
                    <tr>
                        <th scope="col">Hostname</th>
                        <th scope="col">Extension</th>
                        <th scope="col">Status</th>
                        <th scope="col">Provisioning</th>
                        <th scope="col">Created</th>
                        <th scope="col" class="text-end"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($subdomains as $row): ?>
                    <?php
                    $id = (int) $row['id'];
                    $status = (string) $row['status'];
                    $job = $provisioning[$id] ?? null;
                    $href = app_url('/dashboard/subdomain.php?id=' . $id);
                    ?>
                    <tr>
                        <td>
                            <a class="fqdn mono" href="<?= e($href) ?>"><?= e((string) $row['fqdn']) ?></a>
                        </td>
                        <td class="text-muted-2"><?= e((string) ($row['root_domain'] ?? '—')) ?></td>
                        <td><?php partial('partials/status-badge', ['status' => $status, 'kind' => 'subdomain']); ?></td>
                        <td>
                            <?php if ($job !== null): ?>
                                <?php partial('partials/status-badge', ['status' => (string) $job['status'], 'kind' => 'job']); ?>
                                <?php if ((int) $job['attempts'] > 0): ?>
                                    <div class="text-muted-2" style="font-size:.76rem"><?= (int) $job['attempts'] ?> attempt<?= (int) $job['attempts'] === 1 ? '' : 's' ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted-2">Not queued</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted-2"><?= e(format_date((string) ($row['created_at'] ?? null))) ?></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e($href) ?>">Details</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ============================= Mobile ============================= -->
    <div class="d-md-none">
        <?php foreach ($subdomains as $row): ?>
            <?php
            $id = (int) $row['id'];
            $job = $provisioning[$id] ?? null;
            $href = app_url('/dashboard/subdomain.php?id=' . $id);
            ?>
            <div class="sub-card">
                <div class="sub-card-head">
                    <a class="sub-card-host mono" href="<?= e($href) ?>"><?= e((string) $row['fqdn']) ?></a>
                    <?php partial('partials/status-badge', ['status' => (string) $row['status'], 'kind' => 'subdomain']); ?>
                </div>
                <div class="sub-card-meta">
                    <span><?= e((string) ($row['root_domain'] ?? '')) ?></span>
                    <span>Created <?= e(format_date((string) ($row['created_at'] ?? null))) ?></span>
                    <?php if ($job !== null): ?>
                        <span>Provisioning: <?= e(DashboardStatus::job((string) $job['status'])['label']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="sub-card-actions">
                    <a class="btn btn-sm btn-outline-secondary flex-grow-1" href="<?= e($href) ?>">Details</a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
