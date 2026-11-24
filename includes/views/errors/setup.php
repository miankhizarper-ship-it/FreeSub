<?php
/**
 * Shown when the database is unreachable / not imported yet.
 *
 * @var string $message
 * @var string $pageTitle
 */
$debug = app_debug();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle ?? 'Setup required') ?> · <?= e((string) config('name', 'FreeSub')) ?></title>
    <meta name="robots" content="noindex">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="auth-wrap">
<main class="container">
    <div class="auth-card" style="max-width:640px">
        <div class="form-card">
            <div class="auth-head text-start">
                <span class="hero-badge mb-3"><span class="dot"></span> Setup required</span>
                <h1>FreeSub is almost ready</h1>
                <p class="text-start">
                    The application cannot reach its database yet. Import the schema, then reload this page.
                </p>
            </div>

            <ol class="mb-4 ps-3">
                <li>Copy <code>.env.example</code> to <code>.env</code> and fill in your MySQL credentials.</li>
                <li>Import the schema: <code>mysql -u root -p &lt; database/schema.sql</code></li>
                <li>Reload this page.</li>
            </ol>

            <?php if ($debug && !empty($message)): ?>
                <div class="alert alert-warning">
                    <i data-lucide="triangle-alert" aria-hidden="true"></i>
                    <div><strong>Debug detail</strong><br><span class="mono"><?= e($message) ?></span></div>
                </div>
            <?php endif; ?>

            <p class="footer-small mb-0">Full instructions live in <code>README.md</code>.</p>
        </div>
    </div>
</main>
<script src="<?= e(asset('vendor/lucide.min.js')) ?>" defer></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.lucide) { window.lucide.createIcons(); }
});
</script>
</body>
</html>
