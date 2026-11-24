<?php
/**
 * Responsive public navigation.
 *
 * @var array<string, mixed>|null $authUser
 * @var string|null $activeNav
 */
$authUser = $authUser ?? auth_user();
$activeNav = $activeNav ?? null;

$links = [
    ['key' => 'extensions', 'label' => 'Extensions', 'href' => '/extensions.php'],
    ['key' => 'how', 'label' => 'How it works', 'href' => '/#how-it-works'],
    ['key' => 'features', 'label' => 'Features', 'href' => '/#features'],
    ['key' => 'faq', 'label' => 'FAQ', 'href' => '/#faq'],
];
?>
<header class="site-header" data-site-header>
    <div class="container">
        <nav class="navbar navbar-expand-lg site-navbar" aria-label="Primary">
            <a class="navbar-brand brand" href="<?= e(app_url('/')) ?>" aria-label="<?= e((string) config('name', 'FreeSub')) ?> home">
                <span class="brand-mark" aria-hidden="true">
                    <i data-lucide="globe-2"></i>
                </span>
                <span class="brand-text"><?= e((string) config('name', 'FreeSub')) ?></span>
            </a>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#primaryNav"
                    aria-controls="primaryNav" aria-expanded="false" aria-label="Toggle navigation">
                <i data-lucide="menu" aria-hidden="true"></i>
            </button>

            <div class="collapse navbar-collapse" id="primaryNav">
                <ul class="navbar-nav mx-auto gap-lg-1">
                    <?php foreach ($links as $link): ?>
                        <li class="nav-item">
                            <a class="nav-link<?= $activeNav === $link['key'] ? ' active' : '' ?>" href="<?= e(app_url($link['href'])) ?>">
                                <?= e($link['label']) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= e(app_url('/index.php#search')) ?>">
                            <i data-lucide="search" class="nav-link-icon" aria-hidden="true"></i> Check a name
                        </a>
                    </li>
                </ul>

                <div class="navbar-actions d-flex flex-column flex-lg-row align-items-stretch align-items-lg-center gap-2">
                    <?php if ($authUser !== null): ?>
                        <a class="btn btn-ghost" href="<?= e(app_url('/dashboard.php')) ?>">
                            <i data-lucide="layout-dashboard" aria-hidden="true"></i> Dashboard
                        </a>
                        <?php if (($authUser['role'] ?? 'user') === 'admin'): ?>
                            <a class="btn btn-ghost" href="<?= e(app_url('/admin/index.php')) ?>">Admin</a>
                        <?php endif; ?>
                        <form method="post" action="<?= e(app_url('/logout.php')) ?>" class="d-inline">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-outline-secondary w-100">
                                <i data-lucide="log-out" aria-hidden="true"></i> Sign out
                            </button>
                        </form>
                    <?php else: ?>
                        <a class="btn btn-ghost" href="<?= e(app_url('/login.php')) ?>">Sign in</a>
                        <a class="btn btn-primary" href="<?= e(app_url('/register.php')) ?>">
                            Get started <i data-lucide="arrow-right" aria-hidden="true"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </nav>
    </div>
</header>
