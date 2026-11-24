<?php
/**
 * Admin: DNS diagnostics.
 *
 * Every value rendered here comes from the database or from the provider's
 * sanitised status array. No token, header or raw API body ever reaches this
 * template — there is deliberately no variable holding one.
 *
 * @var array<string, mixed> $authUser
 * @var array<string, mixed>|null $domain
 * @var list<array<string, mixed>> $domains
 * @var array<string, mixed> $status
 * @var list<array<string, mixed>> $records
 * @var list<array<string, mixed>> $history
 * @var list<array<string, mixed>> $recentErrors
 * @var array<string, mixed>|null $syncResult
 * @var array<string, string> $errors
 */
$zone = $status['zone'] ?? null;
$enabled = (bool) ($status['enabled'] ?? false);
$configured = (bool) ($status['configured'] ?? false);
?>
<section class="page-header">
    <div class="container">
        <div class="row align-items-end g-3">
            <div class="col-lg-8">
                <nav class="breadcrumb-mini" aria-label="Breadcrumb">
                    <a href="<?= e(app_url('/admin/index.php')) ?>">Admin</a>
                    <i data-lucide="chevron-right" aria-hidden="true" style="width:12px;height:12px"></i>
                    <span>DNS diagnostics</span>
                </nav>
                <h1>DNS diagnostics</h1>
                <p>Cloudflare connection state, verified zone and managed records. Read-only.</p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <span class="pill <?= $configured ? 'pill-success' : 'pill-warning' ?>">
                    <i data-lucide="<?= $configured ? 'check-circle-2' : 'triangle-alert' ?>" aria-hidden="true"></i>
                    <?= e((string) $status['provider']) ?><?= $configured ? ' (configured)' : ' (not configured)' ?>
                </span>
            </div>
        </div>
    </div>
</section>

