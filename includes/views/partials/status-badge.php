<?php
/**
 * Canonical status badge.
 *
 * Renders a label and a tone; the label is always text, so the badge never
 * depends on colour alone (Task 05 §19). The wording comes from
 * DashboardStatus, not from the caller, so the list, the detail page and the
 * admin queue cannot drift apart.
 *
 * @var string $status   Canonical backend state
 * @var string $kind     'subdomain' | 'reservation' | 'job' | 'account'
 * @var string|null $class Extra classes for layout only
 */
$kind = $kind ?? 'subdomain';
$status = (string) ($status ?? '');

$meta = match ($kind) {
    'reservation' => DashboardStatus::reservation($status),
    'job' => DashboardStatus::job($status),
    'account' => DashboardStatus::account($status) + ['hint' => ''],
    default => DashboardStatus::subdomain($status),
};

$label = (string) ($meta['label'] ?? ucfirst($status));
$tone = (string) ($meta['tone'] ?? 'is-neutral');
$hint = (string) ($meta['hint'] ?? '');
?>
<span class="badge-status <?= e($tone) ?> <?= e($class ?? '') ?>" <?= $hint !== '' ? 'title="' . e($hint) . '"' : '' ?>><?= e($label) ?><?php if ($hint !== ''): ?><span class="visually-hidden"> — <?= e($hint) ?></span><?php endif; ?></span>
