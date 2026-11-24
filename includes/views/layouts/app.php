<?php
/**
 * Authenticated application layout.
 *
 * @var string $content
 * @var array<string, mixed>|null $authUser
 * @var string $pageTitle
 */
$authUser = $authUser ?? auth_user();
$appName = (string) config('name', 'FreeSub');
$bodyClass = $bodyClass ?? 'app-shell';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle ?? 'Dashboard') ?> · <?= e($appName) ?></title>
    <meta name="description" content="Manage your FreeSub subdomains, reservations and account.">
    <meta name="robots" content="noindex,nofollow">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <link rel="icon" href="<?= e(app_url('/assets/images/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="preload" href="<?= e(asset('fonts/plus-jakarta-sans-latin-wght-normal.woff2')) ?>" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="<?= e($bodyClass) ?>">
    <a class="skip-link" href="#main">Skip to content</a>

    <?php partial('partials/navbar', ['authUser' => $authUser, 'activeNav' => $activeNav ?? 'dashboard']); ?>

    <main id="main">
        <?= $content ?>
    </main>

    <?php partial('partials/footer'); ?>

    <script src="<?= e(asset('vendor/bootstrap.bundle.min.js')) ?>" defer></script>
    <script src="<?= e(asset('vendor/lucide.min.js')) ?>" defer></script>
    <script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
