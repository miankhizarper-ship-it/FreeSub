<?php
/**
 * Reservations & history (Task 05 §8).
 *
 * Outcomes are described by DashboardStatus so they match the list and detail
 * pages. Timestamps are printed only where the row has one — an approval that
 * never happened leaves the "Reviewed" cell empty rather than inventing a date.
 *
 * @var list<array<string, mixed>> $reservations
 * @var array<int, array<string, mixed>> $linked  reservation id => subdomain
 * @var array<string, int> $counts
 * @var int $total
 * @var string $filter
 * @var list<array<string, mixed>> $domains
 */
$filters = [
    'all' => ['label' => 'All', 'count' => $counts['all'] ?? 0],
    'pending' => ['label' => 'Awaiting review', 'count' => $counts['pending'] ?? 0],
    'approved' => ['label' => 'Approved', 'count' => $counts['approved'] ?? 0],
    'rejected' => ['label' => 'Rejected', 'count' => $counts['rejected'] ?? 0],
    'cancelled' => ['label' => 'Cancelled', 'count' => $counts['cancelled'] ?? 0],
    'expired' => ['label' => 'Expired', 'count' => $counts['expired'] ?? 0],
];
?>
<div class="dash-page-actions mb-3" role="group" aria-label="Filter reservations by outcome">
    <?php foreach ($filters as $key => $meta): ?>
        <?php if ($key !== 'all' && ($meta['count'] ?? 0) === 0 && $filter !== $key) { continue; } ?>
        <a class="btn btn-sm <?= $filter === $key ? 'btn-primary' : 'btn-outline-secondary' ?>"
           href="<?= e(app_url('/dashboard/reservations.php' . ($key === 'all' ? '' : '?status=' . $key))) ?>"
           <?= $filter === $key ? 'aria-current="true"' : '' ?>>
            <?= e($meta['label']) ?>
            <span class="pill <?= $filter === $key ? 'pill-light' : 'pill-neutral' ?> ms-1"><?= (int) ($meta['count'] ?? 0) ?></span>
        </a>
    <?php endforeach; ?>
</div>

<?php
// `reservations` is what the table renders; `visible` decides table vs empty
// state. The controller passes both, and the fallback keeps a bare render safe.
$visible = $visible ?? $reservations;
?>
<?php if ($total === 0): ?>
    <div class="empty-state">
        <div class="empty-icon"><i data-lucide="inbox" aria-hidden="true"></i></div>
        <h2>No reservations yet</h2>
        <p>
            When you request a subdomain it is listed here from the moment you send it, through review,
            provisioning, and any later outcome.
        </p>
        <a class="btn btn-primary" href="<?= e(app_url('/dashboard/search.php')) ?>">
            <i data-lucide="search" aria-hidden="true"></i> Request your first name
        </a>
    </div>
<?php elseif ($visible === []): ?>
    <div class="empty-state is-compact">
        <div class="empty-icon"><i data-lucide="filter" aria-hidden="true"></i></div>
        <h2>Nothing with that outcome</h2>
        <p>You have <?= (int) $total ?> reservation<?= $total === 1 ? '' : 's' ?>, none matching this filter.</p>
        <a class="btn btn-outline-secondary" href="<?= e(app_url('/dashboard/reservations.php')) ?>">Show all</a>
    </div>
