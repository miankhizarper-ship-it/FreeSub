<?php
/**
 * 419 — the CSRF token was missing, stale or forged.
 *
 * Rendered by csrf_check(); the status code is set before this view runs.
 *
 * @var string $pageTitle
 */
$pageTitle = $pageTitle ?? 'Session expired';
?>
<section class="auth-wrap">
    <div class="container">
        <div class="auth-card text-center">
            <div class="form-card">
                <div class="empty-icon mx-auto"><i data-lucide="clock-alert" aria-hidden="true"></i></div>
                <h1 class="h3 mb-2">Session expired</h1>
                <p class="text-muted-2 mb-4">
                    Your session expired, or the security token for this form was no longer valid.
                    Nothing was changed. Go back, refresh the page and submit again.
                </p>
                <div class="d-flex flex-wrap gap-2 justify-content-center">
                    <a class="btn btn-primary" href="<?= e(app_url('/dashboard.php')) ?>">Back to dashboard</a>
                    <a class="btn btn-outline-secondary" href="<?= e(app_url('/')) ?>">Home</a>
                </div>
            </div>
        </div>
    </div>
</section>
