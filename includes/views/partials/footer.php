<?php
$appName = (string) config('name', 'FreeSub');
$year = gmdate('Y');
$supportEmail = (string) config('contact.support', 'support@kharcha.info');
$abuseEmail = (string) config('contact.abuse', 'abuse@kharcha.info');
?>
<footer class="site-footer">
    <div class="container">
        <div class="row gy-4 gy-lg-5">
            <div class="col-lg-4">
                <a class="brand brand-footer" href="<?= e(app_url('/')) ?>">
                    <span class="brand-mark" aria-hidden="true"><i data-lucide="globe-2"></i></span>
                    <span class="brand-text"><?= e($appName) ?></span>
                </a>
                <p class="footer-tagline mt-3">
                    Free, memorable subdomains for side projects, portfolios and prototypes.
                    Built on real infrastructure, without the upsell.
                </p>
                <div class="footer-badges">
                    <span class="pill pill-soft"><i data-lucide="shield-check" aria-hidden="true"></i> No credit card</span>
                    <span class="pill pill-soft"><i data-lucide="zap" aria-hidden="true"></i> Instant search</span>
                </div>
            </div>

            <div class="col-6 col-lg-2">
                <h2 class="footer-heading">Product</h2>
                <ul class="footer-links">
                    <li><a href="<?= e(app_url('/extensions.php')) ?>">Extensions</a></li>
                    <li><a href="<?= e(app_url('/index.php#search')) ?>">Search a name</a></li>
                    <li><a href="<?= e(app_url('/index.php#how-it-works')) ?>">How it works</a></li>
                    <li><a href="<?= e(app_url('/index.php#features')) ?>">Features</a></li>
                </ul>
            </div>

            <div class="col-6 col-lg-2">
                <h2 class="footer-heading">Account</h2>
                <ul class="footer-links">
                    <li><a href="<?= e(app_url('/register.php')) ?>">Create account</a></li>
                    <li><a href="<?= e(app_url('/login.php')) ?>">Sign in</a></li>
                    <li><a href="<?= e(app_url('/dashboard.php')) ?>">Dashboard</a></li>
                    <li><a href="<?= e(app_url('/abuse.php')) ?>">Report abuse</a></li>
                </ul>
            </div>

            <div class="col-lg-4">
                <h2 class="footer-heading">Contact</h2>
                <p class="footer-small">Questions about a reservation or something suspicious on a hostname?</p>
                <ul class="footer-contact">
                    <li><i data-lucide="mail" aria-hidden="true"></i> <a href="mailto:<?= e($supportEmail) ?>"><?= e($supportEmail) ?></a></li>
                    <li><i data-lucide="shield-alert" aria-hidden="true"></i> <a href="mailto:<?= e($abuseEmail) ?>"><?= e($abuseEmail) ?></a></li>
                </ul>
            </div>
        </div>

        <div class="footer-bottom">
            <p class="mb-0">&copy; <?= e($year) ?> <?= e($appName) ?>. All rights reserved.</p>
            <p class="mb-0 footer-small">
                Subdomains are provided as-is. We never host customer files, email or databases.
            </p>
        </div>
    </div>
</footer>
