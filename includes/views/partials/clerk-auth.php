<?php
/**
 * Clerk sign-in / sign-up surface.
 *
 * Clerk's own components (rendered into these containers by clerk-auth.js)
 * own every credential interaction: e-mail + password, Google OAuth, e-mail
 * verification codes, password reset and MFA. This view only provides:
 *
 *   * the visual frame (so it matches the rest of the site),
 *   * the mount points Clerk renders into,
 *   * a no-JavaScript fallback that explains what is required,
 *   * the CSRF-protected handoff form that finishes sign-in server-side.
 *
 * @var string            $mode            'sign-in' | 'sign-up'
 * @var bool              $clerkReady      publishable key + frontend host present
 * @var bool              $secretReady     server-side handoff possible
 * @var string            $channel
 * @var string            $state
 * @var string            $headline
 * @var string|null       $notice
 * @var array<string,string> $errors
 */
$mode = $mode ?? 'sign-in';
$clerkReady = $clerkReady ?? false;
$secretReady = $secretReady ?? false;
$channel = $channel ?? '';
$state = $state ?? '';
$headline = $headline ?? 'Welcome back';
$notice = $notice ?? null;
$errors = $errors ?? [];

$statusUrl = $mode === 'sign-up' ? '/register.php' : '/login.php';
?>
<div class="clerk-auth" data-clerk-auth data-mode="<?= e($mode) ?>">
    <?php if ($notice !== null && $notice !== ''): ?>
        <div class="alert alert-warning" role="alert">
            <i data-lucide="triangle-alert" aria-hidden="true"></i>
            <div><?= e($notice) ?></div>
        </div>
    <?php endif; ?>

    <?php foreach ($errors as $field => $message): ?>
        <?php if ($field === '_fieldless' || !is_string($message)) { continue; } ?>
        <div class="alert alert-danger" role="alert" data-clerk-error="<?= e((string) $field) ?>">
            <i data-lucide="circle-alert" aria-hidden="true"></i>
            <div><?= e($message) ?></div>
        </div>
    <?php endforeach; ?>

    <div class="alert alert-danger d-none" role="alert" data-clerk-error="live">
        <i data-lucide="circle-alert" aria-hidden="true"></i>
        <div data-clerk-error-message></div>
    </div>

    <?php if (!$clerkReady): ?>
        <div class="alert alert-warning" role="alert">
            <i data-lucide="plug-zap" aria-hidden="true"></i>
            <div>
                <strong>Authentication is not configured yet.</strong>
                An administrator needs to set <code>CLERK_PUBLISHABLE_KEY</code> in the environment.
            </div>
        </div>
    <?php elseif (!$secretReady): ?>
        <div class="alert alert-warning" role="alert">
            <i data-lucide="key-round" aria-hidden="true"></i>
            <div>
                <strong>Server-side sign-in is not configured yet.</strong>
                <code>CLERK_SECRET_KEY</code> is missing, so the final step of the handoff
                cannot run. The form below will report the problem instead of signing you in.
            </div>
        </div>
    <?php endif; ?>

    <div class="clerk-mount" data-clerk-sign-in <?= $mode === 'sign-in' ? '' : 'hidden' ?>></div>
    <div class="clerk-mount" data-clerk-sign-up <?= $mode === 'sign-up' ? '' : 'hidden' ?>></div>

    <div class="clerk-loading" data-clerk-loading>
        <span class="clerk-spinner" aria-hidden="true"></span>
        <span>Loading secure sign-in…</span>
    </div>

    <noscript>
        <div class="alert alert-warning" role="alert">
            <i data-lucide="shield-alert" aria-hidden="true"></i>
            <div>
                <strong>JavaScript is required to sign in.</strong>
                Authentication is handled by Clerk and needs JavaScript enabled in your
                browser. Turn it on and reload this page.
            </div>
        </div>
    </noscript>

    <div class="clerk-fallback d-none" data-clerk-fallback>
        <div class="alert alert-warning" role="alert">
            <i data-lucide="cloud-off" aria-hidden="true"></i>
            <div>
                <strong>Secure sign-in could not load.</strong>
                Check your connection and reload the page. If the problem continues,
                contact support.
            </div>
        </div>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-clerk-retry>
            Try again
        </button>
    </div>

    <?php if ($clerkReady): ?>
        <form method="post" action="<?= e(app_url('/auth/handoff.php')) ?>" class="clerk-handoff d-none" data-clerk-handoff novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="handoff_token" value="" data-clerk-token>
            <input type="hidden" name="welcome" value="<?= $mode === 'sign-up' ? '1' : '0' ?>">
            <button type="submit" class="btn btn-primary w-100 btn-lg" data-clerk-continue>
                Continue to FreeSub <i data-lucide="arrow-right" aria-hidden="true"></i>
            </button>
            <p class="text-center text-muted-2 mt-3 mb-0" style="font-size:.86rem">
                Verifying your session with FreeSub…
            </p>
        </form>
    <?php endif; ?>

    <p class="clerk-footnote">
        <i data-lucide="lock" aria-hidden="true"></i>
        Credentials, Google sign-in and password recovery are handled by
        <a href="https://clerk.com" rel="noopener noreferrer" target="_blank">Clerk</a>.
        FreeSub never sees your password.
    </p>
</div>

<script type="application/json" data-clerk-config>
<?= json_encode([
    'publishableKey' => (string) ($clerkPublishableKey ?? ''),
    'mode' => $mode,
    'channel' => $channel,
    'state' => $state,
    'bridgeUrl' => app_url('/clerk-bridge.php'),
    'afterSignInUrl' => app_url(Auth::check() ? '/dashboard.php' : ($_SESSION['_intended'] ?? '/dashboard.php')),
    'afterSignUpUrl' => app_url('/dashboard.php?welcome=1'),
    'handoffTemplate' => (string) config('clerk.jwt_template', 'freesub_handoff'),
    'statusUrl' => app_url($statusUrl),
    'signInUrl' => app_url('/login.php'),
    'signUpUrl' => app_url('/register.php'),
], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
</script>
