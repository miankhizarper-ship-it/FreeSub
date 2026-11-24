<?php
/**
 * @var array<string, mixed> $authUser
 * @var list<array<string, mixed>> $subdomains
 * @var list<array<string, mixed>> $reservations
 * @var list<array<string, mixed>> $domains
 * @var int $quotaLimit
 * @var int $quotaUsed
 * @var bool $welcome
 * @var string $prefillLabel
 * @var int $prefillDomainId
 */
$usedPercent = $quotaLimit > 0 ? min(100, (int) round(($quotaUsed / $quotaLimit) * 100)) : 0;
$firstName = explode(' ', trim((string) $authUser['name']))[0];
$pendingCount = 0;

foreach ($reservations as $reservation) {
    if (($reservation['status'] ?? '') === 'pending') {
        $pendingCount++;
    }
}
?>
<section class="page-header">
    <div class="container">
        <div class="row align-items-end g-3">
            <div class="col-lg-8">
                <nav class="breadcrumb-mini" aria-label="Breadcrumb">
                    <a href="<?= e(app_url('/')) ?>">Home</a>
                    <i data-lucide="chevron-right" aria-hidden="true" style="width:12px;height:12px"></i>
                    <span>Dashboard</span>
                </nav>
                <h1>Hi <?= e($firstName) ?>, here is your workspace.</h1>
                <p>Reserve names, track provisioning and keep an eye on your quota.</p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <a class="btn btn-primary" href="#reserve">
                    <i data-lucide="plus" aria-hidden="true"></i> Reserve a subdomain
                </a>
            </div>
        </div>
    </div>
</section>

