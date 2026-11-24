<?php
/**
 * @var list<array<string, mixed>> $domains
 * @var array<string, string> $errors
 * @var array<string, string> $values
 */
$statusOptions = ['draft' => 'Draft', 'ready' => 'Ready', 'maintenance' => 'Maintenance', 'retired' => 'Retired'];
?>
<section class="page-header">
    <div class="container">
        <nav class="breadcrumb-mini" aria-label="Breadcrumb">
            <a href="<?= e(app_url('/admin/index.php')) ?>">Admin</a>
            <i data-lucide="chevron-right" aria-hidden="true" style="width:12px;height:12px"></i>
            <span>Root domains</span>
        </nav>
        <h1>Root domains</h1>
        <p>Each row is an extension users can choose. Adding one requires no code change.</p>
    </div>
</section>

<section class="section-tight">
    <div class="container">
        <?php partial('partials/flashes'); ?>

        <div class="row g-4">
            <div class="col-lg-8">
                <div class="table-surface">
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th scope="col">Root domain</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Quota</th>
                                    <th scope="col">Visibility</th>
                                    <th scope="col" class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($domains as $domain): ?>
                                <tr>
                                    <td>
                                        <div class="fqdn mono"><?= e((string) $domain['root_domain']) ?></div>
                                        <div class="text-muted-2" style="font-size:.8rem">.<?= e((string) ($domain['extension'] ?? '')) ?> · <?= e((string) ($domain['dns_provider'] ?? 'none')) ?></div>
                                    </td>
                                    <td>
                                        <form method="post" action="<?= e(app_url('/admin/domains.php')) ?>" class="d-flex gap-1">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="set_status">
                                            <input type="hidden" name="id" value="<?= e((string) $domain['id']) ?>">
                                            <label class="visually-hidden" for="status-<?= e((string) $domain['id']) ?>">Status</label>
                                            <select class="form-select form-select-sm" id="status-<?= e((string) $domain['id']) ?>" name="status" style="min-width:130px">
                                                <?php foreach ($statusOptions as $key => $label): ?>
                                                    <option value="<?= e($key) ?>" <?= ($domain['registration_status'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button class="btn btn-sm btn-outline-secondary" type="submit">Save</button>
                                        </form>
                                    </td>
                                    <td><?= e((string) ($domain['subdomain_quota'] ?? 0)) ?></td>
                                    <td>
                                        <?php if ((int) $domain['is_active'] === 1 && (int) $domain['is_public'] === 1): ?>
                                            <span class="badge-status is-active">Public</span>
                                        <?php elseif ((int) $domain['is_active'] === 1): ?>
                                            <span class="badge-status is-pending">Active, hidden</span>
                                        <?php else: ?>
                                            <span class="badge-status is-neutral">Disabled</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <form method="post" action="<?= e(app_url('/admin/domains.php')) ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="toggle_domain">
                                            <input type="hidden" name="id" value="<?= e((string) $domain['id']) ?>">
                                            <button class="btn btn-sm btn-outline-secondary" type="submit"
                                                    data-confirm="Change availability for <?= e((string) $domain['root_domain']) ?>?">
                                                <?= (int) $domain['is_active'] === 1 ? 'Disable' : 'Enable' ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="alert alert-info mt-3" role="status">
                    <i data-lucide="info" aria-hidden="true"></i>
                    <div>New domains are created in <strong>draft</strong> status so the public search never offers a hostname before its zone is ready.</div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="form-card">
                    <h2 class="h5 mb-1">Add a root domain</h2>
                    <p class="form-text mb-3">The apex domain must already be owned by the platform.</p>

                    <form method="post" action="<?= e(app_url('/admin/domains.php')) ?>" novalidate>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="create_domain">

                        <div class="mb-3">
                            <label class="form-label" for="root_domain">Apex domain</label>
                            <input type="text" class="form-control<?= isset($errors['root_domain']) ? ' is-invalid' : '' ?>"
                                   id="root_domain" name="root_domain" placeholder="example.com"
                                   value="<?= e($values['root_domain'] ?? '') ?>" required maxlength="190">
                            <?php if (isset($errors['root_domain'])): ?>
                                <div class="invalid-feedback"><?= e($errors['root_domain']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="label">Display label</label>
                            <input type="text" class="form-control" id="label" name="label"
                                   value="<?= e($values['label'] ?? '') ?>" maxlength="100" placeholder="Optional">
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="description">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3"
                                      maxlength="255" placeholder="Shown on the extensions page"><?= e($values['description'] ?? '') ?></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="subdomain_quota">Subdomains per account</label>
                            <input type="number" class="form-control" id="subdomain_quota" name="subdomain_quota"
                                   value="<?= e((string) (old('subdomain_quota') !== '' ? old('subdomain_quota') : config('subdomains.default_quota', 5))) ?>"
                                   min="1" max="500" required>
                        </div>

                        <button class="btn btn-primary w-100" type="submit">
                            <i data-lucide="plus" aria-hidden="true"></i> Create draft domain
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>
