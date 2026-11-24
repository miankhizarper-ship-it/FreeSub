<?php
/**
 * @var list<array<string, mixed>> $users
 * @var string $search
 * @var array<string, mixed> $authUser
 */
?>
<section class="page-header">
    <div class="container">
        <nav class="breadcrumb-mini" aria-label="Breadcrumb">
            <a href="<?= e(app_url('/admin/index.php')) ?>">Admin</a>
            <i data-lucide="chevron-right" aria-hidden="true" style="width:12px;height:12px"></i>
            <span>Users</span>
        </nav>
        <div class="row align-items-end g-3">
            <div class="col-lg-7">
                <h1>Users</h1>
                <p>Search accounts and manage access.</p>
            </div>
            <div class="col-lg-5">
                <form method="get" action="<?= e(app_url('/admin/users.php')) ?>" class="d-flex gap-2">
                    <label class="visually-hidden" for="q">Search users</label>
                    <input type="search" class="form-control" id="q" name="q" value="<?= e($search) ?>" placeholder="Name or email">
                    <button class="btn btn-primary" type="submit">Search</button>
                </form>
            </div>
        </div>
    </div>
</section>

<section class="section-tight">
    <div class="container">
        <?php partial('partials/flashes'); ?>

        <div class="table-surface">
            <?php if ($users === []): ?>
                <div class="empty-state border-0">
                    <div class="empty-icon"><i data-lucide="users" aria-hidden="true"></i></div>
                    <h2>No matching users</h2>
                    <p>Try a different search term.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th scope="col">User</th>
                                <th scope="col">Role</th>
                                <th scope="col">Status</th>
                                <th scope="col">Joined</th>
                                <th scope="col">Last login</th>
                                <th scope="col" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td>
                                    <div class="fqdn"><?= e((string) $user['name']) ?></div>
                                    <div class="text-muted-2" style="font-size:.8rem"><?= e((string) $user['email']) ?></div>
                                </td>
                                <td><span class="tag <?= ($user['role'] ?? '') === 'admin' ? 'tag-brand' : '' ?>"><?= e(ucfirst((string) $user['role'])) ?></span></td>
                                <td>
                                    <span class="badge-status <?= ($user['status'] ?? '') === 'active' ? 'is-active' : 'is-failed' ?>">
                                        <?= e(ucfirst((string) $user['status'])) ?>
                                    </span>
                                </td>
                                <td class="text-muted-2"><?= e(format_date((string) $user['created_at'])) ?></td>
                                <td class="text-muted-2"><?= e(format_date((string) ($user['last_login_at'] ?? null), 'M j, Y H:i')) ?></td>
                                <td class="text-end">
                                    <?php if ((int) $user['id'] === (int) $authUser['id']): ?>
                                        <span class="text-muted-2" style="font-size:.82rem">You</span>
                                    <?php else: ?>
                                        <form method="post" action="<?= e(app_url('/admin/users.php')) ?>" class="d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= e((string) $user['id']) ?>">
                                            <input type="hidden" name="action" value="<?= ($user['status'] ?? '') === 'active' ? 'suspend' : 'activate' ?>">
                                            <button class="btn btn-sm btn-outline-secondary" type="submit"
                                                    data-confirm="Change the account status for <?= e((string) $user['email']) ?>?">
                                                <?= ($user['status'] ?? '') === 'active' ? 'Suspend' : 'Activate' ?>
                                            </button>
                                        </form>
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
