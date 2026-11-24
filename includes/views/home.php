<?php
/**
 * Landing page.
 *
 * @var list<array<string, mixed>> $domains
 * @var array{domains: int, subdomains: int, users: int} $stats
 */
$primaryDomain = $domains[0] ?? null;
$primaryExtension = $primaryDomain !== null ? '.' . $primaryDomain['extension'] : '.example';
$reserveUrl = '/dashboard.php#reserve';

$features = [
    ['icon' => 'search-check', 'title' => 'Real-time availability', 'text' => 'Type a name and we check it against live reservations in milliseconds — no page reloads, no guesswork.', 'tone' => ''],
    ['icon' => 'shield-check', 'title' => 'Secure by default', 'text' => 'Hashed passwords, CSRF protection on every form, strict sessions and prepared statements throughout.', 'tone' => 'accent'],
    ['icon' => 'layers', 'title' => 'Multiple root domains', 'text' => 'Extensions are database rows, not hard-coded strings. New root domains go live with a single INSERT.', 'tone' => 'cool'],
    ['icon' => 'scroll-text', 'title' => 'Transparent records', 'text' => 'Every reservation and admin action is written to an append-only audit log you can ask us about.', 'tone' => 'warm'],
    ['icon' => 'sparkles', 'title' => 'Honest by design', 'text' => 'We never fake a successful registration. Names stay in "pending DNS" until the record really exists.', 'tone' => 'accent'],
    ['icon' => 'gauge', 'title' => 'Fast, lean pages', 'text' => 'Server-rendered PHP, a single small stylesheet and vanilla JavaScript. No heavyweight bundles to download.', 'tone' => 'cool'],
];

$steps = [
    ['title' => 'Search your name', 'text' => 'Pick an extension and check availability as you type. We also suggest close alternatives when a name is taken.'],
    ['title' => 'Create a free account', 'text' => 'One email, one strong password. No card, no phone number, no hosting upsell at the checkout.'],
    ['title' => 'Reserve and track', 'text' => 'Your request appears on your dashboard with a clear status. DNS provisioning is manual while we finish the automation.'],
];

