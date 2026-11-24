<?php
/**
 * @var array<string, string> $errors
 * @var array<string, string> $values
 * @var array<string, string> $categories
 */
?>
<section class="page-header">
    <div class="container">
        <div class="row">
            <div class="col-lg-8">
                <nav class="breadcrumb-mini" aria-label="Breadcrumb">
                    <a href="<?= e(app_url('/')) ?>">Home</a>
                    <i data-lucide="chevron-right" aria-hidden="true" style="width:12px;height:12px"></i>
                    <span>Abuse</span>
                </nav>
                <h1>Report abuse</h1>
                <p>Tell us about a subdomain that is being used for phishing, malware, spam or other illegal activity.</p>
            </div>
        </div>
    </div>
</section>

<section class="section-tight">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-7">
                <?php partial('partials/flashes'); ?>

                <div class="form-card">
                    <form method="post" action="<?= e(app_url('/abuse.php')) ?>" novalidate>
                        <?= csrf_field() ?>

                        <div class="mb-3">
                            <label class="form-label" for="fqdn">Hostname</label>
                            <input type="text" class="form-control<?= isset($errors['fqdn']) ? ' is-invalid' : '' ?>"
                                   id="fqdn" name="fqdn" value="<?= e($values['fqdn'] ?? '') ?>"
                                   placeholder="something.kharcha.info" required maxlength="190" autocomplete="off">
                            <?php if (isset($errors['fqdn'])): ?>
                                <div class="invalid-feedback"><?= e($errors['fqdn']) ?></div>
                            <?php else: ?>
                                <div class="form-text">Include the full hostname, without http:// or a trailing slash.</div>
                            <?php endif; ?>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="category">Category</label>
                            <select class="form-select<?= isset($errors['category']) ? ' is-invalid' : '' ?>" id="category" name="category" required>
                                <?php foreach ($categories as $key => $label): ?>
                                    <option value="<?= e($key) ?>" <?= ($values['category'] ?? '') === $key ? 'selected' : '' ?>>
                                        <?= e($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors['category'])): ?>
                                <div class="invalid-feedback"><?= e($errors['category']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="reporter_name">Your name <span class="fw-normal text-muted-2">(optional)</span></label>
                                <input type="text" class="form-control" id="reporter_name" name="reporter_name"
                                       value="<?= e($values['reporter_name'] ?? '') ?>" maxlength="100" autocomplete="name">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="reporter_email">Your email</label>
                                <input type="email" class="form-control<?= isset($errors['reporter_email']) ? ' is-invalid' : '' ?>"
                                       id="reporter_email" name="reporter_email" value="<?= e($values['reporter_email'] ?? '') ?>"
                                       required maxlength="190" autocomplete="email">
                                <?php if (isset($errors['reporter_email'])): ?>
                                    <div class="invalid-feedback"><?= e($errors['reporter_email']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="mt-3 mb-3">
                            <label class="form-label" for="message">What happened?</label>
                            <textarea class="form-control<?= isset($errors['message']) ? ' is-invalid' : '' ?>"
                                      id="message" name="message" rows="6" required minlength="20" maxlength="5000"
                                      placeholder="Describe the content and, if possible, include the URLs involved."><?= e($values['message'] ?? '') ?></textarea>
                            <?php if (isset($errors['message'])): ?>
                                <div class="invalid-feedback"><?= e($errors['message']) ?></div>
                            <?php endif; ?>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i data-lucide="shield-alert" aria-hidden="true"></i> Submit report
                        </button>
                    </form>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card-surface p-4">
                    <h2 class="h6">What happens with your report</h2>
                    <ul class="mt-3 ps-3" style="font-size:.92rem;color:var(--fs-muted)">
                        <li class="mb-2">The report is stored with a timestamp, your IP address and the reported hostname.</li>
                        <li class="mb-2">A human reviews it — we never suspend a hostname based on an automated signal alone.</li>
                        <li class="mb-2">Actionable reports lead to a suspension or release of the name.</li>
                        <li class="mb-0">Urgent legal requests should also be sent to
                            <a href="mailto:<?= e((string) config('contact.abuse', 'abuse@kharcha.info')) ?>"><?= e((string) config('contact.abuse', 'abuse@kharcha.info')) ?></a>.
                        </li>
                    </ul>
                </div>

                <div class="card-surface p-4 mt-3">
                    <h2 class="h6">Good to know</h2>
                    <p class="feature-text mt-2">
                        FreeSub only provides the hostname. We do not host the websites behind them, so we cannot
                        take down content on third-party servers — but we can disable the hostname that points to it.
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>
