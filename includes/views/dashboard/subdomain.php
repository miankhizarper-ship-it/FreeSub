<?php
/**
 * Subdomain detail (Task 05 §6, §7).
 *
 * Shows what the database knows and nothing else: no token, no provider id, no
 * raw API response, no operator error string. Timestamps are only rendered when
 * the row actually carries one (DashboardStatus::timeline()).
 *
 * @var array<string, mixed> $subdomain
 * @var array{label:string, tone:string, hint:string} $state
 * @var array<string, mixed>|null $job
 * @var list<array<string, mixed>> $records
 * @var array<string, mixed>|null $primaryRecord
 * @var array<string, mixed>|null $reservation
 * @var list<array{key:string, label:string, at:string|null, state:string, detail:string}> $timeline
 * @var bool $canRelease
 * @var string|null $releaseBlocked
 * @var string $status
 */
$id = (int) $subdomain['id'];
$fqdn = (string) $subdomain['fqdn'];
$isActive = $status === 'active';
$isFailed = $status === 'failed';
$jobStatus = $job !== null ? (string) $job['status'] : null;
?>
<p class="mb-3">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(app_url('/dashboard/subdomains.php')) ?>">
        <i data-lucide="arrow-left" aria-hidden="true"></i> All subdomains
    </a>
</p>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <?php partial('partials/status-badge', ['status' => $status, 'kind' => 'subdomain']); ?>
    <button type="button" class="btn btn-sm btn-ghost" data-copy="<?= e($fqdn) ?>" data-copy-status="copy-status">
        <i data-lucide="copy" aria-hidden="true"></i> Copy hostname
    </button>
    <span id="copy-status" class="text-muted-2" style="font-size:.8rem" role="status" aria-live="polite"></span>
</div>

<?php if ($state['hint'] !== ''): ?>
    <p class="text-muted-2" style="font-size:.9rem;max-width:70ch"><?= e($state['hint']) ?></p>
<?php endif; ?>

<?php if ($isFailed): ?>
    <div class="alert alert-danger" role="alert">
        <i data-lucide="circle-alert" aria-hidden="true"></i>
        <div>
            <strong>Provisioning did not complete.</strong>
            <?= e(DashboardStatus::failureMessage()) ?>
        </div>
    </div>
<?php elseif ($status === 'pending_dns'): ?>
    <div class="alert alert-info" role="status">
        <i data-lucide="clock" aria-hidden="true"></i>
        <div>
            <strong>Not live yet.</strong>
            This name is approved and waiting for its DNS record to be created and verified.
            Nothing is served on <span class="mono"><?= e($fqdn) ?></span> until then.
        </div>
    </div>
<?php elseif ($status === 'suspended'): ?>
    <div class="alert alert-warning" role="status">
        <i data-lucide="pause-circle" aria-hidden="true"></i>
        <div>
            <strong>This name is suspended.</strong>
            Its record is not being served. Contact support if you did not expect this.
        </div>
    </div>
<?php endif; ?>

