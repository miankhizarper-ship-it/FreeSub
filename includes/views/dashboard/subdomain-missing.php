<?php
/**
 * Shown when a subdomain id is unknown or belongs to someone else.
 *
 * Same page for both cases on purpose: the response must not reveal whether the
 * id exists on another account (Task 05 §10). The HTTP status is 404, set by the
 * controller.
 *
 * @var array<string, mixed> $authUser
 */
?>
<div class="empty-state">
    <div class="empty-icon"><i data-lucide="search-x" aria-hidden="true"></i></div>
    <h2>We could not find that subdomain</h2>
    <p>
        The link may be out of date, or the name may have been released. Nothing was changed.
    </p>
    <div class="d-flex flex-wrap gap-2 justify-content-center">
        <a class="btn btn-primary" href="<?= e(app_url('/dashboard/subdomains.php')) ?>">My subdomains</a>
        <a class="btn btn-outline-secondary" href="<?= e(app_url('/dashboard.php')) ?>">Back to overview</a>
    </div>
</div>
