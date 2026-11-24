<?php
/**
 * Authenticated dashboard shell (Task 05).
 *
 * Replaces the public layout for signed-in pages only. The public site keeps
 * `layouts/public`, the admin area keeps `layouts/admin`.
 *
 * Mobile strategy: the sidebar is an off-canvas panel driven by Bootstrap's
 * existing `offcanvas` component, so keyboard focus trapping, Escape handling
 * and the backdrop are Bootstrap's (already vendored) behaviour rather than a
 * hand-rolled imitation. On >=lg it is a fixed column, and the same markup
 * renders inline with no JavaScript at all.
 *
 * @var string $content
 * @var array<string, mixed>|null $authUser
 * @var string $pageTitle
 * @var string|null $activeNav
 * @var string|null $pageSubtitle
 * @var string|null $pageActions   Trusted markup for header buttons
 */
$authUser = $authUser ?? auth_user();
$appName = (string) config('name', 'FreeSub');
$activeNav = $activeNav ?? 'overview';
$pageTitle = $pageTitle ?? 'Dashboard';
$pageSubtitle = $pageSubtitle ?? null;
$pageActions = $pageActions ?? null;
$isAdmin = (($authUser['role'] ?? 'user') === 'admin');

$navItems = [
    ['key' => 'overview', 'label' => 'Overview', 'href' => '/dashboard.php', 'icon' => 'layout-dashboard'],
    ['key' => 'subdomains', 'label' => 'My subdomains', 'href' => '/dashboard/subdomains.php', 'icon' => 'globe-2'],
    ['key' => 'reservations', 'label' => 'Reservations', 'href' => '/dashboard/reservations.php', 'icon' => 'inbox'],
    ['key' => 'search', 'label' => 'Find a subdomain', 'href' => '/dashboard/search.php', 'icon' => 'search'],
    ['key' => 'settings', 'label' => 'Account', 'href' => '/dashboard/settings.php', 'icon' => 'user-round'],
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> · <?= e($appName) ?></title>
    <meta name="description" content="Manage your FreeSub subdomains, reservations and account.">
    <meta name="robots" content="noindex,nofollow">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <link rel="icon" href="<?= e(app_url('/assets/images/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="preload" href="<?= e(asset('fonts/plus-jakarta-sans-latin-wght-normal.woff2')) ?>" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="app-shell">
    <a class="skip-link" href="#main">Skip to content</a>

    <div class="dash-shell">
        <!-- ================= Desktop sidebar ================= -->
        <aside class="dash-sidebar d-none d-lg-flex" aria-label="Dashboard">
            <a class="dash-brand" href="<?= e(app_url('/')) ?>" aria-label="<?= e($appName) ?> home">
                <span class="brand-mark" aria-hidden="true"><i data-lucide="globe-2"></i></span>
                <span class="brand-text"><?= e($appName) ?></span>
            </a>

            <nav class="dash-nav" aria-label="Dashboard sections">
                <p class="dash-nav-label" id="nav-manage">Manage</p>
                <ul aria-labelledby="nav-manage">
                    <?php foreach ($navItems as $item): ?>
                        <li>
                            <a class="dash-nav-link<?= $activeNav === $item['key'] ? ' is-current' : '' ?>"
                               href="<?= e(app_url($item['href'])) ?>"
                               <?= $activeNav === $item['key'] ? 'aria-current="page"' : '' ?>>
                                <i data-lucide="<?= e($item['icon']) ?>" aria-hidden="true"></i>
                                <span><?= e($item['label']) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <?php if ($isAdmin): ?>
                    <p class="dash-nav-label" id="nav-staff">Staff</p>
                    <ul aria-labelledby="nav-staff">
                        <li>
                            <a class="dash-nav-link" href="<?= e(app_url('/admin/index.php')) ?>">
                                <i data-lucide="shield" aria-hidden="true"></i>
                                <span>Admin area</span>
                            </a>
                        </li>
                    </ul>
                <?php endif; ?>
            </nav>

            <div class="dash-sidebar-foot">
                <div class="dash-user">
                    <span class="dash-avatar" aria-hidden="true"><?= e(initials((string) $authUser['name'])) ?></span>
                    <span class="dash-user-text">
                        <span class="dash-user-name"><?= e((string) $authUser['name']) ?></span>
                        <span class="dash-user-email"><?= e((string) $authUser['email']) ?></span>
                    </span>
                </div>
                <form method="post" action="<?= e(app_url('/logout.php')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-outline-secondary btn-sm w-100">
                        <i data-lucide="log-out" aria-hidden="true"></i> Sign out
                    </button>
                </form>
            </div>
        </aside>

        <!-- ================= Mobile off-canvas ================= -->
        <div class="offcanvas offcanvas-start dash-offcanvas" tabindex="-1" id="dashNav" aria-labelledby="dashNavLabel">
            <div class="offcanvas-header">
                <h2 class="offcanvas-title h5 mb-0" id="dashNavLabel"><?= e($appName) ?></h2>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close navigation"></button>
            </div>
            <div class="offcanvas-body d-flex flex-column">
                <nav class="dash-nav" aria-label="Dashboard sections (mobile)">
                    <ul>
                        <?php foreach ($navItems as $item): ?>
                            <li>
                                <a class="dash-nav-link<?= $activeNav === $item['key'] ? ' is-current' : '' ?>"
                                   href="<?= e(app_url($item['href'])) ?>"
                                   <?= $activeNav === $item['key'] ? 'aria-current="page"' : '' ?>>
                                    <i data-lucide="<?= e($item['icon']) ?>" aria-hidden="true"></i>
                                    <span><?= e($item['label']) ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                        <?php if ($isAdmin): ?>
                            <li>
                                <a class="dash-nav-link" href="<?= e(app_url('/admin/index.php')) ?>">
                                    <i data-lucide="shield" aria-hidden="true"></i>
                                    <span>Admin area</span>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>

                <div class="mt-auto pt-3 border-top">
                    <div class="dash-user mb-2">
                        <span class="dash-avatar" aria-hidden="true"><?= e(initials((string) $authUser['name'])) ?></span>
                        <span class="dash-user-text">
                            <span class="dash-user-name"><?= e((string) $authUser['name']) ?></span>
                            <span class="dash-user-email"><?= e((string) $authUser['email']) ?></span>
                        </span>
                    </div>
                    <form method="post" action="<?= e(app_url('/logout.php')) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-outline-secondary w-100">
                            <i data-lucide="log-out" aria-hidden="true"></i> Sign out
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- ================= Main column ================= -->
        <div class="dash-main">
            <header class="dash-topbar">
                <button class="btn btn-icon d-lg-none" type="button" data-bs-toggle="offcanvas"
                        data-bs-target="#dashNav" aria-controls="dashNav" aria-label="Open navigation">
                    <i data-lucide="menu" aria-hidden="true"></i>
                </button>

                <a class="dash-brand d-lg-none" href="<?= e(app_url('/dashboard.php')) ?>">
                    <span class="brand-mark" aria-hidden="true"><i data-lucide="globe-2"></i></span>
                </a>

                <div class="dash-topbar-title d-none d-sm-block">
                    <span class="dash-topbar-eyebrow"><?= e($appName) ?></span>
                    <span class="dash-topbar-current"><?= e($pageTitle) ?></span>
                </div>

                <div class="dash-topbar-actions">
                    <a class="btn btn-sm btn-ghost" href="<?= e(app_url('/')) ?>">
                        <i data-lucide="external-link" aria-hidden="true"></i>
                        <span class="d-none d-md-inline">Public site</span>
                    </a>
                    <a class="btn btn-sm btn-primary" href="<?= e(app_url('/dashboard/search.php')) ?>">
                        <i data-lucide="plus" aria-hidden="true"></i>
                        <span class="d-none d-sm-inline">New subdomain</span>
                        <span class="d-sm-none">New</span>
                    </a>
                </div>
            </header>

            <main id="main" class="dash-content">
                <div class="dash-page-head">
                    <div>
                        <h1 class="dash-page-title"><?= e($pageTitle) ?></h1>
                        <?php if ($pageSubtitle !== null && $pageSubtitle !== ''): ?>
                            <p class="dash-page-subtitle"><?= e($pageSubtitle) ?></p>
                        <?php endif; ?>
                    </div>
                    <?php if ($pageActions !== null && $pageActions !== ''): ?>
                        <div class="dash-page-actions"><?= $pageActions ?></div>
                    <?php endif; ?>
                </div>

                <?php partial('partials/flashes'); ?>

                <?= $content ?>
            </main>

            <footer class="dash-footer">
                <span><?= e($appName) ?></span>
                <span class="dash-footer-links">
                    <a href="<?= e(app_url('/extensions.php')) ?>">Extensions</a>
                    <a href="<?= e(app_url('/abuse.php')) ?>">Report abuse</a>
                </span>
            </footer>
        </div>
    </div>

    <script src="<?= e(asset('vendor/bootstrap.bundle.min.js')) ?>" defer></script>
    <script src="<?= e(asset('vendor/lucide.min.js')) ?>" defer></script>
    <script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
