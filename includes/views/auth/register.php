<?php
/**
 * Registration view. Clerk owns the form, verification e-mail and policy.
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
                        <span class="brand-mark" aria-hidden="true"><i data-lucide="user-plus"></i></span>
                    </span>
                    <h1>Create your free account</h1>
                    <p>One account, up to <strong><?= e((string) config('subdomains.default_quota', 5)) ?></strong> subdomains. No credit card.</p>
                </div>

                <?php partial('partials/flashes'); ?>

                <?php partial('partials/clerk-auth', [
                    'mode' => 'sign-up',
                    'clerkReady' => $clerkReady ?? false,
                    'secretReady' => $secretReady ?? false,
                    'channel' => $channel ?? '',
                    'state' => $state ?? '',
                    'headline' => 'Create your free account',
                    'notice' => $notice ?? null,
                    'errors' => $errors ?? [],
                    'clerkPublishableKey' => $clerkPublishableKey ?? '',
                ]); ?>

                <ul class="clerk-benefits">
                    <li><i data-lucide="check" aria-hidden="true"></i> Free forever, no card required</li>
                    <li><i data-lucide="check" aria-hidden="true"></i> E-mail verification handled by Clerk</li>
                    <li><i data-lucide="check" aria-hidden="true"></i> Sign in with Google in one click</li>
                </ul>

                <p class="text-center mt-4 mb-0 text-muted-2" style="font-size:.92rem">
                    Already registered? <a href="<?= e(app_url('/login.php')) ?>" class="fw-semibold">Sign in</a>
                </p>
            </div>

            <p class="text-center mt-3 mb-0 footer-small" style="color:var(--fs-muted)">
                By creating an account you agree to our acceptable-use policy.
            </p>
        </div>
    </div>
</section>