<section class="section-tight">
    <div class="container">
        <?php partial('partials/flashes'); ?>

        <?php if (!empty($welcome)): ?>
            <div class="alert alert-info" role="status">
                <i data-lucide="party-popper" aria-hidden="true"></i>
                <div>
                    <strong>You are in.</strong> Search for a name below and send your first reservation.
                    DNS automation is still being finished, so new names appear as <em>pending DNS</em> until we provision them.
                </div>
            </div>
        <?php endif; ?>

        <div class="row g-3 g-lg-4 mb-4">
            <div class="col-sm-6 col-xl-3">
                <div class="stat-card">
                    <div class="stat-label"><i data-lucide="globe" aria-hidden="true"></i> Subdomains</div>
                    <div class="stat-value"><?= e((string) $quotaUsed) ?></div>
                    <div class="stat-hint">of <?= e((string) $quotaLimit) ?> allowed</div>
                    <div class="progress-thin mt-2"><span style="width: <?= e((string) $usedPercent) ?>%"></span></div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="stat-card">
                    <div class="stat-label"><i data-lucide="hourglass" aria-hidden="true"></i> Pending requests</div>
                    <div class="stat-value"><?= e((string) $pendingCount) ?></div>
                    <div class="stat-hint">awaiting review</div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="stat-card">
                    <div class="stat-label"><i data-lucide="layers" aria-hidden="true"></i> Extensions</div>
                    <div class="stat-value"><?= e((string) count($domains)) ?></div>
                    <div class="stat-hint">open for registration</div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="stat-card">
                    <div class="stat-label"><i data-lucide="user-round" aria-hidden="true"></i> Account</div>
                    <div class="stat-value" style="font-size:1.05rem;letter-spacing:0">
                        <?= e((string) $authUser['email']) ?>
                    </div>
                    <div class="stat-hint">member since <?= e(format_date((string) $authUser['created_at'], 'M j, Y')) ?></div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-7 order-lg-1">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h2 class="h5 mb-0">Your subdomains</h2>
                    <span class="pill pill-neutral"><?= e((string) count($subdomains)) ?> total</span>
                </div>

                <div class="table-surface">
                    <?php if ($subdomains === []): ?>
                        <div class="empty-state border-0">
                            <div class="empty-icon"><i data-lucide="globe-2" aria-hidden="true"></i></div>
                            <h3>No subdomains yet</h3>
                            <p>Reserve a name with the form on this page. It will show up here with its live status.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead>
                                    <tr>
                                        <th scope="col">Hostname</th>
                                        <th scope="col">Status</th>
                                        <th scope="col">Created</th>
                                        <th scope="col" class="text-end"><span class="visually-hidden">Actions</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($subdomains as $subdomain): ?>
                                    <?php
                                    $status = (string) $subdomain['status'];
                                    $statusClass = match ($status) {
                                        'active' => 'is-active',
                                        'pending_dns' => 'is-pending',
                                        'failed', 'suspended' => 'is-failed',
                                        default => 'is-neutral',
                                    };
                                    $statusLabel = match ($status) {
                                        'active' => 'Active',
                                        'pending_dns' => 'Pending DNS',
                                        'failed' => 'Failed',
                                        'suspended' => 'Suspended',
                                        'terminated' => 'Released',
                                        default => ucfirst($status),
                                    };
                                    ?>
                                    <tr>
                                        <td><span class="fqdn mono"><?= e((string) $subdomain['fqdn']) ?></span></td>
                                        <td><span class="badge-status <?= e($statusClass) ?>"><?= e($statusLabel) ?></span></td>
                                        <td class="text-muted-2"><?= e(format_date((string) $subdomain['created_at'])) ?></td>
                                        <td class="text-end">
                                            <form method="post" action="<?= e(app_url('/dashboard.php')) ?>" class="d-inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="release_subdomain">
                                                <input type="hidden" name="id" value="<?= e((string) $subdomain['id']) ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-secondary"
                                                        data-confirm="Release <?= e((string) $subdomain['fqdn']) ?>? This cannot be undone.">
                                                    Release
                                                </button>
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

            <div class="col-lg-5 order-lg-2">
                <div class="form-card" id="reserve">
                    <h2 class="h5 mb-1">Reserve a subdomain</h2>
                    <p class="form-text mb-3">Availability is checked before the request is saved.</p>

                    <?php if ($domains === []): ?>
                        <div class="empty-state">
                            <div class="empty-icon"><i data-lucide="globe-lock" aria-hidden="true"></i></div>
                            <h3>No extensions open</h3>
                            <p>Registration is paused while we prepare the next root domain.</p>
                        </div>
                    <?php else: ?>
                        <form method="post" action="<?= e(app_url('/dashboard.php')) ?>" novalidate>
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="reserve">

                            <div class="mb-3">
                                <label class="form-label" for="reserve-label">Subdomain</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="reserve-label" name="label"
                                           value="<?= e($prefillLabel ?? '') ?>"
                                           placeholder="yourname" maxlength="32" required autocomplete="off" spellcheck="false">
                                    <select class="form-select" name="domain_id" id="reserve-domain" style="max-width:45%" aria-label="Extension">
                                        <?php foreach ($domains as $domain): ?>
                                            <option value="<?= e((string) $domain['id']) ?>"
                                                <?= (int) ($prefillDomainId ?? 0) === (int) $domain['id'] ? 'selected' : '' ?>>.<?= e((string) $domain['extension']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-text">Lowercase letters, numbers and hyphens. 3–32 characters.</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="reserve-purpose">What is it for? <span class="text-muted-2 fw-normal">(optional)</span></label>
                                <input type="text" class="form-control" id="reserve-purpose" name="purpose"
                                       maxlength="255" placeholder="Portfolio, side project, API sandbox…">
                            </div>

                            <button type="submit" class="btn btn-primary w-100">
                                <i data-lucide="send" aria-hidden="true"></i> Send reservation
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="card-surface p-3 p-md-4 mt-3">
                    <div class="d-flex gap-3 align-items-start">
                        <span class="feature-icon cool mb-0" style="width:40px;height:40px;border-radius:11px"><i data-lucide="info" aria-hidden="true"></i></span>
                        <div>
                            <div class="feature-title">What happens next?</div>
                            <p class="feature-text mb-0">
                                Your request is logged with a <strong>pending</strong> status. Once DNS automation is enabled,
                                approved names move to <strong>active</strong> without any action from you.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-5">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h2 class="h5 mb-0">Your reservations</h2>
                <span class="pill pill-neutral"><?= e((string) count($reservations)) ?> total</span>
            </div>

            <div class="table-surface">
                <?php if ($reservations === []): ?>
                    <div class="empty-state border-0">
                        <div class="empty-icon"><i data-lucide="inbox" aria-hidden="true"></i></div>
                        <h3>Nothing reserved yet</h3>
                        <p>Requests you send appear here with their review status.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th scope="col">Requested name</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Requested</th>
                                    <th scope="col">Note</th>
                                    <th scope="col" class="text-end"><span class="visually-hidden">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($reservations as $reservation): ?>
                                <?php
                                $status = (string) $reservation['status'];
                                $statusClass = match ($status) {
                                    'approved' => 'is-active',
                                    'pending' => 'is-pending',
                                    'rejected', 'expired' => 'is-failed',
                                    default => 'is-neutral',
                                };
                                ?>
                                <tr>
                                    <td>
                                        <span class="fqdn mono"><?= e((string) $reservation['label']) ?>.<?= e((string) $reservation['root_domain']) ?></span>
                                        <?php if (!empty($reservation['purpose'])): ?>
                                            <div class="text-muted-2" style="font-size:.82rem"><?= e(str_limit((string) $reservation['purpose'], 60)) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge-status <?= e($statusClass) ?>"><?= e(ucfirst($status)) ?></span></td>
                                    <td class="text-muted-2"><?= e(format_date((string) $reservation['created_at'])) ?></td>
                                    <td class="text-muted-2"><?= e((string) ($reservation['admin_note'] ?? '—')) ?></td>
                                    <td class="text-end">
                                        <?php if ($status === 'pending'): ?>
                                            <form method="post" action="<?= e(app_url('/dashboard.php')) ?>" class="d-inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="cancel_reservation">
                                                <input type="hidden" name="id" value="<?= e((string) $reservation['id']) ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-secondary"
                                                        data-confirm="Cancel this reservation request?">
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
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
