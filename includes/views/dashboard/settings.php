<?php
/**
 * Account & settings (Task 05 §13).
 *
 * There is deliberately no profile edit form: the name and e-mail here are a
 * verified copy of the account holder's Clerk identity, and FreeSub holds no
 * password for them. The page says so, plainly, and points at the place where
 * those changes actually take effect.
 *
 * @var array<string, mixed> $authUser
 * @var array{label:string, tone:string} $accountState
 * @var array{used:int, limit:int, remaining:int, primary:array<string,mixed>|null} $quota
 * @var list<array{domain_id:int, root_domain:string, limit:int, used:int, remaining:int}> $byDomain
 * @var int $subdomainCount
 * @var int $reservationCount
 * @var array<string, string|null> $metadata
 */
$clerkConfigured = ClerkConfig::isConfigured();
?>
<div class="row g-3 g-lg-4">
    <div class="col-lg-7">
        <!-- ========================== Profile =========================== -->
        <section class="panel" aria-labelledby="profile-heading">
            <div class="panel-body">
                <div class="section-head">
                    <h2 id="profile-heading">Profile</h2>
                    <?php partial('partials/status-badge', ['status' => (string) ($authUser['status'] ?? 'active'), 'kind' => 'account']); ?>
                </div>

                <div class="d-flex align-items-center gap-3 mb-3">
                    <span class="dash-avatar" style="width:52px;height:52px;flex-basis:52px;font-size:1.05rem" aria-hidden="true">
                        <?= e(initials((string) $authUser['name'])) ?>
                    </span>
                    <div style="min-width:0">
                        <div class="fw-bold" style="font-size:1.05rem"><?= e((string) $authUser['name']) ?></div>
                        <div class="text-muted-2" style="overflow-wrap:anywhere"><?= e((string) $authUser['email']) ?></div>
                    </div>
                </div>

                <dl class="detail-list">
                    <dt>Account status</dt>
                    <dd><?php partial('partials/status-badge', ['status' => (string) ($authUser['status'] ?? 'active'), 'kind' => 'account']); ?></dd>

                    <?php foreach ($metadata as $label => $value): ?>
                        <?php if ($value !== null && $value !== '—'): ?>
                            <dt><?= e($label) ?></dt>
                            <dd><?= e($value) ?></dd>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <dt>Sign-in method</dt>
                    <dd>Clerk-managed (e-mail, Google, MFA)</dd>
                </dl>

                <div class="alert alert-info mt-3 mb-0" role="note">
                    <i data-lucide="shield-check" aria-hidden="true"></i>
                    <div>
                        <strong>Your name, e-mail and password are managed by Clerk.</strong>
                        <?php if ($clerkConfigured): ?>
                            Change them from the Clerk account menu on the sign-in page — FreeSub then picks
                            up the verified values the next time you sign in. We do not store a password for
                            you, and there is nothing here that could reset one.
                        <?php else: ?>
                            This installation has no Clerk instance configured, so sign-in is unavailable
                            until an administrator sets it up. FreeSub still stores no password.
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>

        <!-- =========================== Quota ============================ -->
        <section class="panel mt-3" aria-labelledby="quota-heading">
            <div class="panel-body">
                <div class="section-head">
                    <h2 id="quota-heading">Quota</h2>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(app_url('/dashboard/search.php')) ?>">Request a name</a>
                </div>

                <div class="quota-meter mb-3">
                    <div class="quota-meter-top">
                        <span class="quota-figure"><?= (int) $quota['used'] ?> <small>/ <?= (int) $quota['limit'] ?> used</small></span>
                        <span class="quota-remaining"><?= (int) $quota['remaining'] ?> left</span>
                    </div>
                    <?php
                    $percent = $quota['limit'] > 0 ? (int) floor(($quota['used'] / $quota['limit']) * 100) : 0;
                    $percent = max(0, min(100, $percent));
                    ?>
                    <div class="progress-thin" role="progressbar" aria-valuemin="0"
                         aria-valuemax="<?= (int) $quota['limit'] ?>" aria-valuenow="<?= (int) $quota['used'] ?>"
                         aria-label="Subdomain quota used">
                        <span style="width: <?= $percent ?>%"></span>
                    </div>
                </div>

                <?php if ($byDomain !== []): ?>
                    <dl class="detail-list" style="grid-template-columns:minmax(0,1fr) auto">
                        <?php foreach ($byDomain as $row): ?>
                            <dt class="mono">.<?= e($row['root_domain']) ?></dt>
                            <dd class="text-end"><?= (int) $row['used'] ?> / <?= (int) $row['limit'] ?></dd>
                        <?php endforeach; ?>
                    </dl>
                <?php endif; ?>

                <p class="stat-hint mb-0 mt-2">
                    <?= (int) $subdomainCount ?> subdomain<?= $subdomainCount === 1 ? '' : 's' ?> on file ·
                    <?= (int) $reservationCount ?> reservation<?= $reservationCount === 1 ? '' : 's' ?> in total.
                    Need more slots? Contact support — quota changes are audited and made by staff.
                </p>
            </div>
        </section>
    </div>

    <div class="col-lg-5">
        <div class="d-grid gap-3">
            <!-- ========================= Security ========================= -->
            <section class="panel" aria-labelledby="security-heading">
                <div class="panel-body">
                    <div class="section-head">
                        <h2 id="security-heading">Security</h2>
                    </div>

                    <dl class="detail-list">
                        <dt>Sign-in</dt>
                        <dd>Clerk session, verified server-side on every request</dd>

                        <dt>Password</dt>
                        <dd>Not stored by FreeSub</dd>

                        <dt>Two-factor</dt>
                        <dd>Managed by Clerk on your Clerk account</dd>

                        <dt>Permissions</dt>
                        <dd>
                            <?= (($authUser['role'] ?? 'user') === 'admin') ? 'Administrator' : 'Standard account' ?>
                        </dd>
                    </dl>

                    <p class="stat-hint mb-0 mt-3">
                        Session and permission details are resolved from the database on each request.
                        Nothing on this page can change them.
                    </p>
                </div>
            </section>

            <!-- ========================= Sign out ========================= -->
            <section class="panel" aria-labelledby="session-heading">
                <div class="panel-body">
                    <div class="section-head">
                        <h2 id="session-heading">End your session</h2>
                    </div>

                    <p style="font-size:.88rem;color:var(--fs-ink-soft)">
                        Signing out destroys this browser's FreeSub session immediately and clears the Clerk
                        session in your browser. You will need to sign in again to reach the dashboard.
                    </p>

                    <form method="post" action="<?= e(app_url('/logout.php')) ?>" data-busy-form>
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-outline-secondary" data-busy-button>
                            <i data-lucide="log-out" aria-hidden="true"></i> Sign out
                        </button>
                    </form>
                </div>
            </section>

            <section class="panel" aria-labelledby="help-heading">
                <div class="panel-body">
                    <div class="section-head">
                        <h2 id="help-heading">Need help?</h2>
                    </div>
                    <p class="stat-hint mb-2">
                        Subdomain trouble, a provisioning failure, or a quota increase.
                    </p>
                    <div class="d-flex flex-wrap gap-2">
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(app_url('/abuse.php')) ?>">
                            <i data-lucide="flag" aria-hidden="true"></i> Report abuse
                        </a>
                        <a class="btn btn-sm btn-ghost" href="<?= e(app_url('/extensions.php')) ?>">
                            <i data-lucide="layers" aria-hidden="true"></i> Extensions
                        </a>
                    </div>
                </div>
            </section>
        </div>
    </div>
</div>
