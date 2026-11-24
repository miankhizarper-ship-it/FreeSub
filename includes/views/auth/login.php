<?php
/**
 * Sign in view. All credential handling happens inside Clerk's component.
 *
 * @var array<string, string> $errors
 * @var bool   $clerkReady
 * @var bool   $secretReady
 * @var string $channel
 * @var string $state
 * @var string $clerkPublishableKey
 * @var string|null $notice
 */
?>
<section class="auth-wrap">
    <div class="container">
        <div class="auth-card mx-auto">
            <div class="form-card">
                <div class="auth-head">
                    <span class="brand justify-content-center mb-3">
                        <span class="brand-mark" aria-hidden="true"><i data-lucide="globe-2"></i></span>
                    </span>
                    <h1>Welcome back</h1>
                    <p>Sign in to manage your subdomains and reservations.</p>
                </div>

                <?php partial('partials/flashes'); ?>

                <?php partial('partials/clerk-auth', [
                    'mode' => 'sign-in',
                    'clerkReady' => $clerkReady ?? false,
                    'secretReady' => $secretReady ?? false,
                    'channel' => $channel ?? '',
                    'state' => $state ?? '',
                    'headline' => 'Welcome back',
                    'notice' => $notice ?? null,
                    'errors' => $errors ?? [],
                    'clerkPublishableKey' => $clerkPublishableKey ?? '',
                ]); ?>

                <p class="text-center mt-4 mb-0 text-muted-2" style="font-size:.92rem">
                    New here? <a href="<?= e(app_url('/register.php')) ?>" class="fw-semibold">Create a free account</a>
                </p>
            </div>

            <p class="text-center mt-3 mb-0 footer-small" style="color:var(--fs-muted)">
                Sign-in is delegated to Clerk — passwords never touch our servers.
            </p>
        </div>
    </div>
</section>
