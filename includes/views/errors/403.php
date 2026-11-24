<?php
$pageTitle = $pageTitle ?? 'Access denied';
?>
<section class="auth-wrap">
    <div class="container">
        <div class="auth-card text-center">
            <div class="form-card">
                <div class="empty-icon mx-auto"><i data-lucide="shield-x" aria-hidden="true"></i></div>
                <h1 class="h3 mb-2">Access denied</h1>
                <p class="text-muted-2 mb-4">Your account does not have permission to view this page.</p>
                <a class="btn btn-primary" href="<?= e(app_url('/dashboard.php')) ?>">Back to dashboard</a>
            </div>
        </div>
    </div>
</section>
