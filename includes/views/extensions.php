<?php
/**
 * @var list<array<string, mixed>> $domains
 * @var list<array{extension: string, domains: int, root_domain: string}> $extensions
 */
$count = count($domains);
?>
<section class="hero" style="padding-bottom:2.5rem">
    <div class="container">
        <div class="row justify-content-center text-center">
            <div class="col-lg-8">
                <span class="hero-badge mb-3">
                    <span class="dot" aria-hidden="true"></span>
                    <?= e((string) $count) ?> extension<?= $count === 1 ? '' : 's' ?> open for registration
                </span>
                <h1 style="font-size:clamp(2rem, 1.2rem + 2.6vw, 3rem)">Choose your ending.</h1>
                <p class="hero-lead mx-auto">
                    Every extension is a root domain operated by FreeSub. They all support the same dashboard,
                    statuses and support channel — pick whichever reads best in your URL.
                </p>
                <div class="d-flex flex-wrap gap-2 justify-content-center mt-4">
                    <a class="btn btn-primary btn-lg" href="<?= e(app_url('/index.php#search')) ?>">
                        <i data-lucide="search" aria-hidden="true"></i> Search a name
                    </a>
                    <a class="btn btn-light-surface btn-lg" href="<?= e(app_url('/register.php')) ?>">Create free account</a>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="section-tight">
    <div class="container">
        <?php partial('partials/flashes'); ?>

        <div class="row g-3 g-lg-4">
            <?php if ($domains === []): ?>
                <div class="col-12">
                    <div class="empty-state">
                        <div class="empty-icon"><i data-lucide="globe-lock" aria-hidden="true"></i></div>
                        <h2>No extensions are open right now</h2>
                        <p>Registration is paused while we prepare the next root domain. Accounts created now keep their place in line.</p>
                        <a class="btn btn-primary" href="<?= e(app_url('/register.php')) ?>">Create an account</a>
                    </div>
                </div>
            <?php endif; ?>

            <?php foreach ($domains as $domain): ?>
                <div class="col-md-6 col-lg-4">
                    <article class="extension-card">
                        <div class="d-flex align-items-start justify-content-between gap-2">
                            <div class="extension-suffix">.<span><?= e((string) $domain['extension']) ?></span></div>
                            <?php if (($domain['registration_status'] ?? 'ready') === 'maintenance'): ?>
                                <span class="tag tag-accent">Maintenance</span>
                            <?php else: ?>
                                <span class="tag tag-brand">Open</span>
                            <?php endif; ?>
                        </div>

                        <p class="extension-desc"><?= e((string) ($domain['description'] ?? 'A short, memorable ending for your next project.')) ?></p>

                        <dl class="row g-0 mb-3" style="font-size:.86rem">
                            <dt class="col-5 text-muted-2 fw-normal">Root domain</dt>
                            <dd class="col-7 mb-1"><span class="mono"><?= e((string) $domain['root_domain']) ?></span></dd>
                            <dt class="col-5 text-muted-2 fw-normal">Quota</dt>
                            <dd class="col-7 mb-1"><?= e((string) $domain['subdomain_quota']) ?> per account</dd>
                            <dt class="col-5 text-muted-2 fw-normal">Nameservers</dt>
                            <dd class="col-7 mb-0"><?= $domain['nameservers'] ? '<span class="mono">' . e(str_limit((string) $domain['nameservers'], 40)) . '</span>' : 'Managed by FreeSub' ?></dd>
                        </dl>

                        <div class="extension-footer">
                            <span class="extension-count">Updated <?= e(format_date((string) $domain['updated_at'])) ?></span>
                            <a class="btn btn-primary btn-sm" href="<?= e(app_url('/index.php#search')) ?>">
                                Claim <i data-lucide="arrow-right" aria-hidden="true"></i>
                            </a>
                        </div>
                    </article>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="card-surface p-3 p-md-4 mt-4">
            <div class="d-flex gap-3 align-items-start">
                <span class="feature-icon cool mb-0" style="width:40px;height:40px;border-radius:11px"><i data-lucide="plus-circle" aria-hidden="true"></i></span>
                <div>
                    <div class="feature-title">Want another extension?</div>
                    <p class="feature-text mb-0">
                        Root domains are added administratively. Because extensions are database rows rather than hard-coded
                        values, a new domain appears here — and in the search box — as soon as it is inserted.
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>
