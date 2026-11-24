<?php
/**
 * @var list<array<string, mixed>> $reservations
 * @var string $status
 */
$tabs = [
    'pending' => 'Pending',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'cancelled' => 'Cancelled',
    'all' => 'All',
];
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
                <p>Approving creates a pending-DNS subdomain. It does not create DNS records.</p>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 mt-3">
            <?php foreach ($tabs as $key => $label): ?>
                <a class="btn btn-sm <?= $status === $key ? 'btn-primary' : 'btn-outline-secondary' ?>"
                   href="<?= e(app_url('/admin/reservations.php?status=' . $key)) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="section-tight">
    <div class="container">
        <?php partial('partials/flashes'); ?>

        <div class="table-surface">
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
                            ?>
                            <tr>
                                <td class="mono fqdn"><?= e((string) $reservation['label']) ?>.<?= e((string) $reservation['root_domain']) ?></td>
                                <td>
                                    <div><?= e((string) $reservation['user_name']) ?></div>
                                    <div class="text-muted-2" style="font-size:.8rem"><?= e((string) $reservation['user_email']) ?></div>
                                </td>
                                <td><span class="badge-status <?= e($statusClass) ?>"><?= e(ucfirst($statusValue)) ?></span></td>
                                <td class="text-muted-2"><?= e(format_date((string) $reservation['created_at'])) ?></td>
                                <td class="text-muted-2" style="max-width:220px"><?= e(str_limit((string) ($reservation['purpose'] ?? '—'), 60)) ?></td>
                                <td class="text-end">
                                    <?php if ($statusValue === 'pending'): ?>
                                        <div class="d-flex gap-1 justify-content-end">
                                            <form method="post" action="<?= e(app_url('/admin/reservations.php')) ?>">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="approve">
                                                <input type="hidden" name="id" value="<?= e((string) $reservation['id']) ?>">
                                                <button class="btn btn-sm btn-primary" type="submit" data-confirm="Approve this reservation and create a pending-DNS subdomain?">Approve</button>
                                            </form>
                                            <form method="post" action="<?= e(app_url('/admin/reservations.php')) ?>">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="reject">
                                                <input type="hidden" name="id" value="<?= e((string) $reservation['id']) ?>">
                                                <button class="btn btn-sm btn-outline-secondary" type="submit" data-confirm="Reject this reservation?">Reject</button>
                                            </form>
                                        </div>
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
    </div>
</section>