<section class="section-tight">
    <div class="container">
        <?php partial('partials/flashes'); ?>

        <div class="alert alert-info" role="status">
            <i data-lucide="info" aria-hidden="true"></i>
            <div>
                <strong>This page never writes DNS.</strong>
                The connection test performs a single read-only zone lookup. Record creation stays behind the
                service layer and public registration is disabled in this build, so no subdomain is advertised as live.
            </div>
        </div>

        <div class="row g-3 g-lg-4 mb-4">
            <div class="col-lg-7">
                <div class="card h-100">
                    <div class="card-body">
                        <h2 class="h5 mb-3">Connection</h2>

                        <dl class="row mb-0 small">
                            <dt class="col-sm-4">Provider</dt>
                            <dd class="col-sm-8 mono"><?= e((string) $status['provider']) ?></dd>

                            <dt class="col-sm-4">Enabled</dt>
                            <dd class="col-sm-8">
                                <?= $enabled ? 'yes' : 'no — <span class="mono">DNS_PROVIDER</span> is not <span class="mono">cloudflare</span>' ?>
                            </dd>

                            <dt class="col-sm-4">API token</dt>
                            <dd class="col-sm-8">
                                <?php if ($status['token_present']): ?>
                                    <span class="pill pill-success">present (server-side)</span>
                                <?php else: ?>
                                    <span class="pill pill-warning">missing</span>
                                <?php endif; ?>
                            </dd>

                            <dt class="col-sm-4">Zone id</dt>
                            <dd class="col-sm-8 mono"><?= $status['zone_id'] !== '' ? e((string) $status['zone_id']) : '—' ?></dd>

                            <dt class="col-sm-4">Configured root</dt>
                            <dd class="col-sm-8 mono"><?= e((string) $status['root_domain']) ?></dd>

                            <dt class="col-sm-4">API base URL</dt>
                            <dd class="col-sm-8 mono small"><?= e((string) $status['api_base']) ?></dd>

                            <dt class="col-sm-4">Root domain row</dt>
                            <dd class="col-sm-8"><?= $domain !== null ? e((string) $domain['root_domain']) : 'not in database' ?></dd>
                        </dl>

                        <?php if (!empty($status['error'])): ?>
                            <p class="text-danger small mt-3 mb-0"><?= e((string) $status['error']) ?></p>
                        <?php endif; ?>

                        <hr>

                        <div class="d-flex flex-wrap gap-2">
                            <form method="post" action="<?= e(app_url('/admin/dns.php')) ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="test_connection">
                                <?php if ($domain !== null): ?>
                                    <input type="hidden" name="domain_id" value="<?= (int) $domain['id'] ?>">
                                <?php endif; ?>
                                <button class="btn btn-outline-primary btn-sm" type="submit" <?= $configured ? '' : 'disabled' ?>>
                                    <i data-lucide="plug-zap" aria-hidden="true"></i> Test connection (read-only)
                                </button>
                            </form>

                            <form method="post" action="<?= e(app_url('/admin/dns.php')) ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="sync">
                                <?php if ($domain !== null): ?>
                                    <input type="hidden" name="domain_id" value="<?= (int) $domain['id'] ?>">
                                <?php endif; ?>
                                <button class="btn btn-outline-secondary btn-sm" type="submit" <?= $configured ? '' : 'disabled' ?>>
                                    <i data-lucide="refresh-cw" aria-hidden="true"></i> Compare with zone
                                </button>
                            </form>
                        </div>

                        <p class="form-text mt-3 mb-0">
                            Comparing never deletes anything, in Cloudflare or in FreeSub. It lists missing, modified and
                            unmanaged records so an operator can decide.
                        </p>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card h-100">
                    <div class="card-body">
                        <h2 class="h5 mb-3">Verified zone</h2>

                        <?php if (is_array($zone)): ?>
                            <p class="mb-2">
                                <span class="pill <?= $zone['ok'] ? 'pill-success' : 'pill-danger' ?>">
                                    <?= e($zone['ok'] ? 'verified' : (string) $zone['code']) ?>
                                </span>
                            </p>
                            <dl class="row mb-0 small">
                                <dt class="col-5">Zone name</dt>
                                <dd class="col-7 mono"><?= e((string) ($zone['zone_name'] ?? '—')) ?></dd>
                                <dt class="col-5">Zone status</dt>
                                <dd class="col-7 mono"><?= e((string) ($zone['status'] ?? '—')) ?></dd>
                                <dt class="col-5">Nameservers</dt>
                                <dd class="col-7 mono small">
                                    <?= $zone['nameservers'] === [] ? '—' : e(implode(', ', $zone['nameservers'])) ?>
                                </dd>
                            </dl>
                            <p class="form-text mt-3 mb-0"><?= e((string) $zone['message']) ?></p>
                        <?php else: ?>
                            <p class="text-muted small mb-0">
                                Not checked in this request. Use <strong>Test connection</strong> to verify that the zone
                                exists, is active, and belongs to <span class="mono"><?= e((string) $status['root_domain']) ?></span>.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 g-lg-4 mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-label"><i data-lucide="list" aria-hidden="true"></i> Managed records</div>
                    <div class="stat-value"><?= (int) ($status['records']['total'] ?? 0) ?></div>
                    <div class="stat-hint"><?= (int) ($status['records']['active'] ?? 0) ?> active · <?= (int) ($status['records']['pending'] ?? 0) ?> pending</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-label"><i data-lucide="cloud-off" aria-hidden="true"></i> Failed records</div>
                    <div class="stat-value"><?= (int) ($status['records']['failed'] ?? 0) ?></div>
                    <div class="stat-hint">need operator attention</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-label"><i data-lucide="globe-2" aria-hidden="true"></i> Root domains</div>
                    <div class="stat-value"><?= count($domains) ?></div>
                    <div class="stat-hint">configured for Cloudflare</div>
                </div>
            </div>
        </div>

        <?php if (is_array($syncResult)): ?>
            <div class="card mb-4">
                <div class="card-body">
                    <h2 class="h5 mb-3">Comparison result</h2>
                    <p class="small mb-3"><?= e((string) $syncResult['message']) ?></p>

                    <?php foreach ([
                        'missing' => ['Missing from the zone', 'Recorded in FreeSub but not found in Cloudflare. Nothing was recreated.'],
                        'modified' => ['Changed outside FreeSub', 'The live record differs from the stored value. Not corrected automatically.'],
                        'unknown' => ['Unmanaged records (left untouched)', 'Present in the zone but not claimed by FreeSub. Never deleted.'],
                    ] as $key => [$title, $hint]): ?>
                        <?php $rows = $syncResult[$key] ?? []; ?>
                        <h3 class="h6 mt-3"><?= e($title) ?> <span class="text-muted">(<?= count($rows) ?>)</span></h3>
                        <p class="form-text mt-1"><?= e($hint) ?></p>

                        <?php if ($rows === []): ?>
                            <p class="small text-muted mb-0">None.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr><th>Name</th><th>Type</th><th><?= $key === 'unknown' ? '—' : 'Expected' ?></th><th>Live value</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_slice($rows, 0, 20) as $row): ?>
                                            <tr>
                                                <td class="mono small"><?= e((string) ($row['name'] ?? '')) ?></td>
                                                <td class="mono small"><?= e((string) ($row['type'] ?? '')) ?></td>
                                                <td class="mono small"><?= e($key === 'unknown' ? '—' : (string) ($row['expected'] ?? '—')) ?></td>
                                                <td class="mono small"><?= e((string) ($row['actual'] ?? ($row['content'] ?? '—'))) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="row g-3 g-lg-4">
            <div class="col-lg-7">
                <div class="card h-100">
                    <div class="card-body">
                        <h2 class="h5 mb-3">Records owned by FreeSub</h2>

                        <?php if ($records === []): ?>
                            <p class="text-muted small mb-0">No records. Nothing is provisioned in this build.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr><th>Name</th><th>Type</th><th>Value</th><th>Status</th><th>Last sync</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($records as $record): ?>
                                            <tr>
                                                <td class="mono small"><?= e((string) $record['name']) ?></td>
                                                <td class="mono small"><?= e((string) $record['type']) ?></td>
                                                <td class="mono small text-truncate" style="max-width:220px"><?= e((string) $record['content']) ?></td>
                                                <td>
                                                    <span class="pill <?= $record['status'] === 'active' ? 'pill-success' : ($record['status'] === 'failed' ? 'pill-danger' : 'pill-warning') ?>">
                                                        <?= e((string) $record['status']) ?>
                                                    </span>
                                                </td>
                                                <td class="small text-muted"><?= e((string) ($record['last_synced_at'] ?? 'never')) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card mb-3">
                    <div class="card-body">
                        <h2 class="h5 mb-3">Recent sync errors</h2>

                        <?php if ($recentErrors === []): ?>
                            <p class="text-muted small mb-0">None recorded.</p>
                        <?php else: ?>
                            <ul class="list-unstyled small mb-0">
                                <?php foreach ($recentErrors as $row): ?>
                                    <li class="mb-2">
                                        <span class="mono"><?= e((string) $row['type']) ?> <?= e((string) $row['name']) ?></span>
                                        <div class="text-danger"><?= e(mb_substr((string) $row['last_error'], 0, 160)) ?></div>
                                        <span class="text-muted"><?= e((string) $row['updated_at']) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <h2 class="h5 mb-3">Operation history</h2>

                        <?php if ($history === []): ?>
                            <p class="text-muted small mb-0">No DNS operations have been recorded yet.</p>
                        <?php else: ?>
                            <ul class="list-unstyled small mb-0">
                                <?php foreach ($history as $entry): ?>
                                    <li class="mb-2">
                                        <span class="pill pill-neutral"><?= e((string) $entry['actor_type']) ?></span>
                                        <span class="mono"><?= e((string) $entry['action']) ?></span>
                                        <div class="text-muted"><?= e((string) ($entry['description'] ?? '')) ?></div>
                                        <span class="text-muted"><?= e((string) $entry['created_at']) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
