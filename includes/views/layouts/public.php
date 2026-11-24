<?php
/**
 * Public site layout.
 *
 * @var string $content
 * @var string $pageTitle
 * @var string|null $activeNav
 * @var string|null $bodyClass
 * @var string|null $metaDescription
 * @var array<string, mixed>|null $authUser
 * @var bool $loadClerk  Load the Clerk browser integration on this page
 */
$loadClerk = $loadClerk ?? false;
$clerkJsVersion = (string) config('clerk.js_version', '6.46.3');
$authUser = $authUser ?? auth_user();
$pageTitle = $pageTitle ?? 'Free subdomains for your next idea';
$activeNav = $activeNav ?? null;
$bodyClass = $bodyClass ?? '';
$metaDescription = $metaDescription ?? 'FreeSub gives you a free, memorable subdomain on kharcha.info. Search availability, reserve your name and launch your project — no credit card, no hosting lock-in.';
$appName = (string) config('name', 'FreeSub');
?>
<!doctype html>
<html lang="en" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> · <?= e($appName) ?></title>
    <meta name="description" content="<?= e($metaDescription) ?>">
    <meta name="theme-color" content="#4f46e5">
    <meta name="robots" content="index,follow">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">

    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= e($pageTitle) ?> · <?= e($appName) ?>">
    <meta property="og:description" content="<?= e($metaDescription) ?>">
    <meta property="og:url" content="<?= e(app_url(current_path())) ?>">
    <meta name="twitter:card" content="summary_large_image">

    <link rel="icon" href="<?= e(app_url('/assets/images/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="preload" href="<?= e(asset('fonts/plus-jakarta-sans-latin-wght-normal.woff2')) ?>" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="<?= e($bodyClass) ?>">
    <a class="skip-link" href="#main">Skip to content</a>

    <?php partial('partials/navbar', ['authUser' => $authUser, 'activeNav' => $activeNav]); ?>

    <main id="main">
        <?= $content ?>
    </main>

    <?php partial('partials/footer'); ?>

    <script src="<?= e(asset('vendor/bootstrap.bundle.min.js')) ?>" defer></script>
    <script src="<?= e(asset('vendor/lucide.min.js')) ?>" defer></script>
    <script src="<?= e(asset('js/app.js')) ?>" defer></script>
    <?php if ($loadClerk): ?>
        <script src="<?= e(asset('js/clerk-auth.js')) ?>" data-clerk-auth-script
                data-clerk-js-version="<?= e($clerkJsVersion) ?>" defer></script>
    <?php endif; ?>
</body>
</html>
