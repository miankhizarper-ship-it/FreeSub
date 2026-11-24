<?php
/**
 * Search + reserve (Task 05 §9).
 *
 * The availability widget is the existing vanilla-JS one (`app.js` →
 * bindAvailabilitySearch), pointed at the existing `/api/check.php`. It is
 * advisory only: the submit button is never gated on its answer, and the server
 * re-checks everything. If JavaScript is off, the form still posts and the
 * server answers honestly.
 *
 * @var list<array<string, mixed>> $domains
 * @var array{used:int, limit:int, remaining:int, primary:array<string,mixed>|null} $quota
 * @var array<int, array{domain_id:int, root_domain:string, limit:int, used:int, remaining:int}> $byDomain
 * @var array<string, mixed>|null $selectedDomain
 * @var string $prefillLabel
 * @var array<string, string> $errors
 */
$labelError = error_for($errors, 'label');
$quotaError = error_for($errors, 'quota');
$hasDomains = $domains !== [];
$selectedId = (int) ($selectedDomain['id'] ?? 0);
$selectedRemaining = $byDomain[$selectedId]['remaining'] ?? $quota['remaining'];
?>
<div class="row g-3 g-lg-4">
    <div class="col-lg-7">
        <section class="panel" aria-labelledby="reserve-heading">
            <div class="panel-body">
                <div class="section-head">
                    <h2 id="reserve-heading">Request a subdomain</h2>
                    <?php partial('partials/status-badge', ['status' => 'pending', 'kind' => 'reservation']); ?>
                </div>

                <?php if (!$hasDomains): ?>
                    <div class="empty-state">
                        <div class="empty-icon"><i data-lucide="globe-lock" aria-hidden="true"></i></div>
                        <h3>No extensions open</h3>
                        <p>
                            Registration is paused while we prepare the next root domain. Nothing is lost —
                            your account and existing names stay exactly as they are.
                        </p>
                    </div>
                <?php else: ?>
                    <form method="post" action="<?= e(app_url('/dashboard/search.php')) ?>"
                          class="reserve-form" novalidate data-reserve-form data-busy-form>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reserve">

                        <div class="mb-3">
                            <label class="form-label" for="reserve-label">Subdomain label</label>
                            <div class="input-group">
                                <input type="text"
                                       class="form-control <?= $labelError !== null ? 'is-invalid' : '' ?>"
                                       id="reserve-label" name="label"
                                       value="<?= e($prefillLabel) ?>"
                                       placeholder="yourname"
                                       maxlength="63" required autocomplete="off"
                                       spellcheck="false" autocapitalize="none"
                                       aria-describedby="reserve-help"
                                       data-availability-input>
                                <select class="form-select <?= $labelError !== null ? 'is-invalid' : '' ?>"
                                        name="domain_id" id="reserve-domain"
                                        style="max-width: 46%"
                                        aria-label="Root domain" data-availability-domain>
                                    <?php foreach ($domains as $domain): ?>
                                        <option value="<?= (int) $domain['id'] ?>"
                                            <?= (int) $domain['id'] === $selectedId ? 'selected' : '' ?>>
                                            .<?= e((string) $domain['root_domain']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-text" id="reserve-help">
                                Lowercase letters, numbers and hyphens. 3–32 characters, and no leading or
                                trailing hyphen.
                            </div>
                            <?php if ($labelError !== null): ?>
                                <div class="invalid-feedback d-block" role="alert"><?= e($labelError) ?></div>
                            <?php endif; ?>

                            <!-- Availability verdict, announced politely. Populated by app.js. -->
                            <div class="mt-2" data-availability-results aria-live="polite" aria-atomic="true"></div>
                            <p class="form-text mb-0" data-availability-hint>
                                Type a name to check it. The check is a courtesy — the final decision is made
                                when you submit.
                            </p>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="reserve-purpose">
                                What is it for? <span class="text-muted-2 fw-normal">(optional)</span>
                            </label>
                            <input type="text" class="form-control" id="reserve-purpose" name="purpose"
                                   maxlength="255" value="<?= e(old('purpose')) ?>"
                                   placeholder="Portfolio, API sandbox, side project…">
                            <div class="form-text">Helps whoever reviews the request. Never shown publicly.</div>
                        </div>

                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <button type="submit" class="btn btn-primary" data-busy-button>
                                <i data-lucide="send" aria-hidden="true"></i> Send reservation
                            </button>
                            <a class="btn btn-ghost" href="<?= e(app_url('/dashboard/subdomains.php')) ?>">My subdomains</a>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <div class="col-lg-5">
        <div class="d-grid gap-3">
            <section class="panel" aria-labelledby="quota-heading">
                <div class="panel-body">
                    <div class="section-head">
                        <h2 id="quota-heading">Your quota</h2>
                    </div>

                    <div class="quota-meter">
                        <div class="quota-meter-top">
                            <span class="quota-figure">
                                <?= (int) $quota['used'] ?> <small>/ <?= (int) $quota['limit'] ?> used</small>
                            </span>
                            <span class="quota-remaining"><?= (int) $quota['remaining'] ?> left</span>
                        </div>
                        <?php
                        $percent = $quota['limit'] > 0 ? (int) floor(($quota['used'] / $quota['limit']) * 100) : 0;
                        $percent = max(0, min(100, $percent));
                        ?>
                        <div class="progress-thin<?= $quota['used'] > $quota['limit'] ? ' is-over' : ($quota['remaining'] === 0 ? ' is-full' : '') ?>"
                             role="progressbar" aria-valuemin="0" aria-valuemax="<?= (int) $quota['limit'] ?>"
                             aria-valuenow="<?= (int) $quota['used'] ?>"
                             aria-label="Subdomain quota used">
                            <span style="width: <?= $percent ?>%"></span>
                        </div>
                    </div>

                    <?php if ($quotaError !== null): ?>
                        <div class="alert alert-warning mt-3 mb-0" role="alert">
                            <i data-lucide="gauge" aria-hidden="true"></i>
                            <div><?= e($quotaError) ?></div>
                        </div>
                    <?php elseif ($selectedRemaining === 0): ?>
                        <div class="alert alert-warning mt-3 mb-0" role="status">
                            <i data-lucide="gauge" aria-hidden="true"></i>
                            <div>
                                <strong>No slots left on this extension.</strong>
                                Cancel a pending request or release a name you no longer need.
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (count($byDomain) > 1): ?>
                        <hr class="divider-soft my-3">
                        <dl class="detail-list" style="grid-template-columns:minmax(0,1fr) auto">
                            <?php foreach ($byDomain as $row): ?>
                                <dt class="mono">.<?= e($row['root_domain']) ?></dt>
                                <dd class="text-end"><?= (int) $row['used'] ?> / <?= (int) $row['limit'] ?></dd>
                            <?php endforeach; ?>
                        </dl>
                    <?php endif; ?>
                </div>
            </section>

            <section class="panel" aria-labelledby="next-heading">
                <div class="panel-body">
                    <div class="section-head">
                        <h2 id="next-heading">What happens next</h2>
                    </div>
                    <ol class="mb-0 ps-3" style="font-size:.87rem;color:var(--fs-ink-soft)">
                        <li class="mb-1">A person reviews the request.</li>
                        <li class="mb-1">Approved names enter the provisioning queue.</li>
                        <li class="mb-1">The DNS record is created, then read back to verify it.</li>
                        <li>Only then does the name become <strong>Active</strong>.</li>
                    </ol>
                    <p class="stat-hint mt-3 mb-0">
                        Pending requests hold their label so nobody else can take it, and expire
                        automatically so a label is never held indefinitely.
                    </p>
                </div>
            </section>
        </div>
    </div>
</div>
