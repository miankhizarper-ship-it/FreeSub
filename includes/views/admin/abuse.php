<?php
/**
 * @var list<array<string, mixed>> $reports
 */
?>
<section class="page-header">
    <div class="container">
        <nav class="breadcrumb-mini" aria-label="Breadcrumb">
            <a href="<?= e(app_url('/admin/index.php')) ?>">Admin</a>
            <i data-lucide="chevron-right" aria-hidden="true" style="width:12px;height:12px"></i>
            <span>Abuse reports</span>
        </nav>
        <h1>Abuse reports</h1>
        <p>Every report is reviewed by a human before any hostname is affected.</p>
    </div>
</section>

<section class="section-tight">
    <div class="container">
        <?php partial('partials/flashes'); ?>

        <?php if ($reports === []): ?>
            <div class="empty-state">
                <div class="empty-icon"><i data-lucide="shield-check" aria-hidden="true"></i></div>
                <h2>No reports</h2>
                <p>Nothing has been reported yet.</p>
            </div>
        <?php else: ?>
            <div class="d-grid gap-3">
                <?php foreach ($reports as $report): ?>
                    <?php
                    $status = (string) $report['status'];
                    $statusClass = match ($status) {
                        'new' => 'is-pending',
                        'reviewing' => 'is-brand',
                        'resolved' => 'is-active',
                        default => 'is-neutral',
                    };
                    ?>
                    <article class="card-surface p-3 p-md-4">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                            <div>
                                <div class="fqdn mono"><?= e((string) $report['reported_fqdn']) ?></div>
                                <div class="text-muted-2" style="font-size:.82rem">
                                    <?= e(ucfirst((string) $report['category'])) ?> ·
                                    <?= e((string) $report['reporter_email']) ?> ·
                                    <?= e(format_date((string) $report['created_at'], 'M j, Y H:i')) ?> UTC
                                </div>
                            </div>
                            <span class="badge-status <?= e($statusClass) ?>"><?= e(ucfirst($status)) ?></span>
                        </div>

                        <p class="mb-3" style="font-size:.93rem;white-space:pre-line"><?= e((string) $report['message']) ?></p>

                        <?php if ($status === 'new' || $status === 'reviewing'): ?>
                            <form method="post" action="<?= e(app_url('/admin/abuse.php')) ?>" class="row g-2 align-items-end">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= e((string) $report['id']) ?>">
                                <div class="col-md-5">
                                    <label class="form-label" for="note-<?= e((string) $report['id']) ?>">Resolution note</label>
                                    <input type="text" class="form-control form-control-sm" id="note-<?= e((string) $report['id']) ?>"
                                           name="note" maxlength="1000" placeholder="Optional">
                                </div>
                                <div class="col-md-7 d-flex flex-wrap gap-2">
                                    <?php if ($status === 'new'): ?>
                                        <button class="btn btn-sm btn-outline-secondary" name="action" value="review" type="submit">Mark reviewing</button>
                                    <?php endif; ?>
                                    <button class="btn btn-sm btn-primary" name="action" value="resolve" type="submit" data-confirm="Mark this report as resolved?">Resolve</button>
                                    <button class="btn btn-sm btn-outline-secondary" name="action" value="dismiss" type="submit" data-confirm="Dismiss this report?">Dismiss</button>
                                </div>
                            </form>
                        <?php else: ?>
                            <p class="mb-0 text-muted-2" style="font-size:.85rem">
                                Handled <?= e(format_date((string) ($report['handled_at'] ?? null), 'M j, Y H:i')) ?>
                                <?php if (!empty($report['resolution_note'])): ?>— <?= e((string) $report['resolution_note']) ?><?php endif; ?>
                            </p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