<?php else: ?>
    <!-- ============================ Desktop ============================ -->
    <div class="table-surface d-none d-md-block">
        <div class="table-responsive">
            <table class="table align-middle">
                <caption class="visually-hidden">Your reservations, newest first.</caption>
                <thead>
                    <tr>
                        <th scope="col">Requested name</th>
                        <th scope="col">Outcome</th>
                        <th scope="col">Requested</th>
                        <th scope="col">Reviewed</th>
                        <th scope="col">Hold expires</th>
                        <th scope="col">Subdomain</th>
                        <th scope="col" class="text-end"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($reservations as $row): ?>
                    <?php
                    $id = (int) $row['id'];
                    $status = (string) $row['status'];
                    $subdomain = $linked[$id] ?? null;
                    ?>
                    <tr>
                        <td>
                            <span class="fqdn mono"><?= e((string) $row['label'] . '.' . (string) ($row['root_domain'] ?? '')) ?></span>
                            <?php if (($row['purpose'] ?? null) !== null && (string) $row['purpose'] !== ''): ?>
                                <div class="text-muted-2" style="font-size:.78rem"><?= e(str_limit((string) $row['purpose'], 60)) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php partial('partials/status-badge', ['status' => $status, 'kind' => 'reservation']); ?>
                            <?php if ($status === 'rejected' && ($row['admin_note'] ?? null) !== null && (string) $row['admin_note'] !== ''): ?>
                                <div class="text-muted-2" style="font-size:.78rem;max-width:26ch"><?= e((string) $row['admin_note']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted-2"><?= e(format_date((string) ($row['created_at'] ?? null))) ?></td>
                        <td class="text-muted-2">
                            <?php if (($row['reviewed_at'] ?? null) !== null): ?>
                                <?= e(format_date((string) $row['reviewed_at'])) ?>
                            <?php else: ?>
                                <span aria-label="Not reviewed yet">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted-2">
                            <?php if ($status === 'pending' && ($row['expires_at'] ?? null) !== null): ?>
                                <?= e(format_date((string) $row['expires_at'])) ?>
                            <?php else: ?>
                                <span aria-label="No hold">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($subdomain !== null): ?>
                                <a class="mono" href="<?= e(app_url('/dashboard/subdomain.php?id=' . (int) $subdomain['id'])) ?>">
                                    <?= e((string) $subdomain['fqdn']) ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted-2">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?php if ($status === 'pending'): ?>
                                <form method="post" action="<?= e(app_url('/dashboard/reservations.php')) ?>" class="d-inline" data-busy-form>
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="cancel_reservation">
                                    <input type="hidden" name="id" value="<?= $id ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary" data-busy-button
                                            data-confirm="Cancel the request for <?= e((string) $row['label'] . '.' . (string) ($row['root_domain'] ?? '')) ?>? The label is released immediately.">
                                        Cancel
                                    </button>
                                </form>
                            <?php else: ?>
                                <span class="text-muted-2">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ============================= Mobile ============================= -->
    <div class="d-md-none">
        <?php foreach ($reservations as $row): ?>
            <?php
            $id = (int) $row['id'];
            $status = (string) $row['status'];
            $subdomain = $linked[$id] ?? null;
            $meta = DashboardStatus::reservation($status);
            ?>
            <div class="sub-card">
                <div class="sub-card-head">
                    <span class="sub-card-host mono"><?= e((string) $row['label'] . '.' . (string) ($row['root_domain'] ?? '')) ?></span>
                    <?php partial('partials/status-badge', ['status' => $status, 'kind' => 'reservation']); ?>
                </div>

                <div class="sub-card-meta">
                    <span>Requested <?= e(format_date((string) ($row['created_at'] ?? null))) ?></span>
                    <?php if (($row['reviewed_at'] ?? null) !== null): ?>
                        <span>Reviewed <?= e(format_date((string) $row['reviewed_at'])) ?></span>
                    <?php endif; ?>
                    <?php if ($status === 'pending' && ($row['expires_at'] ?? null) !== null): ?>
                        <span>Hold expires <?= e(format_date((string) $row['expires_at'])) ?></span>
                    <?php endif; ?>
                    <?php if ($subdomain !== null): ?>
                        <a href="<?= e(app_url('/dashboard/subdomain.php?id=' . (int) $subdomain['id'])) ?>"><?= e((string) $subdomain['fqdn']) ?></a>
                    <?php endif; ?>
                </div>

                <?php if ($meta['hint'] !== ''): ?>
                    <p class="text-muted-2 mb-0 mt-2" style="font-size:.8rem"><?= e($meta['hint']) ?></p>
                <?php endif; ?>

                <?php if ($status === 'rejected' && ($row['admin_note'] ?? null) !== null && (string) $row['admin_note'] !== ''): ?>
                    <p class="mb-0 mt-2" style="font-size:.82rem;color:#991b1b"><?= e((string) $row['admin_note']) ?></p>
                <?php endif; ?>

                <?php if ($status === 'pending'): ?>
                    <div class="sub-card-actions">
                        <form method="post" action="<?= e(app_url('/dashboard/reservations.php')) ?>" class="flex-grow-1" data-busy-form>
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="cancel_reservation">
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <button type="submit" class="btn btn-sm btn-outline-secondary w-100" data-busy-button
                                    data-confirm="Cancel this request? The label is released immediately.">
                                Cancel request
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
