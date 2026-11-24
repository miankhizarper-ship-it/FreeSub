<?php
/**
 * Admin layout: same identity, denser chrome.
 *
 * @var string $content
 * @var array<string, mixed> $authUser
 * @var string $pageTitle
 * @var string|null $activeNav
 */
$adminUser = $authUser ?? auth_user();
$activeNav = $activeNav ?? null;
$appName = (string) config('name', 'FreeSub');

$adminNav = [
    ['key' => 'admin', 'label' => 'Overview', 'href' => '/admin/index.php', 'icon' => 'gauge'],
    ['key' => 'admin-reservations', 'label' => 'Reservations', 'href' => '/admin/reservations.php', 'icon' => 'inbox'],
    ['key' => 'admin-domains', 'label' => 'Root domains', 'href' => '/admin/domains.php', 'icon' => 'globe-2'],
    ['key' => 'admin-users', 'label' => 'Users', 'href' => '/admin/users.php', 'icon' => 'users'],
    ['key' => 'admin-abuse', 'label' => 'Abuse reports', 'href' => '/admin/abuse.php', 'icon' => 'shield-alert'],
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle ?? 'Admin') ?> · <?= e($appName) ?></title>
    <meta name="robots" content="noindex,nofollow">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <link rel="icon" href="<?= e(app_url('/assets/images/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="preload" href="<?= e(asset('fonts/plus-jakarta-sans-latin-wght-normal.woff2')) ?>" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="app-shell">
    <a class="skip-link" href="#main">Skip to content</a>

    <header class="site-header">
        <div class="container">
            <nav class="navbar navbar-expand-lg site-navbar" aria-label="Admin">
                <a class="brand" href="<?= e(app_url('/admin/index.php')) ?>">
                    <span class="brand-mark" aria-hidden="true"><i data-lucide="shield"></i></span>
                    <span class="brand-text"><?= e($appName) ?> <span class="tag tag-brand ms-1">Admin</span></span>
                </a>

                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNav"
                        aria-controls="adminNav" aria-expanded="false" aria-label="Toggle navigation">
                    <i data-lucide="menu" aria-hidden="true"></i>
                </button>

                <div class="collapse navbar-collapse" id="adminNav">
                    <ul class="navbar-nav mx-auto gap-lg-1">
                        <?php foreach ($adminNav as $item): ?>
                            <li class="nav-item">
                                <a class="nav-link<?= $activeNav === $item['key'] ? ' active' : '' ?>" href="<?= e(app_url($item['href'])) ?>">
                                    <i data-lucide="<?= e($item['icon']) ?>" class="nav-link-icon" aria-hidden="true"></i>
                                    <?= e($item['label']) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <div class="navbar-actions d-flex flex-column flex-lg-row align-items-lg-center gap-2">
                        <a class="btn btn-ghost" href="<?= e(app_url('/dashboard.php')) ?>">
                            <i data-lucide="arrow-up-right" aria-hidden="true"></i> My dashboard
                        </a>
                        <form method="post" action="<?= e(app_url('/logout.php')) ?>" class="d-inline">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-outline-secondary w-100">
                                <i data-lucide="log-out" aria-hidden="true"></i> Sign out
                            </button>
                        </form>
                    </div>
                </div>
            </nav>
        </div>
    </header>

    <main id="main">
        <?= $content ?>
    </main>

    <script src="<?= e(asset('vendor/bootstrap.bundle.min.js')) ?>" defer></script>
    <script src="<?= e(asset('vendor/lucide.min.js')) ?>" defer></script>
    <script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
