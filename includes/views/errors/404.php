<?php
$pageTitle = $pageTitle ?? 'Page not found';
?>
<section class="auth-wrap">
    <div class="container">
        <div class="auth-card text-center">
            <div class="form-card">
                <p class="eyebrow justify-content-center mb-2">Error 404</p>
                <h1 class="h3 mb-2">We could not find that page</h1>
                <p class="text-muted-2 mb-4">The link may be outdated, or the page moved somewhere else.</p>
                <div class="d-flex flex-wrap gap-2 justify-content-center">
                    <a class="btn btn-primary" href="<?= e(app_url('/')) ?>">Back to home</a>
                    <a class="btn btn-outline-secondary" href="<?= e(app_url('/extensions.php')) ?>">Browse extensions</a>
                </div>
            </div>
        </div>
    </div>
</section>
