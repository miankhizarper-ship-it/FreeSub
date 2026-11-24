<?php
/**
 * @var array<string, string> $errors
 * @var array<string, string> $values
 */
?>
<section class="auth-wrap">
    <div class="container">
        <div class="auth-card mx-auto" style="max-width:520px">
            <div class="form-card">
                <div class="auth-head">
                    <span class="brand justify-content-center mb-3">
                        <span class="brand-mark" aria-hidden="true"><i data-lucide="globe-2"></i></span>
                    </span>
                    <h1>Create your free account</h1>
                    <p>One minute to set up. No credit card, ever.</p>
                </div>

                <?php partial('partials/flashes'); ?>

                <form method="post" action="<?= e(app_url('/register.php')) ?>" novalidate>
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label class="form-label" for="name">Your name</label>
                        <input type="text" class="form-control<?= isset($errors['name']) ? ' is-invalid' : '' ?>"
                               id="name" name="name" value="<?= e($values['name'] ?? '') ?>"
                               autocomplete="name" required maxlength="100" autofocus>
                        <?php if (isset($errors['name'])): ?>
                            <div class="invalid-feedback"><?= e($errors['name']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="email">Email address</label>
                        <input type="email" class="form-control<?= isset($errors['email']) ? ' is-invalid' : '' ?>"
                               id="email" name="email" value="<?= e($values['email'] ?? '') ?>"
                               autocomplete="email" required maxlength="190">
                        <?php if (isset($errors['email'])): ?>
                            <div class="invalid-feedback"><?= e($errors['email']) ?></div>
                        <?php else: ?>
                            <div class="form-text">We only use this for account and reservation updates.</div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="password">Password</label>
                        <div class="position-relative">
                            <input type="password" class="form-control<?= isset($errors['password']) ? ' is-invalid' : '' ?>"
                                   id="password" name="password" autocomplete="new-password" required
                                   minlength="10" data-password-strength aria-describedby="password-hint">
                            <button type="button" class="password-toggle" data-password-toggle="password" aria-label="Show password">
                                <i data-lucide="eye"></i>
                            </button>
                            <?php if (isset($errors['password'])): ?>
                                <div class="invalid-feedback"><?= e($errors['password']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="password-meter" data-password-meter="password" data-score="0" aria-hidden="true">
                            <span></span><span></span><span></span><span></span>
                        </div>
                        <div class="form-text" id="password-hint" data-password-hint="password">
                            At least 10 characters, with a letter and a number.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="password_confirmation">Confirm password</label>
                        <div class="position-relative">
                            <input type="password" class="form-control<?= isset($errors['password_confirmation']) ? ' is-invalid' : '' ?>"
                                   id="password_confirmation" name="password_confirmation"
                                   autocomplete="new-password" required minlength="10">
                            <button type="button" class="password-toggle" data-password-toggle="password_confirmation" aria-label="Show password">
                                <i data-lucide="eye"></i>
                            </button>
                            <?php if (isset($errors['password_confirmation'])): ?>
                                <div class="invalid-feedback"><?= e($errors['password_confirmation']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 btn-lg mt-2">
                        Create account <i data-lucide="arrow-right" aria-hidden="true"></i>
                    </button>

                    <p class="form-text text-center mt-3 mb-0">
                        By creating an account you agree to use FreeSub responsibly and to our
                        <a href="<?= e(app_url('/abuse.php')) ?>">acceptable use policy</a>.
                    </p>
                </form>

                <p class="text-center mt-4 mb-0 text-muted-2" style="font-size:.92rem">
                    Already have an account? <a href="<?= e(app_url('/login.php')) ?>" class="fw-semibold">Sign in</a>
                </p>
            </div>
        </div>
    </div>
</section>