$faqs = [
    [
        'q' => 'Is it really free?',
        'a' => '<p>Yes. FreeSub issues subdomains on root domains we own. There is no paid tier, no trial timer and no credit card step. We do not sell hosting, email or databases, so there is nothing to upsell.</p>',
    ],
    [
        'q' => 'How many subdomains can I create?',
        'a' => '<p>Each root domain defines its own quota (five by default, per account). Administrators can raise it per domain, and your dashboard always shows how many you have used.</p>',
    ],
    [
        'q' => 'When does my subdomain start working?',
        'a' => '<p>DNS automation is being finished. Until then every new name is stored as <strong>pending DNS</strong> and our team provisions the record by hand. We never show a name as live before the record actually exists.</p>',
    ],
    [
        'q' => 'Which names are not allowed?',
        'a' => '<p>Reserved infrastructure labels (like <span class="mono">www</span>, <span class="mono">mail</span> or <span class="mono">admin</span>), all-numeric names, punycode and anything that infringes a trademark. Labels must be lowercase letters, digits and single hyphens.</p>',
    ],
    [
        'q' => 'Can I add my own domain?',
        'a' => '<p>Not yet. Root domains must be owned and DNS-managed by the platform. The database is designed for many root domains, so new extensions appear in the search box automatically once they are added.</p>',
    ],
    [
        'q' => 'How do I report abuse?',
        'a' => '<p>Use the <a href="' . e(app_url('/abuse.php')) . '">abuse form</a> or email <a href="mailto:' . e((string) config('contact.abuse', 'abuse@kharcha.info')) . '">' . e((string) config('contact.abuse', 'abuse@kharcha.info')) . '</a>. Reports are logged against the hostname and reviewed by a human.</p>',
    ],
];
?>
<!-- ============================== HERO ============================== -->
<section class="hero">
    <div class="container">
        <div class="hero-grid">
            <div>
                <span class="hero-badge">
                    <span class="dot" aria-hidden="true"></span>
                    <strong><?= e((string) count($domains)) ?></strong> extension<?= count($domains) === 1 ? '' : 's' ?> live &middot; free forever
                </span>

                <h1>
                    Your idea deserves a <span class="highlight">real name</span>, not a slug.
                </h1>

                <p class="hero-lead">
                    FreeSub gives you a free subdomain on <strong><?= e($primaryDomain['root_domain'] ?? 'kharcha.info') ?></strong>.
                    Search a name, claim it in seconds, and point it anywhere — no credit card, no hosting lock-in.
                </p>

                <ul class="hero-meta">
                    <li><i data-lucide="check" aria-hidden="true"></i> Instant availability</li>
                    <li><i data-lucide="check" aria-hidden="true"></i> No ads on your site</li>
                    <li><i data-lucide="check" aria-hidden="true"></i> Free forever</li>
                </ul>

                <div class="d-flex flex-wrap gap-2 mt-4">
                    <a class="btn btn-primary btn-lg" href="#search">
                        Check a name <i data-lucide="arrow-down-right" aria-hidden="true"></i>
                    </a>
                    <a class="btn btn-light-surface btn-lg" href="<?= e(app_url('/register.php')) ?>">
                        Create free account
                    </a>
                </div>
            </div>

            <div class="hero-visual">
                <div class="search-card" id="search">
                    <h2>Find your subdomain</h2>
                    <p class="card-sub">Available names update as you type.</p>

                    <form data-subdomain-search data-endpoint="<?= e(app_url('/api/check.php')) ?>" novalidate>
                        <label class="visually-hidden" for="subdomain-label">Subdomain name</label>
                        <div class="search-input-group">
                            <span class="input-icon" aria-hidden="true"><i data-lucide="search"></i></span>
                            <input type="text" id="subdomain-label" name="label" placeholder="yourname"
                                   autocomplete="off" spellcheck="false" maxlength="32"
                                   inputmode="latin" aria-describedby="subdomain-help">
                            <?php if (count($domains) > 0): ?>
                                <label class="visually-hidden" for="domain-select">Extension</label>
                                <select class="extension-select" id="domain-select" name="domain_id">
                                    <?php foreach ($domains as $domain): ?>
                                        <option value="<?= e((string) $domain['id']) ?>">.<?= e((string) $domain['extension']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                            <button class="btn btn-primary" type="submit">
                                Check <i data-lucide="arrow-right" aria-hidden="true"></i>
                            </button>
                        </div>
                        <p class="search-hint" id="subdomain-help" data-search-status>
                            <i data-lucide="info" aria-hidden="true"></i>
                            Lowercase letters, numbers and hyphens. 3–32 characters.
                        </p>
                    </form>

                    <div class="search-results" data-search-results aria-live="polite"></div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ============================== TRUST BAR ============================== -->
<section class="trust-bar">
    <div class="container">
        <div class="trust-bar-inner">
            <span class="trust-label">Built for people who ship</span>
            <div class="trust-items">
                <span class="trust-item"><i data-lucide="code-2" aria-hidden="true"></i> Developers</span>
                <span class="trust-item"><i data-lucide="palette" aria-hidden="true"></i> Designers</span>
                <span class="trust-item"><i data-lucide="graduation-cap" aria-hidden="true"></i> Students</span>
                <span class="trust-item"><i data-lucide="rocket" aria-hidden="true"></i> Indie makers</span>
                <span class="trust-item"><i data-lucide="users" aria-hidden="true"></i> Communities</span>
            </div>
        </div>
    </div>
</section>

<!-- ============================== EXTENSIONS ============================== -->
<section class="section" id="extensions">
    <div class="container">
        <div class="row align-items-end g-4 mb-4 mb-lg-5">
            <div class="col-lg-7">
                <span class="eyebrow"><i data-lucide="globe-2" aria-hidden="true"></i> Available extensions</span>
                <h2 class="section-title mt-2">Pick the ending that fits your project.</h2>
                <p class="section-lead mb-0">
                    Every extension below is a root domain we own and operate. More are added over time — your account works across all of them.
                </p>
            </div>
            <div class="col-lg-5 text-lg-end">
                <a class="btn btn-outline-secondary" href="<?= e(app_url('/extensions.php')) ?>">
                    Browse all extensions <i data-lucide="arrow-up-right" aria-hidden="true"></i>
                </a>
            </div>
        </div>

        <div class="row g-3 g-lg-4">
            <?php if ($domains === []): ?>
                <div class="col-12">
                    <div class="empty-state">
                        <div class="empty-icon"><i data-lucide="globe-lock" aria-hidden="true"></i></div>
                        <h3>No extensions are open right now</h3>
                        <p>We are preparing the next root domain. Check back shortly or create an account to be ready.</p>
                        <a class="btn btn-primary" href="<?= e(app_url('/register.php')) ?>">Create an account</a>
                    </div>
                </div>
            <?php endif; ?>

            <?php foreach ($domains as $index => $domain): ?>
                <div class="col-md-6 col-lg-4<?= $index === 0 ? '' : '' ?>">
                    <article class="extension-card">
                        <div class="d-flex align-items-start justify-content-between gap-2">
                            <div class="extension-suffix">.<span><?= e((string) $domain['extension']) ?></span></div>
                            <?php if ($index === 0): ?>
                                <span class="tag tag-brand">Most popular</span>
                            <?php else: ?>
                                <span class="tag tag-accent">Available</span>
                            <?php endif; ?>
                        </div>
                        <p class="extension-desc"><?= e((string) ($domain['description'] ?? 'A short, memorable ending for your next project.')) ?></p>
                        <div class="extension-tags">
                            <span class="tag"><?= e((string) $domain['root_domain']) ?></span>
                            <span class="tag">No credit card</span>
                            <span class="tag"><?= e((string) $domain['subdomain_quota']) ?> per account</span>
                        </div>
                        <div class="extension-footer">
                            <span class="extension-count">Free forever</span>
                            <a class="btn btn-primary btn-sm" href="<?= e(app_url('/index.php#search')) ?>">
                                Claim <i data-lucide="arrow-right" aria-hidden="true"></i>
                            </a>
                        </div>
                    </article>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ============================== HOW IT WORKS ============================== -->
<section class="section section-muted" id="how-it-works">
    <div class="container">
        <div class="row g-4 mb-4 mb-lg-5">
            <div class="col-lg-7">
                <span class="eyebrow"><i data-lucide="route" aria-hidden="true"></i> How it works</span>
                <h2 class="section-title mt-2">Three steps, about a minute.</h2>
                <p class="section-lead mb-0">No sales call, no configuration maze. Create the name, then point it wherever your project already lives.</p>
            </div>
        </div>

        <div class="steps">
            <?php foreach ($steps as $step): ?>
                <article class="step">
                    <h3><?= e($step['title']) ?></h3>
                    <p><?= e($step['text']) ?></p>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="row g-3 mt-4">
            <div class="col-md-6">
                <div class="card-surface p-3 p-md-4 d-flex gap-3 align-items-start">
                    <span class="feature-icon mb-0" style="width:40px;height:40px;border-radius:11px"><i data-lucide="info" aria-hidden="true"></i></span>
                    <div>
                        <div class="feature-title">Honest status, always</div>
                        <p class="feature-text">Until the DNS record exists, a name is shown as <strong>pending DNS</strong>. We never simulate a successful registration.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card-surface p-3 p-md-4 d-flex gap-3 align-items-start">
                    <span class="feature-icon accent mb-0" style="width:40px;height:40px;border-radius:11px"><i data-lucide="shield" aria-hidden="true"></i></span>
                    <div>
                        <div class="feature-title">You keep control</div>
                        <p class="feature-text">Cancel a reservation any time from your dashboard. Records are removed when a name is released.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ============================== FEATURES ============================== -->
<section class="section" id="features">
    <div class="container">
        <div class="row g-4 mb-4 mb-lg-5">
            <div class="col-lg-7">
                <span class="eyebrow"><i data-lucide="sparkles" aria-hidden="true"></i> Features</span>
                <h2 class="section-title mt-2">Small platform, serious engineering.</h2>
                <p class="section-lead mb-0">
                    FreeSub is built like a product, not a weekend script: explicit security controls, a schema that scales to many root domains and a UI that respects your time.
                </p>
            </div>
        </div>

        <div class="row g-3 g-lg-4">
            <?php foreach ($features as $feature): ?>
                <div class="col-md-6 col-lg-4">
                    <article class="card-surface hoverable feature-card reveal">
                        <span class="feature-icon <?= e($feature['tone']) ?>"><i data-lucide="<?= e($feature['icon']) ?>" aria-hidden="true"></i></span>
                        <h3 class="feature-title"><?= e($feature['title']) ?></h3>
                        <p class="feature-text"><?= e($feature['text']) ?></p>
                    </article>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ============================== CTA ============================== -->
<section class="section-tight">
    <div class="container">
        <div class="cta-panel">
            <div class="row align-items-center g-4">
                <div class="col-lg-8">
                    <span class="eyebrow" style="color:#c7d2fe"><i data-lucide="rocket" aria-hidden="true"></i> Ready when you are</span>
                    <h2 class="mt-2 mb-2">Claim your name before someone else does.</h2>
                    <p class="mb-0">Free accounts, no card, and a dashboard that tells you exactly where your request stands.</p>
                </div>
                <div class="col-lg-4 text-lg-end">
                    <div class="d-flex flex-column flex-sm-row flex-lg-column gap-2 justify-content-lg-end">
                        <a class="btn btn-light-surface btn-lg" href="<?= e(app_url('/register.php')) ?>">
                            Create free account <i data-lucide="arrow-right" aria-hidden="true"></i>
                        </a>
                        <a class="btn btn-outline-light btn-lg" href="<?= e(app_url('/login.php')) ?>">I already have one</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ============================== FAQ ============================== -->
<section class="section" id="faq">
    <div class="container">
        <div class="row justify-content-center text-center mb-4 mb-lg-5">
            <div class="col-lg-8">
                <span class="eyebrow"><i data-lucide="help-circle" aria-hidden="true"></i> FAQ</span>
                <h2 class="section-title mt-2">Questions, answered plainly.</h2>
                <p class="section-lead mx-auto mb-0">Everything about limits, timelines and what we do — and do not — provide.</p>
            </div>
        </div>

        <div class="row justify-content-center">
            <div class="col-lg-9">
                <div class="faq-list">
                    <?php foreach ($faqs as $faq): ?>
                        <details class="faq-item">
                            <summary>
                                <span><?= e($faq['q']) ?></span>
                                <span class="faq-icon" aria-hidden="true"><i data-lucide="plus"></i></span>
                            </summary>
                            <div class="faq-answer"><?= $faq['a'] ?></div>
                        </details>
                    <?php endforeach; ?>
                </div>

                <div class="text-center mt-4">
                    <p class="text-muted-2 mb-2">Still stuck?</p>
                    <a class="btn btn-outline-secondary" href="mailto:<?= e((string) config('contact.support', 'support@kharcha.info')) ?>">
                        <i data-lucide="mail" aria-hidden="true"></i> Email support
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>
