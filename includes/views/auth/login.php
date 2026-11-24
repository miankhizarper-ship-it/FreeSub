<?php
/**
 * @var array<string, string> $errors
 * @var array<string, string> $values
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

                <?php if (isset($errors['email']) && !isset($errors['_fieldless'])): ?>
                    <div class="alert alert-danger" role="alert">
                        <i data-lucide="circle-alert" aria-hidden="true"></i>
                        <div><?= e($errors['email']) ?></div>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?= e(app_url('/login.php')) ?>" novalidate>
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label class="form-label" for="email">Email address</label>
                        <input type="email" class="form-control<?= isset($errors['email']) ? ' is-invalid' : '' ?>"
                               id="email" name="email" value="<?= e($values['email'] ?? '') ?>"
                               autocomplete="username" required maxlength="190" autofocus>
                        <?php if (isset($errors['email'])): ?>
                            <div class="invalid-feedback"><?= e($errors['email']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <label class="form-label mb-0" for="password">Password</label>
                            <span class="form-text">Forgot it? <a href="mailto:<?= e((string) config('contact.support', 'support@kharcha.info')) ?>">Contact support</a></span>
                        </div>
                        <div class="position-relative mt-2">
                            <input type="password" class="form-control<?= isset($errors['password']) ? ' is-invalid' : '' ?>"
                                   id="password" name="password" autocomplete="current-password" required>
                            <?php if (isset($errors['password'])): ?>
                                <div class="invalid-feedback"><?= e($errors['password']) ?></div>
                            <?php endif; ?>
                            <button type="button" class="password-toggle" data-password-toggle="password" aria-label="Show password">
                                <i data-lucide="eye"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 btn-lg mt-2">
                        Sign in <i data-lucide="arrow-right" aria-hidden="true"></i>
                    </button>
                </form>

                <p class="text-center mt-4 mb-0 text-muted-2" style="font-size:.92rem">
                    New here? <a href="<?= e(app_url('/register.php')) ?>" class="fw-semibold">Create a free account</a>
                </p>
            </div>

            <p class="text-center mt-3 mb-0 footer-small" style="color:var(--fs-muted)">
                Protected by CSRF tokens, rate limiting and hashed credentials.
            </p>
        </div>
    </div>
</section>