<div class="detail-grid">
    <!-- ======================= Basic + DNS info ======================= -->
    <div class="d-grid gap-3">
        <section class="panel" aria-labelledby="basic-heading">
            <div class="panel-body">
                <div class="section-head">
                    <h2 id="basic-heading">Details</h2>
                </div>

                <dl class="detail-list">
                    <dt>Hostname</dt>
                    <dd class="mono"><?= e($fqdn) ?></dd>

                    <dt>Extension</dt>
                    <dd><?= e((string) ($subdomain['root_domain'] ?? '—')) ?></dd>

                    <dt>Status</dt>
                    <dd><?php partial('partials/status-badge', ['status' => $status, 'kind' => 'subdomain']); ?></dd>

                    <dt>Requested</dt>
                    <dd><?= e(format_date((string) ($subdomain['created_at'] ?? null), 'M j, Y H:i')) ?></dd>

                    <?php if (($subdomain['provisioned_at'] ?? null) !== null): ?>
                        <dt>Provisioned</dt>
                        <dd><?= e(format_date((string) $subdomain['provisioned_at'], 'M j, Y H:i')) ?></dd>
                    <?php endif; ?>

                    <?php if (($subdomain['expires_at'] ?? null) !== null): ?>
                        <dt>Renews / expires</dt>
                        <dd><?= e(format_date((string) $subdomain['expires_at'], 'M j, Y')) ?></dd>
                    <?php endif; ?>

                    <dt>Last updated</dt>
                    <dd><?= e(format_date((string) ($subdomain['updated_at'] ?? null), 'M j, Y H:i')) ?></dd>
                </dl>
            </div>
        </section>

        <section class="panel" aria-labelledby="dns-heading">
            <div class="panel-body">
                <div class="section-head">
                    <h2 id="dns-heading">DNS &amp; provisioning</h2>
                    <?php if ($job !== null): ?>
                        <?php partial('partials/status-badge', ['status' => (string) $job['status'], 'kind' => 'job']); ?>
                    <?php endif; ?>
                </div>

                <dl class="detail-list">
                    <dt>Verification</dt>
                    <dd>
                        <?php if ($isActive): ?>
                            Verified — read back from the zone
                        <?php elseif ($isFailed): ?>
                            Not verified
                        <?php elseif ($jobStatus === null): ?>
                            No provisioning job yet
                        <?php else: ?>
                            Waiting on verification
                        <?php endif; ?>
                    </dd>

                    <dt>Queue state</dt>
                    <dd>
                        <?php if ($job !== null): ?>
                            <?= e(DashboardStatus::job((string) $job['status'])['label']) ?>
                            <?php if ((int) $job['attempts'] > 0): ?>
                                <span class="text-muted-2">· <?= (int) $job['attempts'] ?> attempt<?= (int) $job['attempts'] === 1 ? '' : 's' ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted-2">Not queued</span>
                        <?php endif; ?>
                    </dd>

                    <?php if ($job !== null): ?>
                        <dt>Last attempt</dt>
                        <dd>
                            <?php if ((int) $job['attempts'] > 0): ?>
                                <?= e(format_date((string) ($job['updated_at'] ?? null), 'M j, Y H:i')) ?>
                            <?php else: ?>
                                <span class="text-muted-2">Not attempted yet</span>
                            <?php endif; ?>
                        </dd>
                    <?php endif; ?>

                    <?php if ($primaryRecord !== null): ?>
                        <dt>Record</dt>
                        <dd class="mono">
                            <?= e((string) $primaryRecord['type']) ?>
                            <?= e((string) $primaryRecord['name']) ?>
                            <?php if (($primaryRecord['content'] ?? '') !== ''): ?>
                                → <?= e((string) $primaryRecord['content']) ?>
                            <?php endif; ?>
                        </dd>

                        <dt>Record state</dt>
                        <dd>
                            <?= e(ucfirst((string) ($primaryRecord['status'] ?? 'pending'))) ?>
                            <?php if (($primaryRecord['last_synced_at'] ?? null) !== null): ?>
                                <span class="text-muted-2">· checked <?= e(format_date((string) $primaryRecord['last_synced_at'], 'M j, Y H:i')) ?></span>
                            <?php endif; ?>
                        </dd>
                    <?php endif; ?>
                </dl>

                <p class="stat-hint mb-0 mt-3">
                    Provisioning is done by FreeSub's queue against the DNS provider. It cannot be
                    triggered, sped up or edited from this page — that is what keeps a name from being
                    reported as live before the record actually exists.
                </p>
            </div>
        </section>
    </div>

    <!-- ========================== Timeline =========================== -->
    <div class="d-grid gap-3">
        <section class="panel" aria-labelledby="timeline-heading">
            <div class="panel-body">
                <div class="section-head">
                    <h2 id="timeline-heading">Timeline</h2>
                </div>

                <?php if ($timeline === []): ?>
                    <div class="empty-state is-compact">
                        <div class="empty-icon"><i data-lucide="history" aria-hidden="true"></i></div>
                        <h3>No events yet</h3>
                        <p>Steps appear here as they happen. Nothing is shown before it has been recorded.</p>
                    </div>
                <?php else: ?>
                    <ol class="timeline">
                        <?php foreach ($timeline as $step): ?>
                            <li class="is-<?= e($step['state']) ?>">
                                <div class="timeline-label">
                                    <?= e($step['label']) ?>
                                    <?php /* The state is text, not just the dot's colour (§19). */ ?>
                                    <span class="visually-hidden"> — <?= e($step['state'] === 'failed' ? 'failed' : 'done') ?></span>
                                </div>
                                <?php if ($step['at'] !== null): ?>
                                    <div class="timeline-time"><?= e($step['at']) ?></div>
                                <?php endif; ?>
                                <?php if ($step['detail'] !== ''): ?>
                                    <div class="timeline-detail"><?= e($step['detail']) ?></div>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($reservation !== null): ?>
            <section class="panel" aria-labelledby="reservation-heading">
                <div class="panel-body">
                    <div class="section-head">
                        <h2 id="reservation-heading">Linked reservation</h2>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(app_url('/dashboard/reservations.php')) ?>">History</a>
                    </div>

                    <dl class="detail-list">
                        <dt>Requested name</dt>
                        <dd class="mono"><?= e((string) $reservation['label'] . '.' . (string) ($reservation['root_domain'] ?? '')) ?></dd>

                        <dt>Outcome</dt>
                        <dd><?php partial('partials/status-badge', ['status' => (string) $reservation['status'], 'kind' => 'reservation']); ?></dd>

                        <dt>Requested</dt>
                        <dd><?= e(format_date((string) ($reservation['created_at'] ?? null), 'M j, Y H:i')) ?></dd>

                        <?php if (($reservation['reviewed_at'] ?? null) !== null): ?>
                            <dt>Reviewed</dt>
                            <dd><?= e(format_date((string) $reservation['reviewed_at'], 'M j, Y H:i')) ?></dd>
                        <?php endif; ?>
                    </dl>
                </div>
            </section>
        <?php endif; ?>

        <!-- ========================== Release ========================= -->
        <section class="danger-zone" aria-labelledby="release-heading">
            <h3 id="release-heading">Release this subdomain</h3>

            <?php if ($canRelease): ?>
                <p>
                    This removes <span class="mono"><?= e($fqdn) ?></span> from your account and frees one
                    slot of your quota. The label becomes available to everyone, including other people,
                    and you will need to request it again if you change your mind.
                    <?php if ($job !== null): ?>
                        Its pending provisioning job is cancelled first.
                    <?php endif; ?>
                </p>

                <form method="post" action="<?= e(app_url('/dashboard.php')) ?>" data-busy-form>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="release_subdomain">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <button type="submit" class="btn btn-danger" data-busy-button
                            data-confirm="Release <?= e($fqdn) ?>? This cannot be undone and the name becomes available to others.">
                        <i data-lucide="trash-2" aria-hidden="true"></i> Release subdomain
                    </button>
                </form>
            <?php else: ?>
                <p>
                    <?= e($releaseBlocked ?? 'This subdomain cannot be released right now.') ?>
                    Nothing is removed and no DNS record is touched unless that path runs.
                </p>
                <a class="btn btn-outline-secondary" href="<?= e(app_url('/abuse.php')) ?>">
                    <i data-lucide="life-buoy" aria-hidden="true"></i> Contact support
                </a>
            <?php endif; ?>
        </section>
    </div>
</div>

<div class="mt-3 text-muted-2" style="font-size:.8rem">
    Subdomain reference #<?= $id ?>
</div>
